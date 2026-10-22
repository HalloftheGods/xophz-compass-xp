<?php

/**
 * The family's save on the wire for Do It For The XP: a batch of records from a device, the
 * records after a number, and a hero's turn (play and rest).
 *
 * The routes (Xophz_Compass_Xp_Family_Rest) work out the caller and the family, then hand the
 * request here. Every entry point asks the Pro gate first (403 xp_family_pro_required for a
 * family that is not entitled), then works out whose device is calling: a hero key is always its
 * own phone's device, whatever the request says, and only a parent names its device. The
 * single-writer rule is kept by the store, under the family's lock (upsert_records and
 * take_turn); nothing here reads the holder of a turn first, so a takeover cannot slip in
 * between a check and a write.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Records {

  /** What the busy line calls a parent device that sent no label of its own. */
  const PARENT_LABEL = 'the family device';

  /** What a record refused by the record rules is told, by the code it was refused with. */
  private const REFUSALS = [
    Xophz_Compass_Xp_Family_Record_Rules::STORE_UNKNOWN => 'That store is not part of the family save.',
    Xophz_Compass_Xp_Family_Record_Rules::INVALID       => 'That record is not valid.',
    Xophz_Compass_Xp_Family_Record_Rules::SCOPE         => 'This caller may not write that record.',
  ];

  /**
   * A batch of records from a device.
   *
   * Each record is checked on its own (Record_Rules::check): a refused one is listed in refused
   * and the rest of the batch carries on. The records that pass go to the store in one call,
   * which numbers them, refuses a hero's record from a device that does not hold the hero's turn
   * (xp_family_turn) and a hero key's write over another hero's row (xp_family_scope), and
   * refreshes the last seen of the heroes written. In refused the record rules' refusals come
   * first, then the store's, each in the order the records were sent.
   *
   * The whole batch is refused when the family is not entitled (403 xp_family_pro_required),
   * when a parent names no device or a malformed one (400 xp_family_bad_request), when the
   * records are not a list, are more than 50 or have bodies that add up to more than 1 MB
   * (400 xp_family_record_invalid; the app counts the same bytes), and when the family's lock
   * is busy (503 xp_family_busy) or the family is not there (404 xp_family_no_family).
   *
   * @param array  $family    The caller's family row.
   * @param array  $caller    The caller: parent or hero.
   * @param string $device_id The device a parent writes as; a hero key writes as its phone.
   * @param mixed  $records   The records param of the request: wire records.
   * @param int    $now       Unix time of the write.
   * @return array|WP_Error [ 'accepted' => [ { store, key, seq } ], 'refused' => [ { store, key, code, message } ] ]
   */
  public static function put( Xophz_Compass_Xp_Family_Store $store, array $family, array $caller, string $device_id, $records, int $now ) {
    $family_id = (string) $family['family_id'];
    if ( ! Xophz_Compass_Xp_Family_Pro::entitled( $family_id, $now ) ) {
      return self::pro_required();
    }

    $who = self::who( $caller, $device_id );
    if ( is_wp_error( $who ) ) {
      return $who;
    }

    $oversize = self::batch_error( $records );
    if ( null !== $oversize ) {
      return $oversize;
    }

    $ctx     = [ 'writer' => $who['writer'], 'key_hero_id' => $who['hero_id'] ];
    $clean   = [];
    $refused = [];
    foreach ( $records as $raw ) {
      $checked = Xophz_Compass_Xp_Family_Record_Rules::check( $raw, $ctx );
      if ( is_array( $checked ) ) {
        $clean[] = $checked;
        continue;
      }

      $refused[] = [
        'store'   => is_array( $raw ) ? self::text( $raw['store'] ?? null ) : '',
        'key'     => is_array( $raw ) ? self::text( $raw['key'] ?? null ) : '',
        'code'    => $checked,
        'message' => self::REFUSALS[ $checked ] ?? 'That record was refused.',
      ];
    }

    $result = $store->upsert_records( $family_id, $who['device_id'], $clean, $now, $who['hero_id'] );
    if ( is_wp_error( $result ) ) {
      return $result;
    }

    return [ 'accepted' => $result['accepted'], 'refused' => array_merge( $refused, $result['refused'] ) ];
  }

  /**
   * The family's records after the number since, oldest first, at most limit, only those the
   * caller may read: a parent reads all; a hero key reads the family's records, every chat row
   * and its own hero's records (never a sibling's).
   *
   * A page also ends before the row that would take the bodies past 1 MB (always one row at
   * least), so a few big records cannot swell a page to the 100 MB that 200 rows of 512 KB would
   * make. The caller's cursor is the last number returned, so paging needs nothing new. The store
   * reads the sizes first and fetches only the bodies of the page (see Store::records_after), so
   * the cap holds in memory too, not only in the answer.
   *
   * Reading is how a holder shows it is still there: the heroes whose turn the caller's device
   * holds get their last seen refreshed (one query finds them). A hero key reads as its phone; a
   * parent names its device with $device_id, and without one nothing is refreshed.
   *
   * @param array       $family    The caller's family row.
   * @param array       $caller    The caller: parent or hero.
   * @param string      $device_id The parent's device, or '' for none.
   * @param int|null    $now       Unix time; the clock when null.
   * @return array|WP_Error The rows as the wire carries them (record_wire), or 403
   *                        xp_family_pro_required, or 400 xp_family_bad_request for a malformed device.
   */
  public static function read( Xophz_Compass_Xp_Family_Store $store, array $family, array $caller, int $since, int $limit, string $device_id = '', ?int $now = null ) {
    $now       = $now ?? time();
    $family_id = (string) $family['family_id'];
    if ( ! Xophz_Compass_Xp_Family_Pro::entitled( $family_id, $now ) ) {
      return self::pro_required();
    }

    $who = self::who( $caller, $device_id, true );
    if ( is_wp_error( $who ) ) {
      return $who;
    }

    $ctx  = [ 'writer' => $who['writer'], 'key_hero_id' => $who['hero_id'] ];
    $rows = $store->records_after(
      $family_id,
      $since,
      $limit,
      'hero' === $who['writer'] ? $who['hero_id'] : null,
      Xophz_Compass_Xp_Family_Record_Rules::MAX_BATCH_BYTES
    );
    $out  = [];
    foreach ( $rows as $row ) {
      if ( Xophz_Compass_Xp_Family_Record_Rules::may_read( $row, $ctx ) ) {
        $out[] = self::record_wire( $row );
      }
    }

    if ( '' !== $who['device_id'] ) {
      self::touch_held( $store, $family_id, $who['device_id'], $now );
    }

    return $out;
  }

  /**
   * Take a hero's turn for the caller's device (see Family_Store::take_turn).
   *
   * @param string $hero_id The hero, from the path of the route.
   * @param string $label   What the busy line names, for a parent device: cleaned, up to 60 characters,
   *                        "the family device" when empty. A hero key is named by the label its phone
   *                        was linked with, and this one is ignored: a kid phone cannot pass itself
   *                        off as the family tablet.
   * @param bool   $force   Take the turn from the device that holds it.
   * @return array|WP_Error [ 'answer' => 'granted' ] or [ 'answer' => 'busy', 'device' => [ 'label',
   *                        'last_seen_at' ] ]; 404 xp_family_no_hero for a hero that is not there; 403
   *                        xp_family_scope for a hero key asking for another hero; 503 xp_family_busy.
   */
  public static function play( Xophz_Compass_Xp_Family_Store $store, array $family, array $caller, string $hero_id, string $device_id, string $label, bool $force, int $now ) {
    $family_id = (string) $family['family_id'];
    if ( ! Xophz_Compass_Xp_Family_Pro::entitled( $family_id, $now ) ) {
      return self::pro_required();
    }

    $who = self::who( $caller, $device_id );
    if ( is_wp_error( $who ) ) {
      return $who;
    }
    $refused = self::hero_error( $who, $hero_id );
    if ( null !== $refused ) {
      return $refused;
    }

    $named  = 'hero' === $who['writer']
      ? Xophz_Compass_Xp_Family_Rules::device_label( $caller['device']['label'] ?? '' )
      : Xophz_Compass_Xp_Family_Rules::device_label( $label, self::PARENT_LABEL );
    $answer = $store->take_turn( $family_id, $hero_id, $who['device_id'], $named, $force, $now );
    if ( is_wp_error( $answer ) ) {
      return $answer;
    }
    if ( 'missing' === ( $answer['answer'] ?? null ) ) {
      return self::error( 'xp_family_no_hero', 'That hero is not in this family.', 404 );
    }

    return $answer;
  }

  /**
   * Give a hero's turn back. Only the device that holds it clears it: a late rest from a device
   * that lost the turn changes nothing, and still gets the same answer.
   *
   * @return array|WP_Error [ 'answer' => 'rested' ], or why not (as play, less the turn itself).
   */
  public static function rest( Xophz_Compass_Xp_Family_Store $store, array $family, array $caller, string $hero_id, string $device_id, ?int $now = null ) {
    $family_id = (string) $family['family_id'];
    if ( ! Xophz_Compass_Xp_Family_Pro::entitled( $family_id, $now ?? time() ) ) {
      return self::pro_required();
    }

    $who = self::who( $caller, $device_id );
    if ( is_wp_error( $who ) ) {
      return $who;
    }
    $refused = self::hero_error( $who, $hero_id );
    if ( null !== $refused ) {
      return $refused;
    }

    $store->rest_turn( $family_id, $hero_id, $who['device_id'] );

    return [ 'answer' => 'rested' ];
  }

  /**
   * A stored record as the wire carries it: the body is the JSON text as the app wrote it
   * (empty for a removal), deleted is a boolean, saved_at is ISO 8601 and hero_id is null for a
   * family record.
   */
  public static function record_wire( array $row ): array {
    $hero = $row['hero_id'] ?? null;

    return [
      'seq'       => (int) $row['seq'],
      'store'     => (string) $row['store'],
      'key'       => (string) $row['record_key'],
      'hero_id'   => is_string( $hero ) && '' !== $hero ? $hero : null,
      'device_id' => (string) $row['device_id'],
      'saved_at'  => Xophz_Compass_Xp_Family_Store::iso( $row['saved_at'] ?? null ),
      'deleted'   => 1 === (int) ( $row['deleted'] ?? 0 ),
      'body'      => (string) ( $row['body'] ?? '' ),
    ];
  }

  // --- helpers ---------------------------------------------------------------------------------

  /**
   * Whose device is calling, and as what.
   *
   * @param bool $optional A parent may name no device (the read).
   * @return array|WP_Error [ 'writer' => hero|parent, 'device_id' => string, 'hero_id' => the key's hero or null ];
   *                        400 xp_family_bad_request for a parent whose device id is not one;
   *                        403 xp_family_forbidden for a caller that is neither.
   */
  private static function who( array $caller, string $given, bool $optional = false ) {
    $kind = (string) ( $caller['kind'] ?? '' );

    if ( 'hero' === $kind ) {
      return [
        'writer'    => 'hero',
        'device_id' => (string) ( $caller['device']['device_id'] ?? '' ),
        'hero_id'   => (string) ( $caller['device']['hero_id'] ?? '' ),
      ];
    }
    if ( 'parent' !== $kind ) {
      return self::error( 'xp_family_forbidden', 'This caller may not do that.', 403 );
    }

    if ( ! ( $optional && '' === $given ) && ! Xophz_Compass_Xp_Family_Rules::is_device_id( $given ) ) {
      return self::error( 'xp_family_bad_request', 'A device id (16 letters and digits) is needed.', 400 );
    }

    return [ 'writer' => 'parent', 'device_id' => $given, 'hero_id' => null ];
  }

  /** Why this caller may not name this hero in play or rest, or null: a hero key names only its own. */
  private static function hero_error( array $who, string $hero_id ): ?WP_Error {
    if ( ! Xophz_Compass_Xp_Family_Rules::is_hero_id( $hero_id ) ) {
      return self::error( 'xp_family_bad_request', 'That is not a hero id.', 400 );
    }
    if ( 'hero' === $who['writer'] && $hero_id !== $who['hero_id'] ) {
      return self::error( 'xp_family_scope', 'A hero phone can only take its own hero.', 403 );
    }

    return null;
  }

  /**
   * Why a whole batch is refused, or null: not a list, more than 50 records, or bodies that add
   * up to more than 1 MB. A record whose body is not a string counts nothing here (the record
   * rules refuse it on its own).
   */
  private static function batch_error( $records ): ?WP_Error {
    if ( ! is_array( $records ) || ! array_is_list( $records ) ) {
      return self::error( Xophz_Compass_Xp_Family_Record_Rules::INVALID, 'The body needs a list of records.', 400 );
    }
    if ( count( $records ) > Xophz_Compass_Xp_Family_Record_Rules::MAX_BATCH ) {
      return self::error( Xophz_Compass_Xp_Family_Record_Rules::INVALID, 'At most ' . Xophz_Compass_Xp_Family_Record_Rules::MAX_BATCH . ' records per request.', 400 );
    }

    $bytes = 0;
    foreach ( $records as $raw ) {
      if ( is_array( $raw ) && is_string( $raw['body'] ?? null ) ) {
        $bytes += strlen( $raw['body'] );
      }
    }
    if ( $bytes > Xophz_Compass_Xp_Family_Record_Rules::MAX_BATCH_BYTES ) {
      return self::error( Xophz_Compass_Xp_Family_Record_Rules::INVALID, 'At most 1 MB of records per request.', 400 );
    }

    return null;
  }

  /** The holder is still playing: refresh the last seen of every hero this device holds. */
  private static function touch_held( Xophz_Compass_Xp_Family_Store $store, string $family_id, string $device_id, int $now ): void {
    foreach ( $store->heroes( $family_id ) as $hero ) {
      if ( (string) ( $hero['turn_device_id'] ?? '' ) === $device_id ) {
        $store->touch_turn( $family_id, (string) $hero['hero_id'], $device_id, $now );
      }
    }
  }

  private static function pro_required(): WP_Error {
    return self::error( 'xp_family_pro_required', 'Family sync is part of XP Pro.', 403 );
  }

  /** @param mixed $v A string, or '' for anything else. */
  private static function text( $v ): string {
    return is_string( $v ) ? $v : '';
  }

  private static function error( string $code, string $message, int $status ): WP_Error {
    return new WP_Error( $code, $message, [ 'status' => $status ] );
  }
}
