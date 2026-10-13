<?php

/**
 * The family store of Do It For The XP: the rows of the family tables on a family site.
 *
 * Every read and write of the five family tables goes through here, with the site's own
 * prefix. It decides nothing: the routes ask the rule classes first, then call the store.
 * Times are stored as UTC datetimes.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Store {

  /** Seconds to wait for a family's lock (GET_LOCK) before an append gives up. */
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
   * Remove a hero: marked removed, card cleared, every phone of the hero revoked and its
   * unused link codes deleted. Removing a hero that is not there changes nothing.
   */
  public function remove_hero( string $family_id, string $hero_id ): void {
    $now = self::db_time( time() );

    $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'xp_family_heroes' )} SET removed_at = %s, card = NULL, card_at = NULL
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
   * Unlink a phone of this family. A phone of another family is never touched.
   *
   * @return bool Whether a linked phone was revoked now.
   */
  public function revoke_device( string $family_id, int $id ): bool {
    $done = $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'xp_family_devices' )} SET revoked_at = %s WHERE id = %d AND family_id = %s AND revoked_at IS NULL",
      self::db_time( time() ),
      $id,
      $family_id
    ) );

    return 1 === (int) $done;
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

  /** A family table's full name on this site. */
  private function t( string $name ): string {
    return $this->db->prefix . $name;
  }
}
