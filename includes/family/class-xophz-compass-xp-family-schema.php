<?php

/**
 * The family tables of Do It For The XP: the SQL, the install on a family site and the
 * daily cleanup.
 *
 * Installs only on the sites of the Town Hall's list (filter xp_town_hall_site_urls), so
 * the plugin stays inert on every other site of the network. Tables are per site, with
 * the site's own prefix, and a stored version decides whether dbDelta runs.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Schema {

  /** The schema version. Raise it when a table changes; maybe_install then runs dbDelta once. */
  const VERSION      = '1';
  const OPTION       = 'xp_family_db_version';
  const CLEANUP_HOOK = 'xp_family_cleanup';

  /** The family sites when the Town Hall class does not say. */
  const DEFAULT_SITE_URLS = [ 'https://doit.forthexp.com' ];

  /** Days an event is kept, counted from when the site received it. */
  const EVENT_DAYS  = 90;
  /** Days a revoked phone's row is kept. */
  const DEVICE_DAYS = 30;

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'init'              => [ 'maybe_install', 5 ],
    'xp_family_cleanup' => 'cleanup',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /**
   * The CREATE TABLE statements, in the form dbDelta reads (one column per line, two spaces
   * after PRIMARY KEY).
   *
   * @param string $prefix  The site's table prefix.
   * @param string $charset The charset and collation clause.
   * @return array Table name => SQL.
   */
  public static function tables( string $prefix, string $charset ): array {
    $families = "{$prefix}xp_families";
    $heroes   = "{$prefix}xp_family_heroes";
    $devices  = "{$prefix}xp_family_devices";
    $codes    = "{$prefix}xp_family_link_codes";
    $events   = "{$prefix}xp_family_events";

    return [
      $families => "CREATE TABLE {$families} (
  family_id char(36) NOT NULL,
  owner_user_id bigint(20) unsigned NOT NULL,
  home_device_id varchar(16) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (family_id),
  UNIQUE KEY owner_user_id (owner_user_id)
) {$charset};",

      $heroes   => "CREATE TABLE {$heroes} (
  family_id char(36) NOT NULL,
  hero_id varchar(64) NOT NULL,
  nickname varchar(40) NOT NULL,
  age_group varchar(8) NOT NULL,
  card longtext,
  card_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  removed_at datetime DEFAULT NULL,
  PRIMARY KEY  (family_id,hero_id)
) {$charset};",

      $devices  => "CREATE TABLE {$devices} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  family_id char(36) NOT NULL,
  hero_id varchar(64) NOT NULL,
  device_id varchar(16) NOT NULL,
  label varchar(60) NOT NULL,
  key_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  last_seen_at datetime DEFAULT NULL,
  revoked_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY key_hash (key_hash),
  KEY family_hero (family_id,hero_id)
) {$charset};",

      $codes    => "CREATE TABLE {$codes} (
  code_hash char(64) NOT NULL,
  family_id char(36) NOT NULL,
  hero_id varchar(64) NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  PRIMARY KEY  (code_hash),
  KEY family_hero (family_id,hero_id),
  KEY expires_at (expires_at)
) {$charset};",

      $events   => "CREATE TABLE {$events} (
  seq bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id char(36) NOT NULL,
  family_id char(36) NOT NULL,
  hero_id varchar(64) NOT NULL,
  device_id varchar(16) NOT NULL,
  kind varchar(40) NOT NULL,
  scope varchar(8) NOT NULL,
  occurred_at datetime NOT NULL,
  received_at datetime NOT NULL,
  data longtext NOT NULL,
  PRIMARY KEY  (seq),
  UNIQUE KEY event_id (event_id),
  KEY family_seq (family_id,seq),
  KEY received_at (received_at)
) {$charset};",
    ];
  }

  /**
   * The addresses of the family sites: the Town Hall's list, which a filter may change.
   * Before the Town Hall class exists, the default is doit.forthexp.com.
   */
  public static function site_urls(): array {
    $default = defined( 'Xophz_Compass_Xp_Town_Hall::DEFAULT_SITE_URLS' )
      ? constant( 'Xophz_Compass_Xp_Town_Hall::DEFAULT_SITE_URLS' )
      : self::DEFAULT_SITE_URLS;
    if ( ! is_array( $default ) ) {
      $default = self::DEFAULT_SITE_URLS;
    }

    $urls = apply_filters( 'xp_town_hall_site_urls', $default );

    return is_array( $urls ) ? $urls : $default;
  }

  /** Whether the current site is one of the family sites. */
  public static function is_family_site(): bool {
    return Xophz_Compass_Xp_Family_Rules::is_family_site( home_url(), self::site_urls() );
  }

  /**
   * Create or update the tables when the stored version differs, and make sure the daily
   * cleanup is scheduled. Does nothing off a family site.
   */
  public function maybe_install(): void {
    if ( ! self::is_family_site() ) {
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
   * Daily: delete events received more than 90 days ago, link codes past their expiry and
   * devices revoked more than 30 days ago.
   */
  public function cleanup(): void {
    if ( ! self::is_family_site() ) {
      return;
    }

    global $wpdb;
    $now = time();

    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->prefix}xp_family_events WHERE received_at < %s",
      gmdate( 'Y-m-d H:i:s', $now - self::EVENT_DAYS * 86400 )
    ) );

    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->prefix}xp_family_link_codes WHERE expires_at < %s",
      gmdate( 'Y-m-d H:i:s', $now )
    ) );

    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->prefix}xp_family_devices WHERE revoked_at IS NOT NULL AND revoked_at < %s",
      gmdate( 'Y-m-d H:i:s', $now - self::DEVICE_DAYS * 86400 )
    ) );
  }
}
