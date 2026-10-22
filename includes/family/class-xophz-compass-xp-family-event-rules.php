<?php

/**
 * Event rules for the family event stream of Do It For The XP: the kinds of event, what
 * each keeps, the checks on one event, who may read it and the hourly limits.
 *
 * Pure rules. This class calls no WordPress function and touches no database, so the
 * tests load it alone. The store and the routes (which do use WordPress) ask it.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Event_Rules {

  /**
   * Kind => scope, writer and fields.
   *
   * Scope: public (the family's own devices in this base) or family (a parent, and the hero
   * it is about). Writer: home (the family's home device), server (PHP code on the site) or
   * hero (a hero phone, by its hero key, for its own hero only); a kind names one writer or
   * a list of them. Field types: int, bool, strN (a string of up to N characters), enum:a|b|c,
   * look, stats and quests (the two parts of a hero sheet). A kind may carry max_bytes, its
   * own cap on the size of its data, in place of MAX_DATA_BYTES.
   *
   * A quest's name and category are never fields of a public kind, because parents type them
   * and they can be private; only the hero's own phone and the parent see them, in a hero
   * sheet. The same holds for gold, which buys real rewards: it is only in the family kinds
   * hero.sheet and claim.settled, never in a public one.
   *
   * quest.claimed carries synced (bool) when the claim is also saved as a quest log record
   * (family sync): the family device, which reads the record, then skips the event.
   */
  const KINDS = [
    'hero.card'      => [
      'scope'  => 'public',
      'writer' => 'home',
      'fields' => [ 'nickname' => 'str40', 'look' => 'look', 'jobClass' => 'str40', 'level' => 'int' ],
    ],
    'hero.leveled'   => [
      'scope'  => 'public',
      'writer' => 'home',
      'fields' => [ 'level' => 'int', 'jobClass' => 'str40' ],
    ],
    'quest.done'     => [
      'scope'  => 'public',
      'writer' => 'home',
      'fields' => [ 'difficulty' => 'int', 'xp' => 'int', 'fight' => 'bool' ],
    ],
    'quest.claimed'  => [
      'scope'  => 'family',
      'writer' => [ 'home', 'hero' ],
      'fields' => [ 'questId' => 'str64', 'difficulty' => 'int', 'requiresApproval' => 'bool', 'synced' => 'bool' ],
    ],
    'quest.approved' => [
      'scope'  => 'family',
      'writer' => 'home',
      'fields' => [ 'questId' => 'str64', 'note' => 'str140' ],
    ],
    'quest.rejected' => [
      'scope'  => 'family',
      'writer' => 'home',
      'fields' => [ 'questId' => 'str64', 'note' => 'str140' ],
    ],
    'reward.granted' => [
      'scope'  => 'family',
      'writer' => 'server',
      'fields' => [ 'xp' => 'int', 'ap' => 'int', 'reason' => 'str40', 'sourceId' => 'str64' ],
    ],
    'hero.sheet'     => [
      'scope'     => 'family',
      'writer'    => 'home',
      'max_bytes' => 16384,
      'fields'    => [ 'stats' => 'stats', 'quests' => 'quests' ],
    ],
    'claim.settled'  => [
      'scope'  => 'family',
      'writer' => 'home',
      'fields' => [
        'claimId' => 'str64', 'questId' => 'str64', 'status' => 'enum:pending|approved|rejected',
        'note' => 'str140', 'xp' => 'int', 'gp' => 'int', 'ap' => 'int',
      ],
    ],
  ];

  /** The numbers of a hero sheet's stats. */
  const SHEET_STATS = [
    'level' => 'int', 'xp' => 'int', 'nextLevelXp' => 'int', 'gp' => 'int', 'ap' => 'int',
    'hp' => 'int', 'maxHp' => 'int', 'mp' => 'int', 'maxMp' => 'int',
  ];

  /** What a hero sheet keeps of one quest. */
  const SHEET_QUEST = [
    'questId' => 'str64', 'name' => 'str80', 'difficulty' => 'int', 'xp' => 'int', 'gp' => 'int',
    'ap' => 'int', 'needsApproval' => 'bool', 'photo' => 'bool', 'overdue' => 'bool',
  ];

  /** Quests in a hero sheet; a longer list is refused, not cut. */
  const SHEET_QUESTS_MAX = 60;

  /** The parts of a hero's voxel look; each value is a string of up to 32 characters. */
  const LOOK_FIELDS = [
    'skin', 'hair', 'hairColor', 'face', 'eyeColor', 'outfit',
    'outfitColor', 'outfitAccent', 'hat', 'hatColor', 'leftHand', 'rightHand',
  ];

  const LOOK_VALUE_MAX  = 32;
  /** Events in one request. */
  const MAX_BATCH       = 50;
  /** Bytes of an event's data as the device sent it (JSON, unicode unescaped), unless its kind sets max_bytes. */
  const MAX_DATA_BYTES  = 8192;
  const HERO_PER_HOUR   = 120;
  const FAMILY_PER_HOUR = 600;
  /** Seconds an event's time may be ahead of the server. */
  const FUTURE_SLACK    = 86400;
  /** Seconds a device waits after a 429. */
  const RETRY_AFTER     = 600;
  const INT_MAX         = 100000;

  /** The error code of a refused event. */
  const INVALID = 'xp_family_event_invalid';

  /** Who writes: home, server or hero. */
  private const WRITERS = [ 'home', 'server', 'hero' ];

  /**
   * Keep the listed fields of the right type; drop everything else.
   *
   * Ints are clamped to 0..INT_MAX and a JSON string is not an int. A string longer than its
   * type allows is dropped, not cut. An empty result is an empty array: encode it as an object.
   *
   * @param string $kind  The event kind.
   * @param array  $data  The data the device sent.
   * @param array  $kinds The kinds table, KINDS by default.
   * @return array The kept fields, in the order the kind lists them.
   */
  public static function filter_data( string $kind, array $data, array $kinds = self::KINDS ): array {
    $fields = $kinds[ $kind ]['fields'] ?? null;

    return is_array( $fields ) ? self::keep( $fields, $data ) : [];
  }

  /**
   * Check one event from the wire and turn it into the row to store, or say why not.
   *
   * @param array $e     The wire event: id, hero_id, device_id, kind, at, data.
   * @param array $ctx   writer (home, hero or server), hero_ids (the family's live heroes),
   *                     key_hero_id (the hero of a hero key, else null) and now (unix time).
   * @param array $kinds The kinds table, KINDS by default.
   * @return array [ 'ok' => true, 'event' => [ event_id, hero_id, device_id, kind, scope,
   *               occurred_at (unix), data ] ] or [ 'ok' => false, 'id', 'code', 'message' ].
   */
  public static function check_event( array $e, array $ctx, array $kinds = self::KINDS ): array {
    $id = is_string( $e['id'] ?? null ) ? $e['id'] : '';

    if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id ) ) {
      return self::refuse( $id, 'The event id must be a version 4 UUID.' );
    }

    $kind = $e['kind'] ?? null;
    if ( ! is_string( $kind ) || ! isset( $kinds[ $kind ] ) || ! is_array( $kinds[ $kind ] ) ) {
      return self::refuse( $id, 'That kind of event is not known.' );
    }
    $def = $kinds[ $kind ];

    $writer = $ctx['writer'] ?? null;
    if ( ! is_string( $writer ) || ! in_array( $writer, self::WRITERS, true ) || ! in_array( $writer, (array) ( $def['writer'] ?? [] ), true ) ) {
      return self::refuse( $id, 'This caller may not write that kind of event.' );
    }

    $hero_id  = $e['hero_id'] ?? null;
    $hero_ids = is_array( $ctx['hero_ids'] ?? null ) ? $ctx['hero_ids'] : [];
    if ( ! is_string( $hero_id ) || ! in_array( $hero_id, $hero_ids, true ) ) {
      return self::refuse( $id, 'That hero is not in this family.' );
    }

    if ( 'hero' === $writer && ( ! is_string( $ctx['key_hero_id'] ?? null ) || $ctx['key_hero_id'] !== $hero_id ) ) {
      return self::refuse( $id, 'A hero phone can only send events for its own hero.' );
    }

    $device_id = $e['device_id'] ?? null;
    if ( ! is_string( $device_id ) || 1 !== preg_match( '/\A[A-Za-z0-9]{1,16}\z/', $device_id ) ) {
      return self::refuse( $id, 'The device id is not valid.' );
    }

    $now = (int) ( $ctx['now'] ?? time() );
    $at  = is_string( $e['at'] ?? null ) ? self::parse_time( $e['at'] ) : null;
    if ( null === $at ) {
      return self::refuse( $id, 'The time of the event is not an ISO 8601 time.' );
    }
    if ( $at > $now + self::FUTURE_SLACK ) {
      return self::refuse( $id, 'The event is more than a day ahead of the server.' );
    }

    $data = $e['data'] ?? null;
    if ( ! is_array( $data ) || ( [] !== $data && self::is_list( $data ) ) ) {
      return self::refuse( $id, 'The data must be a JSON object.' );
    }
    $max  = (int) ( $def['max_bytes'] ?? self::MAX_DATA_BYTES );
    $json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    if ( false === $json || strlen( $json ) > $max ) {
      return self::refuse( $id, 'The data is larger than ' . $max . ' bytes.' );
    }

    $kept    = self::filter_data( $kind, $data, $kinds );
    $problem = self::unfit( (array) ( $def['fields'] ?? [] ), $data, $kept );
    if ( null !== $problem ) {
      return self::refuse( $id, $problem );
    }

    return [
      'ok'    => true,
      'event' => [
        'event_id'    => $id,
        'hero_id'     => $hero_id,
        'device_id'   => $device_id,
        'kind'        => $kind,
        'scope'       => $def['scope'],
        'occurred_at' => $at,
        'data'        => $kept,
      ],
    ];
  }

  /**
   * Whether a caller may read a stored event.
   *
   * A parent reads all. A hero key reads the public events and the family events of its own
   * hero. Anyone else reads nothing.
   *
   * @param array       $row         A stored event: scope and hero_id.
   * @param string      $caller      parent, hero or none.
   * @param string|null $key_hero_id The hero of the hero key.
   */
  public static function readable_by( array $row, string $caller, ?string $key_hero_id ): bool {
    if ( 'parent' === $caller ) {
      return true;
    }
    if ( 'hero' !== $caller ) {
      return false;
    }

    $scope = $row['scope'] ?? null;
    if ( 'public' === $scope ) {
      return true;
    }

    return 'family' === $scope && null !== $key_hero_id && ( $row['hero_id'] ?? null ) === $key_hero_id;
  }

  /**
   * Whether a batch would take a hero or the family past the hourly limits.
   *
   * @param array $hero_counts       Events in the last hour, hero id => count.
   * @param int   $family_count      Events in the last hour for the whole family.
   * @param array $incoming_by_hero  The batch, hero id => events.
   */
  public static function over_limit( array $hero_counts, int $family_count, array $incoming_by_hero ): bool {
    $total = 0;
    foreach ( $incoming_by_hero as $hero_id => $count ) {
      $count = max( 0, (int) $count );
      if ( $count > 0 && (int) ( $hero_counts[ $hero_id ] ?? 0 ) + $count > self::HERO_PER_HOUR ) {
        return true;
      }
      $total += $count;
    }

    return $total > 0 && $family_count + $total > self::FAMILY_PER_HOUR;
  }

  /** A refused event. */
  private static function refuse( string $id, string $message ): array {
    return [ 'ok' => false, 'id' => $id, 'code' => self::INVALID, 'message' => $message ];
  }

  /**
   * An ISO 8601 date and time as unix seconds. A time with no zone counts as UTC. Words such
   * as "tomorrow", which strtotime would read, are refused.
   */
  private static function parse_time( string $at ): ?int {
    $shape = '/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}(:?\d{2})?)?\z/i';
    if ( 1 !== preg_match( $shape, $at ) ) {
      return null;
    }

    try {
      $time = new DateTimeImmutable( $at, new DateTimeZone( 'UTC' ) );
    } catch ( Exception $e ) {
      return null;
    }

    // 2026-02-31 would roll over into March: refuse it.
    $problems = DateTimeImmutable::getLastErrors();
    if ( is_array( $problems ) && ( $problems['warning_count'] > 0 || $problems['error_count'] > 0 ) ) {
      return null;
    }

    return $time->getTimestamp();
  }

  /**
   * The fields of a map (name => type) that $data has with a value of the right type.
   *
   * @return array The kept fields, in the order the map lists them.
   */
  private static function keep( array $fields, array $data ): array {
    $kept = [];
    foreach ( $fields as $name => $type ) {
      if ( ! array_key_exists( $name, $data ) ) {
        continue;
      }
      list( $ok, $value ) = self::typed( (string) $type, $data[ $name ] );
      if ( $ok ) {
        $kept[ $name ] = $value;
      }
    }

    return $kept;
  }

  /**
   * Why an event with right-looking fields still cannot be kept, or null: a list of quests
   * over SHEET_QUESTS_MAX (counted as the device sent it), or an enum field with no value of
   * its own (an event with no status says nothing, so it is refused, not stored empty).
   *
   * @param array $fields The kind's fields.
   * @param array $data   The data as the device sent it.
   * @param array $kept   filter_data of that data.
   */
  private static function unfit( array $fields, array $data, array $kept ): ?string {
    foreach ( $fields as $name => $type ) {
      $type = (string) $type;
      if ( 'quests' === $type && is_array( $data[ $name ] ?? null ) && count( $data[ $name ] ) > self::SHEET_QUESTS_MAX ) {
        return 'The ' . $name . ' list holds at most ' . self::SHEET_QUESTS_MAX . ' quests.';
      }
      if ( 0 === strpos( $type, 'enum:' ) && ! array_key_exists( $name, $kept ) ) {
        return 'The ' . $name . ' must be one of ' . implode( ', ', explode( '|', substr( $type, 5 ) ) ) . '.';
      }
    }

    return null;
  }

  /**
   * One value against one field type.
   *
   * @param mixed $v
   * @return array [ bool kept, mixed value ]
   */
  private static function typed( string $type, $v ): array {
    if ( 'int' === $type ) {
      return is_int( $v ) ? [ true, max( 0, min( self::INT_MAX, $v ) ) ] : [ false, null ];
    }
    if ( 'bool' === $type ) {
      return is_bool( $v ) ? [ true, $v ] : [ false, null ];
    }
    if ( 1 === preg_match( '/\Astr(\d+)\z/', $type, $m ) ) {
      return self::text_within( $v, (int) $m[1] ) ? [ true, $v ] : [ false, null ];
    }
    if ( 'look' === $type ) {
      $look = self::look( $v );
      return [] === $look ? [ false, null ] : [ true, $look ];
    }
    if ( 0 === strpos( $type, 'enum:' ) ) {
      return is_string( $v ) && in_array( $v, explode( '|', substr( $type, 5 ) ), true ) ? [ true, $v ] : [ false, null ];
    }
    if ( 'stats' === $type ) {
      $stats = self::is_object( $v ) ? self::keep( self::SHEET_STATS, $v ) : [];
      return [] === $stats ? [ false, null ] : [ true, $stats ];
    }
    if ( 'quests' === $type ) {
      return is_array( $v ) && self::is_list( $v ) ? [ true, self::quests( $v ) ] : [ false, null ];
    }

    return [ false, null ];
  }

  /**
   * Whether an array is a list: keys 0, 1, 2... in order. Same answer as array_is_list, which is
   * PHP 8.1 only; a JSON object with the keys "0" and "1" decodes to a list, as it does there.
   */
  private static function is_list( array $v ): bool {
    return [] === $v || array_keys( $v ) === range( 0, count( $v ) - 1 );
  }

  /** Whether a decoded JSON value is a non-empty object (an empty one decodes to an empty list). @param mixed $v */
  private static function is_object( $v ): bool {
    return is_array( $v ) && [] !== $v && ! self::is_list( $v );
  }

  /**
   * The quests of a hero sheet: each an object with only the SHEET_QUEST fields it has right.
   * An entry that is not an object, or has no quest id of its own, is left out.
   */
  private static function quests( array $list ): array {
    $quests = [];
    foreach ( $list as $q ) {
      $kept = self::is_object( $q ) ? self::keep( self::SHEET_QUEST, $q ) : [];
      if ( '' !== ( $kept['questId'] ?? '' ) ) {
        $quests[] = $kept;
      }
    }

    return $quests;
  }

  /** Whether a value is a UTF-8 string of at most $max characters. @param mixed $v */
  private static function text_within( $v, int $max ): bool {
    return is_string( $v ) && mb_check_encoding( $v, 'UTF-8' ) && mb_strlen( $v, 'UTF-8' ) <= $max;
  }

  /**
   * A look with only its known parts, each a short string. An empty array when there is none.
   *
   * @param mixed $v
   */
  private static function look( $v ): array {
    if ( ! is_array( $v ) ) {
      return [];
    }

    $look = [];
    foreach ( self::LOOK_FIELDS as $part ) {
      if ( array_key_exists( $part, $v ) && self::text_within( $v[ $part ], self::LOOK_VALUE_MAX ) ) {
        $look[ $part ] = $v[ $part ];
      }
    }

    return $look;
  }
}
