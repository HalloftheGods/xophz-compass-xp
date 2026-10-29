<?php

/**
 * The party store of Do It For The XP: the rows of the party tables on a family site.
 *
 * Every read and write of the party tables goes through here, with the site's own prefix. It decides nothing: the
 * routes ask the rule class first, then call the store. Times are stored as UTC datetimes; the SQL is plain enough to
 * run unchanged on MySQL and on the SQLite stand-in the tests use. A party's writes run under one named lock per
 * party (locked()), so the event counter, a raid's strikes and a roster change never interleave.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/party
 */

class Xophz_Compass_Xp_Party_Store {

  /** Seconds to wait for a party's lock (GET_LOCK) before a write gives up. */
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

  /** A party table name with the site's prefix (members, requests, events, raids, strikes, blocks). */
  private function t( string $name ): string {
    return $this->db->prefix . 'xp_party_' . $name;
  }

  /** The parties table, whose name does not follow the xp_party_ pattern. */
  private function parties_table(): string {
    return $this->db->prefix . 'xp_parties';
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

  // --- the party lock --------------------------------------------------------------------------

  /**
   * Runs `$work` under the party's named lock (GET_LOCK( 'xp_party_{id}', 5 )) and answers what it answers; a
   * WP_Error 503 when the lock cannot be had in time.
   *
   * @return mixed
   */
  public function locked( string $party_id, callable $work ) {
    $lock = 'xp_party_' . $party_id;
    $got  = $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT ) );
    if ( '1' !== (string) $got ) {
      return new WP_Error( 'xp_party_busy', 'This party is busy. Try again in a moment.', [ 'status' => 503 ] );
    }

    try {
      return $work();
    } finally {
      $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
    }
  }

  // --- parties ---------------------------------------------------------------------------------

  /** Whether a party already holds this code. */
  public function code_taken( string $code ): bool {
    return null !== $this->db->get_var( $this->db->prepare( "SELECT party_id FROM {$this->parties_table()} WHERE code = %s", $code ) );
  }

  /** Inserts a party: party_id, name, emblem, code, invite_rule, leader_family_id, leader_hero_id and now (unix). */
  public function create_party( array $p ): void {
    $this->db->insert(
      $this->parties_table(),
      [
        'party_id'         => $p['party_id'],
        'name'             => $p['name'],
        'emblem'           => $p['emblem'],
        'code'             => $p['code'],
        'invite_rule'      => $p['invite_rule'],
        'leader_family_id' => $p['leader_family_id'],
        'leader_hero_id'   => $p['leader_hero_id'],
        'events_seq'       => 0,
        'created_at'       => self::db_time( (int) $p['now'] ),
      ]
    );
  }

  /** A party that is not disbanded, or null. */
  public function party( string $party_id ): ?array {
    $row = $this->db->get_row(
      $this->db->prepare( "SELECT * FROM {$this->parties_table()} WHERE party_id = %s AND disbanded_at IS NULL", $party_id ),
      ARRAY_A
    );

    return $row ? $row : null;
  }

  /** The party that holds this code, or null. */
  public function party_by_code( string $code ): ?array {
    $row = $this->db->get_row(
      $this->db->prepare( "SELECT * FROM {$this->parties_table()} WHERE code = %s AND disbanded_at IS NULL", $code ),
      ARRAY_A
    );

    return $row ? $row : null;
  }

  public function set_code( string $party_id, string $code ): void {
    $this->db->update( $this->parties_table(), [ 'code' => $code ], [ 'party_id' => $party_id ] );
  }

  public function set_leader( string $party_id, string $family_id, string $hero_id ): void {
    $this->db->update( $this->parties_table(), [ 'leader_family_id' => $family_id, 'leader_hero_id' => $hero_id ], [ 'party_id' => $party_id ] );
  }

  /** Ends a party. Its code stops working and it drops out of every list. */
  public function disband( string $party_id, int $now ): void {
    $this->db->update( $this->parties_table(), [ 'disbanded_at' => self::db_time( $now ) ], [ 'party_id' => $party_id ] );
  }

  /** The ids of the parties a family has an active hero in, or just this hero's when a hero id is given. */
  public function party_ids_of( string $family_id, ?string $hero_id = null ): array {
    if ( null === $hero_id ) {
      $sql = $this->db->prepare( "SELECT DISTINCT party_id FROM {$this->t( 'members' )} WHERE family_id = %s AND left_at IS NULL", $family_id );
    } else {
      $sql = $this->db->prepare( "SELECT DISTINCT party_id FROM {$this->t( 'members' )} WHERE family_id = %s AND hero_id = %s AND left_at IS NULL", $family_id, $hero_id );
    }

    return array_map( 'strval', (array) $this->db->get_col( $sql ) );
  }

  // --- members ---------------------------------------------------------------------------------

  /** Adds a hero to a party with a role, or brings back one who left. `$card` is the public hero card. */
  public function add_member( string $party_id, string $family_id, string $hero_id, array $card, string $role, int $now ): void {
    $existing = $this->db->get_var( $this->db->prepare(
      "SELECT hero_id FROM {$this->t( 'members' )} WHERE party_id = %s AND family_id = %s AND hero_id = %s",
      $party_id,
      $family_id,
      $hero_id
    ) );

    $row = [ 'card' => wp_json_encode( $card ), 'role' => $role, 'joined_at' => self::db_time( $now ), 'left_at' => null ];
    if ( null !== $existing ) {
      $this->db->update( $this->t( 'members' ), $row, [ 'party_id' => $party_id, 'family_id' => $family_id, 'hero_id' => $hero_id ] );
      return;
    }

    $this->db->insert( $this->t( 'members' ), $row + [ 'party_id' => $party_id, 'family_id' => $family_id, 'hero_id' => $hero_id ] );
  }

  /** The active members of a party, earliest joined first, each with its card decoded. */
  public function members( string $party_id ): array {
    $rows = $this->db->get_results(
      $this->db->prepare( "SELECT * FROM {$this->t( 'members' )} WHERE party_id = %s AND left_at IS NULL ORDER BY joined_at, family_id, hero_id", $party_id ),
      ARRAY_A
    );

    return array_map( [ $this, 'member_row' ], (array) $rows );
  }

  /** One active member, or null. */
  public function member( string $party_id, string $family_id, string $hero_id ): ?array {
    $row = $this->db->get_row(
      $this->db->prepare(
        "SELECT * FROM {$this->t( 'members' )} WHERE party_id = %s AND family_id = %s AND hero_id = %s AND left_at IS NULL",
        $party_id,
        $family_id,
        $hero_id
      ),
      ARRAY_A
    );

    return $row ? $this->member_row( $row ) : null;
  }

  /** Changes a member's role (leader or member); when they joined stays as it was. */
  public function set_role( string $party_id, string $family_id, string $hero_id, string $role ): void {
    $this->db->update( $this->t( 'members' ), [ 'role' => $role ], [ 'party_id' => $party_id, 'family_id' => $family_id, 'hero_id' => $hero_id ] );
  }

  /** Marks a member as left; whether anyone was active to leave. */
  public function remove_member( string $party_id, string $family_id, string $hero_id, int $now ): bool {
    $changed = $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'members' )} SET left_at = %s WHERE party_id = %s AND family_id = %s AND hero_id = %s AND left_at IS NULL",
      self::db_time( $now ),
      $party_id,
      $family_id,
      $hero_id
    ) );

    return (int) $changed > 0;
  }

  /** A member row with its card decoded and its join time as unix seconds. */
  private function member_row( array $row ): array {
    $card = json_decode( (string) $row['card'], true );

    return [
      'party_id'  => $row['party_id'],
      'family_id' => $row['family_id'],
      'hero_id'   => $row['hero_id'],
      'card'      => is_array( $card ) ? $card : [],
      'role'      => $row['role'],
      'joined_at' => self::unix( $row['joined_at'] ),
    ];
  }

  // --- join requests ---------------------------------------------------------------------------

  /** Inserts a request: request_id, party_id, family_id, hero_id, card (array) and now (unix). */
  public function create_request( array $r ): void {
    $this->db->insert(
      $this->t( 'requests' ),
      [
        'request_id' => $r['request_id'],
        'party_id'   => $r['party_id'],
        'family_id'  => $r['family_id'],
        'hero_id'    => $r['hero_id'],
        'card'       => wp_json_encode( $r['card'] ),
        'created_at' => self::db_time( (int) $r['now'] ),
      ]
    );
  }

  /** A request by id (settled or not), or null. */
  public function request( string $request_id ): ?array {
    $row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->t( 'requests' )} WHERE request_id = %s", $request_id ), ARRAY_A );

    return $row ? $this->request_row( $row ) : null;
  }

  /** The open request of this hero for this party, or null. */
  public function open_request_for( string $party_id, string $family_id, string $hero_id ): ?array {
    $row = $this->db->get_row(
      $this->db->prepare(
        "SELECT * FROM {$this->t( 'requests' )} WHERE party_id = %s AND family_id = %s AND hero_id = %s AND resolved_at IS NULL",
        $party_id,
        $family_id,
        $hero_id
      ),
      ARRAY_A
    );

    return $row ? $this->request_row( $row ) : null;
  }

  /** The open requests for a party, oldest first. */
  public function open_requests_of_party( string $party_id ): array {
    $rows = $this->db->get_results(
      $this->db->prepare( "SELECT * FROM {$this->t( 'requests' )} WHERE party_id = %s AND resolved_at IS NULL ORDER BY created_at, request_id", $party_id ),
      ARRAY_A
    );

    return array_map( [ $this, 'request_row' ], (array) $rows );
  }

  /** The open requests a family made, oldest first. */
  public function open_requests_of_family( string $family_id ): array {
    $rows = $this->db->get_results(
      $this->db->prepare( "SELECT * FROM {$this->t( 'requests' )} WHERE family_id = %s AND resolved_at IS NULL ORDER BY created_at, request_id", $family_id ),
      ARRAY_A
    );

    return array_map( [ $this, 'request_row' ], (array) $rows );
  }

  /** Settles an open request as accepted or declined; whether it was open. */
  public function resolve_request( string $request_id, string $resolution, int $now ): bool {
    $changed = $this->db->query( $this->db->prepare(
      "UPDATE {$this->t( 'requests' )} SET resolved_at = %s, resolution = %s WHERE request_id = %s AND resolved_at IS NULL",
      self::db_time( $now ),
      $resolution,
      $request_id
    ) );

    return (int) $changed > 0;
  }

  private function request_row( array $row ): array {
    $card = json_decode( (string) $row['card'], true );

    return [
      'request_id'  => $row['request_id'],
      'party_id'    => $row['party_id'],
      'family_id'   => $row['family_id'],
      'hero_id'     => $row['hero_id'],
      'card'        => is_array( $card ) ? $card : [],
      'created_at'  => self::unix( $row['created_at'] ),
      'resolved_at' => self::unix( $row['resolved_at'] ),
      'resolution'  => $row['resolution'],
    ];
  }

  // --- events ----------------------------------------------------------------------------------

  /**
   * Appends an event to the party's feed and answers its number. The caller holds the party's lock (locked()), which
   * is what keeps the numbers gap-free and ordered: the counter is read, raised and written back under it.
   */
  public function append_event( string $party_id, string $kind, ?string $family_id, ?string $hero_id, array $data, int $now ): int {
    $seq = 1 + (int) $this->db->get_var( $this->db->prepare( "SELECT events_seq FROM {$this->parties_table()} WHERE party_id = %s", $party_id ) );

    $this->db->insert(
      $this->t( 'events' ),
      [
        'party_id'   => $party_id,
        'seq'        => $seq,
        'kind'       => $kind,
        'family_id'  => $family_id,
        'hero_id'    => $hero_id,
        'data'       => wp_json_encode( $data ),
        'created_at' => self::db_time( $now ),
      ]
    );
    $this->db->update( $this->parties_table(), [ 'events_seq' => $seq ], [ 'party_id' => $party_id ] );

    return $seq;
  }

  /** The events after `$since`, oldest first, at most `$limit`. */
  public function events_since( string $party_id, int $since, int $limit ): array {
    $rows = $this->db->get_results(
      $this->db->prepare(
        "SELECT * FROM {$this->t( 'events' )} WHERE party_id = %s AND seq > %d ORDER BY seq LIMIT %d",
        $party_id,
        $since,
        $limit
      ),
      ARRAY_A
    );

    return array_map(
      static function ( array $row ): array {
        $data = json_decode( (string) $row['data'], true );

        return [
          'seq'       => (int) $row['seq'],
          'kind'      => $row['kind'],
          'family_id' => $row['family_id'],
          'hero_id'   => $row['hero_id'],
          'data'      => is_array( $data ) ? $data : [],
          'at'        => self::unix( $row['created_at'] ),
        ];
      },
      (array) $rows
    );
  }

  // --- blocks ----------------------------------------------------------------------------------

  /** A family stops seeing a party: its heroes are out and may not join again. */
  public function block( string $family_id, string $party_id, int $now ): void {
    if ( $this->is_blocked( $family_id, $party_id ) ) {
      return;
    }
    $this->db->insert( $this->t( 'blocks' ), [ 'family_id' => $family_id, 'party_id' => $party_id, 'created_at' => self::db_time( $now ) ] );
  }

  public function is_blocked( string $family_id, string $party_id ): bool {
    return null !== $this->db->get_var( $this->db->prepare(
      "SELECT party_id FROM {$this->t( 'blocks' )} WHERE family_id = %s AND party_id = %s",
      $family_id,
      $party_id
    ) );
  }

  // --- raids and strikes -----------------------------------------------------------------------

  /** Inserts a raid: raid_id, party_id, beast_id, window, max_hp, member_count, started_at and ends_at (unix). */
  public function create_raid( array $r ): void {
    $this->db->insert(
      $this->t( 'raids' ),
      [
        'raid_id'      => $r['raid_id'],
        'party_id'     => $r['party_id'],
        'beast_id'     => $r['beast_id'],
        'raid_window'  => $r['window'],
        'max_hp'       => (int) $r['max_hp'],
        'member_count' => (int) $r['member_count'],
        'started_at'   => self::db_time( (int) $r['started_at'] ),
        'ends_at'      => self::db_time( (int) $r['ends_at'] ),
      ]
    );
  }

  /** A raid by id, or null. */
  public function raid( string $raid_id ): ?array {
    $row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->t( 'raids' )} WHERE raid_id = %s", $raid_id ), ARRAY_A );

    return $row ? $this->raid_row( $row ) : null;
  }

  /** The party's newest raid, or null. */
  public function latest_raid( string $party_id ): ?array {
    $row = $this->db->get_row(
      $this->db->prepare( "SELECT * FROM {$this->t( 'raids' )} WHERE party_id = %s ORDER BY started_at DESC, raid_id DESC LIMIT 1", $party_id ),
      ARRAY_A
    );

    return $row ? $this->raid_row( $row ) : null;
  }

  private function raid_row( array $row ): array {
    return [
      'raid_id'      => $row['raid_id'],
      'party_id'     => $row['party_id'],
      'beast_id'     => $row['beast_id'],
      'window'       => $row['raid_window'],
      'max_hp'       => (int) $row['max_hp'],
      'member_count' => (int) $row['member_count'],
      'started_at'   => self::unix( $row['started_at'] ),
      'ends_at'      => self::unix( $row['ends_at'] ),
    ];
  }

  /** A raid's counted strikes in the shape the rules read: strike_key, family_id, hero_id, member, damage, day, at. */
  public function strikes_of( string $raid_id ): array {
    $rows = $this->db->get_results(
      $this->db->prepare( "SELECT * FROM {$this->t( 'strikes' )} WHERE raid_id = %s ORDER BY struck_at, strike_key", $raid_id ),
      ARRAY_A
    );

    return array_map(
      static function ( array $row ): array {
        return [
          'strike_key' => $row['strike_key'],
          'family_id'  => $row['family_id'],
          'hero_id'    => $row['hero_id'],
          'member'     => $row['family_id'] . '|' . $row['hero_id'],
          'damage'     => (int) $row['damage'],
          'day'        => $row['day'],
          'at'         => (int) self::unix( $row['struck_at'] ),
        ];
      },
      (array) $rows
    );
  }

  /** Counts a strike: raid_id, strike_key, family_id, hero_id, damage, day, at (unix) and now (unix). */
  public function add_strike( array $s ): void {
    $this->db->insert(
      $this->t( 'strikes' ),
      [
        'raid_id'    => $s['raid_id'],
        'strike_key' => $s['strike_key'],
        'family_id'  => $s['family_id'],
        'hero_id'    => $s['hero_id'],
        'damage'     => (int) $s['damage'],
        'day'        => $s['day'],
        'struck_at'  => self::db_time( (int) $s['at'] ),
        'created_at' => self::db_time( (int) $s['now'] ),
      ]
    );
  }

  /** Takes a strike back (a chore that was undone); whether it was there. */
  public function remove_strike( string $raid_id, string $strike_key ): bool {
    $changed = $this->db->query( $this->db->prepare(
      "DELETE FROM {$this->t( 'strikes' )} WHERE raid_id = %s AND strike_key = %s",
      $raid_id,
      $strike_key
    ) );

    return (int) $changed > 0;
  }
}
