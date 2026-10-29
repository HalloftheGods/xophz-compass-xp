<?php

/**
 * The party tables of Do It For The XP: the SQL, the install on a family site and the daily cleanup.
 *
 * Parties are made of heroes from several families, so these tables hold only what may cross a family: a hero's public
 * card, the party's own events and raids. Never a family's save, a chore name, a PIN or a photo. Like the family
 * tables they install only on the sites of the Town Hall's list, with the site's own prefix, and a stored version
 * decides whether dbDelta runs.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/party
 */

class Xophz_Compass_Xp_Party_Schema {

  /** The schema version. Raise it when a table changes; maybe_install then runs dbDelta once. */
  const VERSION      = '1';
  const OPTION       = 'xp_party_db_version';
  const CLEANUP_HOOK = 'xp_party_cleanup';

  /** Days a party event is kept, counted from when it was written. */
  const EVENT_DAYS   = 90;
  /** Days a settled join request is kept. */
  const REQUEST_DAYS = 30;
  /** Days a raid and its strikes are kept after the window closed. */
  const RAID_DAYS    = 90;
  /** Days the row of a member who left is kept. */
  const MEMBER_DAYS  = 30;

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'init'              => [ 'maybe_install', 5 ],
    'xp_party_cleanup'  => 'cleanup',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /**
   * The CREATE TABLE statements, in the form dbDelta reads (one column per line, two spaces after PRIMARY KEY).
   *
   * @param string $prefix  The site's table prefix.
   * @param string $charset The charset and collation clause.
   * @return array Table name => SQL.
   */
  public static function tables( string $prefix, string $charset ): array {
    $parties  = "{$prefix}xp_parties";
    $members  = "{$prefix}xp_party_members";
    $requests = "{$prefix}xp_party_requests";
    $events   = "{$prefix}xp_party_events";
    $raids    = "{$prefix}xp_party_raids";
    $strikes  = "{$prefix}xp_party_strikes";
    $blocks   = "{$prefix}xp_party_blocks";

    return [
      $parties  => "CREATE TABLE {$parties} (
  party_id char(36) NOT NULL,
  name varchar(40) NOT NULL,
  emblem varchar(24) NOT NULL,
  code char(8) NOT NULL,
  invite_rule varchar(8) NOT NULL DEFAULT 'leader',
  leader_family_id char(36) NOT NULL,
  leader_hero_id varchar(64) NOT NULL,
  events_seq bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  disbanded_at datetime DEFAULT NULL,
  PRIMARY KEY  (party_id),
  UNIQUE KEY code (code),
  KEY leader_family (leader_family_id)
) {$charset};",

      $members  => "CREATE TABLE {$members} (
  party_id char(36) NOT NULL,
  family_id char(36) NOT NULL,
  hero_id varchar(64) NOT NULL,
  card longtext NOT NULL,
  role varchar(8) NOT NULL DEFAULT 'member',
  joined_at datetime NOT NULL,
  left_at datetime DEFAULT NULL,
  PRIMARY KEY  (party_id,family_id,hero_id),
  KEY family_hero (family_id,hero_id)
) {$charset};",

      $requests => "CREATE TABLE {$requests} (
  request_id char(36) NOT NULL,
  party_id char(36) NOT NULL,
  family_id char(36) NOT NULL,
  hero_id varchar(64) NOT NULL,
  card longtext NOT NULL,
  created_at datetime NOT NULL,
  resolved_at datetime DEFAULT NULL,
  resolution varchar(8) DEFAULT NULL,
  PRIMARY KEY  (request_id),
  KEY party_open (party_id,resolved_at),
  KEY family_open (family_id,resolved_at)
) {$charset};",

      $events   => "CREATE TABLE {$events} (
  party_id char(36) NOT NULL,
  seq bigint(20) unsigned NOT NULL,
  kind varchar(16) NOT NULL,
  family_id char(36) DEFAULT NULL,
  hero_id varchar(64) DEFAULT NULL,
  data longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (party_id,seq),
  KEY created_at (created_at)
) {$charset};",

      $raids    => "CREATE TABLE {$raids} (
  raid_id char(36) NOT NULL,
  party_id char(36) NOT NULL,
  beast_id varchar(64) NOT NULL,
  raid_window varchar(8) NOT NULL,
  max_hp int(10) unsigned NOT NULL,
  member_count smallint(5) unsigned NOT NULL,
  started_at datetime NOT NULL,
  ends_at datetime NOT NULL,
  PRIMARY KEY  (raid_id),
  KEY party_started (party_id,started_at),
  KEY ends_at (ends_at)
) {$charset};",

      $strikes  => "CREATE TABLE {$strikes} (
  raid_id char(36) NOT NULL,
  strike_key varchar(80) COLLATE utf8mb4_bin NOT NULL,
  family_id char(36) NOT NULL,
  hero_id varchar(64) NOT NULL,
  damage smallint(5) unsigned NOT NULL,
  day char(10) NOT NULL,
  struck_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (raid_id,strike_key),
  KEY member_day (raid_id,family_id,hero_id,day)
) {$charset};",

      $blocks   => "CREATE TABLE {$blocks} (
  family_id char(36) NOT NULL,
  party_id char(36) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (family_id,party_id)
) {$charset};",
    ];
  }

  /**
   * Create or update the tables when the stored version differs, and make sure the daily cleanup is scheduled. Does
   * nothing off a family site.
   */
  public function maybe_install(): void {
    if ( ! Xophz_Compass_Xp_Family_Schema::is_family_site() ) {
      return;
    }

    if ( self::VERSION !== (string) get_option( self::OPTION, '' ) ) {
      global $wpdb;

      if ( ! function_exists( 'dbDelta' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
      }

      foreach ( self::tables( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
        dbDelta( $sql );
      }

      update_option( self::OPTION, self::VERSION );
    }

    if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
      wp_schedule_event( time() + 3600, 'daily', self::CLEANUP_HOOK );
    }
  }

  /**
   * Daily: delete party events written more than 90 days ago, settled join requests older than 30 days, the strikes
   * and then the raids that ended more than 90 days ago, and members who left more than 30 days ago. A party, its
   * current members and its running raids are never aged out.
   */
  public function cleanup(): void {
    if ( ! Xophz_Compass_Xp_Family_Schema::is_family_site() ) {
      return;
    }

    global $wpdb;
    $now = time();
    $at  = static function ( int $days ) use ( $now ): string {
      return gmdate( 'Y-m-d H:i:s', $now - $days * 86400 );
    };

    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->prefix}xp_party_events WHERE created_at < %s",
      $at( self::EVENT_DAYS )
    ) );

    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->prefix}xp_party_requests WHERE resolved_at IS NOT NULL AND resolved_at < %s",
      $at( self::REQUEST_DAYS )
    ) );

    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->prefix}xp_party_strikes WHERE raid_id IN (SELECT raid_id FROM {$wpdb->prefix}xp_party_raids WHERE ends_at < %s)",
      $at( self::RAID_DAYS )
    ) );

    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->prefix}xp_party_raids WHERE ends_at < %s",
      $at( self::RAID_DAYS )
    ) );

    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->prefix}xp_party_members WHERE left_at IS NOT NULL AND left_at < %s",
      $at( self::MEMBER_DAYS )
    ) );
  }
}
