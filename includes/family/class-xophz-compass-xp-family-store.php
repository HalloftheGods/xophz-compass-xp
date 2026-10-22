<?php

/**
 * The family store of Do It For The XP: the rows of the family tables on a family site.
 *
 * Every read and write of the six family tables goes through here, with the site's own
 * prefix. It decides nothing: the routes ask the rule classes first, then call the store.
 * Times are stored as UTC datetimes.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Store {

  /** Seconds to wait for a family's lock (GET_LOCK) before an append or a records write gives up. */
  const LOCK_WAIT = 5;

  /** @var wpdb */
  private $db;

  /**
   * @param wpdb|null $db The database; the global $wpdb when null.
   */
  public function __construct( $db = null ) {
    if ( null === $db ) {
      global $wpdb;
      $db = $wpdb;
    }
    $this->db = $db;
  }

  /** A unix time as a stored UTC datetime. */
  public static function db_time( int $unix ): string {
    return gmdate( 'Y-m-d H:i:s', $unix );
  }

  /** A stored UTC datetime as unix seconds; null for an empty one. */
  public static function unix( $stored ): ?int {
    if ( ! is_string( $stored ) || '' === $stored || '0000-00-00 00:00:00' === $stored ) {
      return null;
    }
    $t = strtotime( $stored . ' UTC' );

    return false === $t ? null : $t;
  }

  /** A stored UTC datetime as ISO 8601 (2026-10-13T09:30:00Z); null for an empty one. */
  public static function iso( $stored ): ?string {
    $t = self::unix( $stored );

    return null === $t ? null : gmdate( 'Y-m-d\TH:i:s\Z', $t );
  }

  // --- families --------------------------------------------------------------------------------

  /** The family this user owns, or null. */
  public function family_of_user( int $user_id ): ?array {
    if ( $user_id < 1 ) {
      return null;
    }
    $row = $this->db->get_row(
      $this->db->prepare( "SELECT * FROM {$this->t( 'xp_families' )} WHERE owner_user_id = %d", $user_id ),
      ARRAY_A
    );

    return is_array( $row ) ? $row : null;
  }

  /** The family with this id, or null. */
  public function family( string $family_id ): ?array {
    $row = $this->db->get_row(
      $this->db->prepare( "SELECT * FROM {$this->t( 'xp_families' )} WHERE family_id = %s", $family_id ),
      ARRAY_A
    );

    return is_array( $row ) ? $row : null;
  }

  /**
   * Tie a new family to an account, with this device as home. INSERT IGNORE: the unique
   * owner_user_id and the family_id key make a second concurrent bind a no-op, so the caller
   * reads the rows again to learn who won.
   */
  public function create_family( string $family_id, int $user_id, string $device_id ): void {
    $now = self::db_time( time() );
    $this->db->query( $this->db->prepare(
      "INSERT IGNORE INTO {$this->t( 'xp_families' )} (family_id, owner_user_id, home_device_id, created_at, updated_at)
       VALUES (%s, %d, %s, %s, %s)",
      $family_id,
      $user_id,
      $device_id,
      $now,
      $now
    ) );
  }

  /** Make this device the family's home device. */
  public function move_home( string $family_id, string $device_id ): void {
    $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'xp_families' )} SET home_device_id = %s, updated_at = %s WHERE family_id = %s",
      $device_id,
      self::db_time( time() ),
      $family_id
    ) );
  }

  // --- heroes ----------------------------------------------------------------------------------

  /**
   * Register a hero or update its nickname and age group. A removed hero comes back (with no
   * card: removing cleared it).
   */
  public function save_hero( string $family_id, string $hero_id, string $nickname, string $age_group ): void {
    $this->db->query( $this->db->prepare(
      "INSERT INTO {$this->t( 'xp_family_heroes' )} (family_id, hero_id, nickname, age_group, created_at)
       VALUES (%s, %s, %s, %s, %s)
       ON DUPLICATE KEY UPDATE nickname = VALUES(nickname), age_group = VALUES(age_group), removed_at = NULL",
      $family_id,
      $hero_id,
      $nickname,
      $age_group,
      self::db_time( time() )
    ) );
  }

  /**
   * Remove a hero: marked removed, card cleared, the turn it was held on given back, every phone
   * of the hero revoked and its unused link codes deleted. Removing a hero that is not there
   * changes nothing.
   */
  public function remove_hero( string $family_id, string $hero_id ): void {
    $now = self::db_time( time() );

    $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'xp_family_heroes' )}
       SET removed_at = %s, card = NULL, card_at = NULL, turn_device_id = NULL, turn_label = '', turn_taken_at = NULL, turn_seen_at = NULL
       WHERE family_id = %s AND hero_id = %s AND removed_at IS NULL",
      $now,
      $family_id,
      $hero_id
    ) );

    $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'xp_family_devices' )} SET revoked_at = %s
       WHERE family_id = %s AND hero_id = %s AND revoked_at IS NULL",
      $now,
      $family_id,
      $hero_id
    ) );

    $this->db->query( $this->db->prepare(
      "DELETE FROM {$this->t( 'xp_family_link_codes' )} WHERE family_id = %s AND hero_id = %s AND used_at IS NULL",
      $family_id,
      $hero_id
    ) );
  }

  /** The family's live heroes (not removed), oldest first. */
  public function heroes( string $family_id ): array {
    $rows = $this->db->get_results(
      $this->db->prepare(
        "SELECT * FROM {$this->t( 'xp_family_heroes' )} WHERE family_id = %s AND removed_at IS NULL ORDER BY created_at, hero_id",
        $family_id
      ),
      ARRAY_A
    );

    return is_array( $rows ) ? $rows : [];
  }

  /** One live hero of the family, or null when it is not there or was removed. */
  public function hero( string $family_id, string $hero_id ): ?array {
    $row = $this->db->get_row(
      $this->db->prepare(
        "SELECT * FROM {$this->t( 'xp_family_heroes' )} WHERE family_id = %s AND hero_id = %s AND removed_at IS NULL",
        $family_id,
        $hero_id
      ),
      ARRAY_A
    );

    return is_array( $row ) ? $row : null;
  }

  /**
   * Keep a hero's card (the filtered data of a hero.card) when it is newer than the one
   * kept, and the hero's nickname from it when that nickname is fit to store.
   *
   * @param array $card The card fields: nickname, look, jobClass, level.
   * @param int   $at   When the card was made on the device (unix).
   */
  public function set_card( string $family_id, string $hero_id, array $card, int $at ): void {
    $json     = wp_json_encode( [] === $card ? new stdClass() : $card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    $nickname = Xophz_Compass_Xp_Family_Rules::clean_nickname( $card['nickname'] ?? null );
    $when     = self::db_time( $at );
    $heroes   = $this->t( 'xp_family_heroes' );
    $newer    = 'family_id = %s AND hero_id = %s AND removed_at IS NULL AND ( card_at IS NULL OR card_at < %s )';

    if ( null === $nickname ) {
      $this->db->query( $this->db->prepare(
        "UPDATE {$heroes} SET card = %s, card_at = %s WHERE {$newer}",
        $json,
        $when,
        $family_id,
        $hero_id,
        $when
      ) );
      return;
    }

    $this->db->query( $this->db->prepare(
      "UPDATE {$heroes} SET card = %s, card_at = %s, nickname = %s WHERE {$newer}",
      $json,
      $when,
      $nickname,
      $family_id,
      $hero_id,
      $when
    ) );
  }

  // --- link codes ------------------------------------------------------------------------------

  /**
   * Keep a new link code (its hash only). The hero's unused codes are deleted first, so a new
   * code replaces the old one.
   *
   * @return bool False when the hash is already stored (the caller makes another code).
   */
  public function put_code( string $code_hash, string $family_id, string $hero_id, int $user_id, int $expires_at ): bool {
    $codes = $this->t( 'xp_family_link_codes' );

    $this->db->query( $this->db->prepare(
      "DELETE FROM {$codes} WHERE family_id = %s AND hero_id = %s AND used_at IS NULL",
      $family_id,
      $hero_id
    ) );

    $done = $this->db->query( $this->db->prepare(
      "INSERT IGNORE INTO {$codes} (code_hash, family_id, hero_id, created_by, expires_at) VALUES (%s, %s, %s, %d, %s)",
      $code_hash,
      $family_id,
      $hero_id,
      $user_id,
      self::db_time( $expires_at )
    ) );

    return 1 === (int) $done;
  }

  /**
   * Use a link code once: one UPDATE marks it used only while it is unused and unexpired, so
   * two claims at the same moment cannot both win. The row is read only when this call won.
   *
   * @return array|null The code row, or null when it is unknown, used or expired.
   */
  public function take_code( string $code_hash, int $now ): ?array {
    $codes = $this->t( 'xp_family_link_codes' );
    $at    = self::db_time( $now );

    $changed = $this->db->query( $this->db->prepare(
      "UPDATE {$codes} SET used_at = %s WHERE code_hash = %s AND used_at IS NULL AND expires_at > %s",
      $at,
      $code_hash,
      $at
    ) );
    if ( 1 !== (int) $changed ) {
      return null;
    }

    $row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$codes} WHERE code_hash = %s", $code_hash ), ARRAY_A );

    return is_array( $row ) ? $row : null;
  }

  // --- hero phones -----------------------------------------------------------------------------

  /**
   * Link a hero phone; only the hash of its key is kept.
   *
   * @return int The phone's id, 0 when the row could not be written.
   */
  public function add_device( string $family_id, string $hero_id, string $device_id, string $label, string $key_hash ): int {
    $done = $this->db->query( $this->db->prepare(
      "INSERT INTO {$this->t( 'xp_family_devices' )} (family_id, hero_id, device_id, label, key_hash, created_at)
       VALUES (%s, %s, %s, %s, %s, %s)",
      $family_id,
      $hero_id,
      $device_id,
      $label,
      $key_hash,
      self::db_time( time() )
    ) );

    return 1 === (int) $done ? (int) $this->db->insert_id : 0;
  }

  /**
   * The linked phone of a key's hash: not revoked, and its hero not removed.
   *
   * @return array|null The phone row, or null.
   */
  public function device_by_key_hash( string $hash ): ?array {
    $row = $this->db->get_row(
      $this->db->prepare(
        "SELECT d.* FROM {$this->t( 'xp_family_devices' )} d
         JOIN {$this->t( 'xp_family_heroes' )} h ON h.family_id = d.family_id AND h.hero_id = d.hero_id
         JOIN {$this->t( 'xp_families' )} f ON f.family_id = d.family_id
         WHERE d.key_hash = %s AND d.revoked_at IS NULL AND h.removed_at IS NULL",
        $hash
      ),
      ARRAY_A
    );

    return is_array( $row ) ? $row : null;
  }

  /** The family's linked phones (not revoked), oldest first. */
  public function devices( string $family_id ): array {
    $rows = $this->db->get_results(
      $this->db->prepare(
        "SELECT * FROM {$this->t( 'xp_family_devices' )} WHERE family_id = %s AND revoked_at IS NULL ORDER BY id",
        $family_id
      ),
      ARRAY_A
    );

    return is_array( $rows ) ? $rows : [];
  }

  /**
   * Unlink a phone of this family, and give back the turn of its hero if it held it. A phone of
   * another family is never touched; a phone that was already revoked clears nothing (its device
   * id may hold a turn again by now).
   *
   * @return bool Whether a linked phone was revoked now.
   */
  public function revoke_device( string $family_id, int $id ): bool {
    $devices = $this->t( 'xp_family_devices' );

    $done = $this->db->query( $this->db->prepare(
      "UPDATE {$devices} SET revoked_at = %s WHERE id = %d AND family_id = %s AND revoked_at IS NULL",
      self::db_time( time() ),
      $id,
      $family_id
    ) );
    if ( 1 !== (int) $done ) {
      return false;
    }

    // Only the turn of the phone's own hero: the same device id may hold other heroes elsewhere.
    $phone = $this->db->get_row(
      $this->db->prepare( "SELECT hero_id, device_id FROM {$devices} WHERE id = %d AND family_id = %s", $id, $family_id ),
      ARRAY_A
    );
    if ( is_array( $phone ) ) {
      $this->rest_turn( $family_id, (string) $phone['hero_id'], (string) $phone['device_id'] );
    }

    return true;
  }

  /** Note that a phone called, at most once a minute. */
  public function touch_device( int $id, int $now ): void {
    $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'xp_family_devices' )} SET last_seen_at = %s WHERE id = %d AND ( last_seen_at IS NULL OR last_seen_at <= %s )",
      self::db_time( $now ),
      $id,
      self::db_time( $now - 60 )
    ) );
  }

  // --- the event stream ------------------------------------------------------------------------

  /**
   * Append checked events (the 'event' rows of Event_Rules::check_event) to a family's stream.
   *
   * Runs under the family's named lock (GET_LOCK( 'xp_family_{family_id}', 5 )), so a family's
   * numbers are committed in order and a reader asking for "after 104" never misses a 104
   * still being written. An id already stored (or twice in the batch) is a duplicate, never a
   * second copy. An accepted hero.card also becomes the hero's card when it is newer. An event
   * that could not be written is in neither list, so the device sends it again.
   *
   * @return array|WP_Error [ 'accepted' => ids, 'duplicate' => ids ], or 503 xp_family_busy
   *                        when the lock could not be taken.
   */
  public function append_events( string $family_id, array $events ) {
    $lock = 'xp_family_' . $family_id;
    $got  = $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT ) );
    if ( '1' !== (string) $got ) {
      return new WP_Error( 'xp_family_busy', 'The family is busy. Send again in a moment.', [ 'status' => 503 ] );
    }

    $accepted  = [];
    $duplicate = [];

    try {
      $stored = array_flip( $this->known_event_ids( array_column( $events, 'event_id' ) ) );
      $now    = self::db_time( time() );

      foreach ( $events as $e ) {
        $id = (string) $e['event_id'];
        if ( isset( $stored[ $id ] ) ) {
          $duplicate[] = $id;
          continue;
        }

        $data = is_array( $e['data'] ) && [] !== $e['data'] ? $e['data'] : new stdClass();
        $done = $this->db->insert(
          $this->t( 'xp_family_events' ),
          [
            'event_id'    => $id,
            'family_id'   => $family_id,
            'hero_id'     => (string) $e['hero_id'],
            'device_id'   => (string) $e['device_id'],
            'kind'        => (string) $e['kind'],
            'scope'       => (string) $e['scope'],
            'occurred_at' => self::db_time( (int) $e['occurred_at'] ),
            'received_at' => $now,
            'data'        => wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
          ],
          [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        if ( 1 !== (int) $done ) {
          continue;
        }

        $stored[ $id ] = true;
        $accepted[]    = $id;

        if ( 'hero.card' === $e['kind'] && is_array( $e['data'] ) ) {
          $this->set_card( $family_id, (string) $e['hero_id'], $e['data'], (int) $e['occurred_at'] );
        }
      }
    } finally {
      $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
    }

    return [ 'accepted' => $accepted, 'duplicate' => $duplicate ];
  }

  /**
   * Which of these event ids are already stored (in any family: ids are unique site-wide).
   *
   * @param string[] $ids
   * @return string[]
   */
  public function known_event_ids( array $ids ): array {
    $ids = array_values( array_unique( array_filter( $ids, 'is_string' ) ) );
    if ( [] === $ids ) {
      return [];
    }

    $marks = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
    $found = $this->db->get_col(
      $this->db->prepare( "SELECT event_id FROM {$this->t( 'xp_family_events' )} WHERE event_id IN ({$marks})", $ids )
    );

    return is_array( $found ) ? array_map( 'strval', $found ) : [];
  }

  /**
   * A family's events after a number, oldest first.
   *
   * @param string|null $hero_id For a hero key: only what that hero may read (public events and
   *                             the family events of that hero), so a page is never filled with
   *                             rows the caller cannot see.
   */
  public function events_after( string $family_id, int $since, int $limit, ?string $hero_id = null ): array {
    $events = $this->t( 'xp_family_events' );

    $sql = null === $hero_id
      ? $this->db->prepare(
        "SELECT * FROM {$events} WHERE family_id = %s AND seq > %d ORDER BY seq ASC LIMIT %d",
        $family_id,
        max( 0, $since ),
        max( 1, $limit )
      )
      : $this->db->prepare(
        "SELECT * FROM {$events} WHERE family_id = %s AND seq > %d
         AND ( scope = 'public' OR ( scope = 'family' AND hero_id = %s ) ) ORDER BY seq ASC LIMIT %d",
        $family_id,
        max( 0, $since ),
        $hero_id,
        max( 1, $limit )
      );

    $rows = $this->db->get_results( $sql, ARRAY_A );

    return is_array( $rows ) ? $rows : [];
  }

  /**
   * The events a family's devices sent in the last hour (what the server writes itself is not
   * counted).
   *
   * @return array [ 'family' => int, 'by_hero' => [ hero id => int ] ]
   */
  public function counts_last_hour( string $family_id, int $now ): array {
    $server_kinds = [];
    foreach ( Xophz_Compass_Xp_Family_Event_Rules::KINDS as $kind => $def ) {
      if ( 'server' === ( $def['writer'] ?? null ) ) {
        $server_kinds[] = $kind;
      }
    }
    $skip  = [] === $server_kinds ? "''" : implode( ',', array_fill( 0, count( $server_kinds ), '%s' ) );
    $query = "SELECT hero_id, COUNT(*) AS n FROM {$this->t( 'xp_family_events' )}
      WHERE family_id = %s AND received_at > %s AND kind NOT IN ({$skip}) GROUP BY hero_id";

    $rows = $this->db->get_results(
      $this->db->prepare( $query, array_merge( [ $family_id, self::db_time( $now - 3600 ) ], $server_kinds ) ),
      ARRAY_A
    );

    $by_hero = [];
    $family  = 0;
    foreach ( is_array( $rows ) ? $rows : [] as $row ) {
      $by_hero[ (string) $row['hero_id'] ] = (int) $row['n'];
      $family                             += (int) $row['n'];
    }

    return [ 'family' => $family, 'by_hero' => $by_hero ];
  }

  // --- the family's save: records ---------------------------------------------------------------

  /**
   * Write checked records (the clean records of Record_Rules::check) into the family's save.
   *
   * Runs under the family's named lock, the one append_events takes. Under it the counter
   * (xp_families.records_seq) is read, the accepted records get the next numbers in order, the
   * counter moves past them, and each record is upserted: an existing key is overwritten and
   * takes the new number, so a reader asking for "after N" never misses a change. The counter
   * moves before the rows are written, so a crash in between leaves a gap in the numbers and
   * never a number given twice. A body is stored exactly as it came (the JSON text); a
   * tombstone stores an empty one.
   *
   * A hero key ($caller_hero_id) may not touch a row that already belongs to someone else,
   * live write or tombstone: a row of another hero, or of nobody (a Game Master's chat message
   * or quest, whose hero_id is NULL), is refused with xp_family_scope and takes no number. A
   * key with no row yet is free, and a row of its own hero it may rewrite. A parent is never
   * restricted. Keys are compared without their trailing spaces, as MySQL compares them.
   *
   * The turn is checked here, under the lock, and the routes rely on it (they do not check it
   * first): a record of a hero-scope store (Record_Rules::STORES), live or removed, from a
   * device that does not hold that hero's turn is refused with xp_family_turn and takes no
   * number. Family records and the any-writer stores (chat, quests, beasts) are never
   * turn-checked. take_turn takes the same lock, so once a takeover has been granted no batch
   * of the old holder can still land. The heroes whose records were written get their last
   * seen refreshed before the lock is let go.
   *
   * A record that could not be written is in neither list, so the device sends it again.
   *
   * @param string      $family_id
   * @param string      $device_id      The device that sends the batch.
   * @param array       $records        Clean records: store, key, hero_id, deleted, body.
   * @param int         $now            Unix time of the write.
   * @param string|null $caller_hero_id The hero of the key when a hero phone sends the batch.
   * @return array|WP_Error [ 'accepted' => [ [ store, key, seq ] ], 'refused' => [ [ store, key,
   *                        code, message ] ] ] (codes xp_family_scope, xp_family_turn), both in the
   *                        order the records came in; 503
   *                        xp_family_busy when the lock could not be taken; 404
   *                        xp_family_no_family when the family is not there.
   */
  public function upsert_records( string $family_id, string $device_id, array $records, int $now, ?string $caller_hero_id = null ) {
    if ( [] === $records ) {
      return [ 'accepted' => [], 'refused' => [] ];
    }

    $lock = 'xp_family_' . $family_id;
    $got  = $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT ) );
    if ( '1' !== (string) $got ) {
      return new WP_Error( 'xp_family_busy', 'The family is busy. Send again in a moment.', [ 'status' => 503 ] );
    }

    $accepted = [];
    $refused  = [];

    try {
      $families = $this->t( 'xp_families' );
      $counter  = $this->db->get_var( $this->db->prepare( "SELECT records_seq FROM {$families} WHERE family_id = %s FOR UPDATE", $family_id ) );
      if ( null === $counter ) {
        return new WP_Error( 'xp_family_no_family', 'There is no such family.', [ 'status' => 404 ] );
      }

      $owners  = null === $caller_hero_id ? [] : $this->record_owners( $family_id, $records );
      $holders = $this->turn_holders( $family_id, $records );
      $write   = [];
      foreach ( $records as $r ) {
        $name = self::owner_name( $r['store'], $r['key'] );
        if ( array_key_exists( $name, $owners ) && $owners[ $name ] !== $caller_hero_id ) {
          $refused[] = self::refusal( $r, 'xp_family_scope', 'That record belongs to someone else.' );
          continue;
        }
        if ( self::is_hero_record( $r ) && ( $holders[ (string) ( $r['hero_id'] ?? '' ) ] ?? null ) !== $device_id ) {
          $refused[] = self::refusal( $r, 'xp_family_turn', 'Another device is playing this hero.' );
          continue;
        }
        $write[] = $r;
      }

      if ( [] !== $write ) {
        $first    = (int) $counter + 1;
        $reserved = $this->db->query( $this->db->prepare(
          "UPDATE {$families} SET records_seq = %d WHERE family_id = %s",
          (int) $counter + count( $write ),
          $family_id
        ) );
        if ( false === $reserved ) {
          return new WP_Error( 'xp_family_busy', 'The family is busy. Send again in a moment.', [ 'status' => 503 ] );
        }

        $played = [];
        foreach ( $write as $i => $r ) {
          $seq = $first + $i;
          if ( false === $this->put_record( $family_id, $device_id, $r, $seq, $now ) ) {
            continue;
          }
          $accepted[] = [ 'store' => (string) $r['store'], 'key' => (string) $r['key'], 'seq' => $seq ];
          if ( self::is_hero_record( $r ) ) {
            $played[ (string) $r['hero_id'] ] = true;
          }
        }

        foreach ( array_keys( $played ) as $hero_id ) {
          $this->touch_turn( $family_id, (string) $hero_id, $device_id, $now );
        }
      }
    } finally {
      $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
    }

    return [ 'accepted' => $accepted, 'refused' => $refused ];
  }

  /**
   * The family's records after a number, oldest first (the rows as stored: the body is the JSON
   * text, a tombstone has deleted = 1 and an empty body).
   *
   * @param string|null $hero_id   For a hero key: only what that hero may read (the family's
   *                               records, every row of the any-writer stores and that hero's own),
   *                               so a page is never filled with rows the caller cannot see.
   * @param int|null    $max_bytes A cap on the bodies of the page. The page ends before the first row
   *                               that would take the bodies past it, and always holds one row. It is
   *                               read in two steps so PHP never holds more than a page of bodies: one
   *                               query reads only the size of each candidate row (at most $limit
   *                               numbers), then the bodies are fetched up to the last row that
   *                               fits. A row rewritten in between moves past the page and shows up
   *                               on a later one; no row is lost, because the next read starts from
   *                               the last number returned. Null reads $limit rows whatever their size.
   */
  public function records_after( string $family_id, int $since, int $limit, ?string $hero_id = null, ?int $max_bytes = null ): array {
    $records = $this->t( 'xp_family_records' );
    $since   = max( 0, $since );
    $limit   = max( 1, $limit );

    $filter = '';
    $named  = [];
    if ( null !== $hero_id ) {
      $stores = Xophz_Compass_Xp_Family_Record_Rules::any_writer_stores();
      $filter = ' AND ( hero_id IS NULL OR hero_id = %s OR store IN (' . implode( ', ', array_fill( 0, count( $stores ), '%s' ) ) . ') )';
      $named  = array_merge( [ $hero_id ], $stores );
    }

    $upto = '';
    $stop = [];
    if ( null !== $max_bytes ) {
      $sizes = $this->db->get_results(
        $this->db->prepare(
          "SELECT seq, LENGTH(body) AS size FROM {$records} WHERE family_id = %s AND seq > %d{$filter} ORDER BY seq ASC LIMIT %d",
          array_merge( [ $family_id, $since ], $named, [ $limit ] )
        ),
        ARRAY_A
      );

      $last  = null;
      $total = 0;
      foreach ( is_array( $sizes ) ? $sizes : [] as $row ) {
        if ( null !== $last && $total + (int) $row['size'] > $max_bytes ) {
          break;
        }
        $total += (int) $row['size'];
        $last   = (int) $row['seq'];
      }
      if ( null === $last ) {
        return [];
      }

      $upto = ' AND seq <= %d';
      $stop = [ $last ];
    }

    $rows = $this->db->get_results(
      $this->db->prepare(
        "SELECT * FROM {$records} WHERE family_id = %s AND seq > %d{$upto}{$filter} ORDER BY seq ASC LIMIT %d",
        array_merge( [ $family_id, $since ], $stop, $named, [ $limit ] )
      ),
      ARRAY_A
    );

    return is_array( $rows ) ? $rows : [];
  }

  /**
   * The hero each of these records' keys belongs to now, read once for the batch: store,
   * NUL and key => hero id, or null for a row that belongs to nobody. A key with no row is
   * not in the map.
   */
  private function record_owners( string $family_id, array $records ): array {
    $pairs = [];
    $args  = [ $family_id ];
    foreach ( $records as $r ) {
      $name = self::owner_name( $r['store'], $r['key'] );
      if ( isset( $pairs[ $name ] ) ) {
        continue;
      }
      $pairs[ $name ] = '( store = %s AND record_key = %s )';
      array_push( $args, (string) $r['store'], (string) $r['key'] );
    }

    $rows = $this->db->get_results(
      $this->db->prepare(
        "SELECT store, record_key, hero_id FROM {$this->t( 'xp_family_records' )} WHERE family_id = %s AND ( " . implode( ' OR ', $pairs ) . ' )',
        $args
      ),
      ARRAY_A
    );

    $owners = [];
    foreach ( is_array( $rows ) ? $rows : [] as $row ) {
      $owners[ self::owner_name( $row['store'], $row['record_key'] ) ] = isset( $row['hero_id'] ) ? (string) $row['hero_id'] : null;
    }

    return $owners;
  }

  /** The name a record goes by in the owners map: MySQL reads 'k ' and 'k' as the same key. */
  private static function owner_name( $store, $key ): string {
    return $store . "\0" . rtrim( (string) $key, ' ' );
  }

  /** Whether a clean record belongs to a hero: its store is a hero-scope store. */
  private static function is_hero_record( array $r ): bool {
    $def = Xophz_Compass_Xp_Family_Record_Rules::STORES[ $r['store'] ] ?? null;

    return null !== $def && 'hero' === $def['scope'];
  }

  /** A refused record's entry in the answer. */
  private static function refusal( array $r, string $code, string $message ): array {
    return [ 'store' => (string) $r['store'], 'key' => (string) $r['key'], 'code' => $code, 'message' => $message ];
  }

  /**
   * The device that holds the turn of each hero a batch writes: hero id => device id (null when
   * nobody does). A hero that is not there or was removed is not in the map. No query when the
   * batch holds no hero record.
   */
  private function turn_holders( string $family_id, array $records ): array {
    $heroes = [];
    foreach ( $records as $r ) {
      if ( self::is_hero_record( $r ) && isset( $r['hero_id'] ) ) {
        $heroes[ (string) $r['hero_id'] ] = true;
      }
    }
    if ( [] === $heroes ) {
      return [];
    }

    $heroes = array_map( 'strval', array_keys( $heroes ) );
    $marks  = implode( ', ', array_fill( 0, count( $heroes ), '%s' ) );
    $rows   = $this->db->get_results(
      $this->db->prepare(
        "SELECT hero_id, turn_device_id FROM {$this->t( 'xp_family_heroes' )} WHERE family_id = %s AND removed_at IS NULL AND hero_id IN ({$marks})",
        array_merge( [ $family_id ], $heroes )
      ),
      ARRAY_A
    );

    $holders = [];
    foreach ( is_array( $rows ) ? $rows : [] as $row ) {
      $holders[ (string) $row['hero_id'] ] = isset( $row['turn_device_id'] ) ? (string) $row['turn_device_id'] : null;
    }

    return $holders;
  }

  /**
   * Upsert one clean record under its number. A family record's hero id is a real NULL (a NULL
   * argument would be written as an empty string).
   *
   * @return int|bool What the query answered: false when the row was not written.
   */
  private function put_record( string $family_id, string $device_id, array $r, int $seq, int $now ) {
    $hero = $r['hero_id'] ?? null;
    $gone = ! empty( $r['deleted'] );
    $args = [ $seq, $family_id, (string) $r['store'], (string) $r['key'] ];
    if ( null !== $hero ) {
      $args[] = (string) $hero;
    }
    array_push( $args, $device_id, self::db_time( $now ), $gone ? 1 : 0, $gone ? '' : (string) ( $r['body'] ?? '' ) );
    $hero_sql = null === $hero ? 'NULL' : '%s';

    return $this->db->query( $this->db->prepare(
      "INSERT INTO {$this->t( 'xp_family_records' )} (seq, family_id, store, record_key, hero_id, device_id, saved_at, deleted, body)
       VALUES (%d, %s, %s, %s, {$hero_sql}, %s, %s, %d, %s)
       ON DUPLICATE KEY UPDATE seq = VALUES(seq), hero_id = VALUES(hero_id), device_id = VALUES(device_id),
       saved_at = VALUES(saved_at), deleted = VALUES(deleted), body = VALUES(body)",
      $args
    ) );
  }

  // --- a hero's turn ---------------------------------------------------------------------------

  /**
   * Take a hero's play turn for a device. One conditional UPDATE decides, so two devices asking
   * at the same moment cannot both be granted: it writes the turn (device, label, now) only when
   * nobody holds it, this device already does, or the asker forces it. It runs under the
   * family's lock, the one upsert_records holds while it checks the turn and writes: a takeover
   * waits for a batch of the old holder in flight, and no batch of the old holder lands after it.
   *
   * @param string $label What the busy line names; cut to the column's 60 characters.
   * @return array|WP_Error [ 'answer' => 'granted' ]; [ 'answer' => 'busy', 'device' => [ 'label',
   *                        'last_seen_at' (ISO) ] ] when another device holds it and force is
   *                        false; [ 'answer' => 'missing' ] when the hero is not there or was
   *                        removed; 503 xp_family_busy when the lock could not be taken, the
   *                        write failed, or the turn was given back between the write and the
   *                        read (ask again).
   */
  public function take_turn( string $family_id, string $hero_id, string $device_id, string $label, bool $force, int $now ) {
    $lock = 'xp_family_' . $family_id;
    $got  = $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT ) );
    if ( '1' !== (string) $got ) {
      return new WP_Error( 'xp_family_busy', 'The family is busy. Ask again in a moment.', [ 'status' => 503 ] );
    }

    try {
      return $this->take_turn_locked( $family_id, $hero_id, $device_id, $label, $force, $now );
    } finally {
      $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
    }
  }

  /** take_turn with the family's lock held. */
  private function take_turn_locked( string $family_id, string $hero_id, string $device_id, string $label, bool $force, int $now ) {
    $at      = self::db_time( $now );
    $changed = $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'xp_family_heroes' )}
       SET turn_device_id = %s, turn_label = %s, turn_taken_at = %s, turn_seen_at = %s
       WHERE family_id = %s AND hero_id = %s AND removed_at IS NULL
       AND ( %d = 1 OR turn_device_id IS NULL OR turn_device_id = %s )",
      $device_id,
      mb_substr( $label, 0, Xophz_Compass_Xp_Family_Rules::LABEL_MAX, 'UTF-8' ),
      $at,
      $at,
      $family_id,
      $hero_id,
      $force ? 1 : 0,
      $device_id
    ) );
    if ( 1 === (int) $changed ) {
      return [ 'answer' => 'granted' ];
    }

    // Nothing changed: the hero is gone, someone else holds the turn, or this device holds it
    // already and every column came out the same (the same label in the same second).
    $row = $this->hero( $family_id, $hero_id );
    if ( null === $row ) {
      return [ 'answer' => 'missing' ];
    }

    $holder = (string) ( $row['turn_device_id'] ?? '' );
    if ( $holder === $device_id ) {
      return [ 'answer' => 'granted' ];
    }
    if ( '' === $holder ) {
      return new WP_Error( 'xp_family_busy', 'The turn could not be taken. Ask again in a moment.', [ 'status' => 503 ] );
    }

    return [
      'answer' => 'busy',
      'device' => [ 'label' => (string) $row['turn_label'], 'last_seen_at' => self::iso( $row['turn_seen_at'] ?? null ) ],
    ];
  }

  /** Give a hero's turn back, only when this device holds it (a late rest cannot clear a new holder). */
  public function rest_turn( string $family_id, string $hero_id, string $device_id ): void {
    $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'xp_family_heroes' )}
       SET turn_device_id = NULL, turn_label = '', turn_taken_at = NULL, turn_seen_at = NULL
       WHERE family_id = %s AND hero_id = %s AND turn_device_id = %s",
      $family_id,
      $hero_id,
      $device_id
    ) );
  }

  /** Note that the holder is still playing: refreshes last seen, only when this device holds the turn. */
  public function touch_turn( string $family_id, string $hero_id, string $device_id, int $now ): void {
    $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'xp_family_heroes' )} SET turn_seen_at = %s WHERE family_id = %s AND hero_id = %s AND turn_device_id = %s",
      self::db_time( $now ),
      $family_id,
      $hero_id,
      $device_id
    ) );
  }

  /** The device that holds a live hero's turn, or null when nobody does (or the hero is not there). */
  public function turn_holder( string $family_id, string $hero_id ): ?string {
    $holder = $this->db->get_var( $this->db->prepare(
      "SELECT turn_device_id FROM {$this->t( 'xp_family_heroes' )} WHERE family_id = %s AND hero_id = %s AND removed_at IS NULL",
      $family_id,
      $hero_id
    ) );

    return is_string( $holder ) && '' !== $holder ? $holder : null;
  }

  /** A family table's full name on this site. */
  private function t( string $name ): string {
    return $this->db->prefix . $name;
  }
}
