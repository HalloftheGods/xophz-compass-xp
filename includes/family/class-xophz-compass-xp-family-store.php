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

  /** A family table's full name on this site. */
  private function t( string $name ): string {
    return $this->db->prefix . $name;
  }
}
