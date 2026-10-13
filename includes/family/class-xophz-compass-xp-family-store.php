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

  /** A family table's full name on this site. */
  private function t( string $name ): string {
    return $this->db->prefix . $name;
  }
}
