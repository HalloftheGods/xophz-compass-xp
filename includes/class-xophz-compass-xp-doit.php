<?php

/**
 * Hosts the Do It For The XP frontend at a configurable permalink.
 *
 * The build is not shipped with XP: it is uploaded by hand to public/dist. Until
 * public/dist/index.html exists (or the dev server is in play), XP registers no
 * routes and the settings page only says where to upload it.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes
 */
class Xophz_Compass_Xp_Doit {

  const SLUG         = 'doit';
  const DEV_PORT     = 8100;
  const QUERY_VAR    = 'xophz_compass_doit';
  const OPTION_GROUP = 'xophz_compass_doit_options';
  const OPT_SLUG     = 'xophz_compass_doit_custom_slug';
  const OPT_MODE     = 'xophz_compass_doit_load_mode';
  const OPT_PAGE     = 'xophz_compass_doit_load_page_id';
  const OPT_MOUNTED  = 'xophz_compass_doit_mount_signature';
  const LOAD_MODES   = [ 'routes_only', 'homepage', 'specific_page' ];

  /**
   * First path segments a permalink may not take, or it would shadow WordPress itself.
   */
  const RESERVED_SEGMENTS = [ 'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'wp-login.php', 'xmlrpc.php', 'feed' ];

  public $action_hooks = [
    'init'       => [ 'sync_rewrites', 99 ],
    'admin_init' => 'register_settings',
    'admin_menu' => 'add_settings_page',
  ];

  public $filter_hooks = [
    'xophz_compass_dev_proxy_doit_html' => 'filter_html',
  ];

  private $plugin_name;
  private $version;
  private $dist_index;
  private $dist_url;
  private $available;

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
    $plugin_dir        = plugin_dir_path( dirname( __FILE__ ) );
    $plugin_url        = plugin_dir_url( dirname( __FILE__ ) );
    $this->dist_index  = $plugin_dir . 'public/dist/index.html';
    $this->dist_url    = wp_make_link_relative( $plugin_url ) . 'public/dist/';

    $dev_mode        = class_exists( 'Xophz_Compass_Dev_Proxy' ) && Xophz_Compass_Dev_Proxy::is_dev_mode();
    $this->available = self::is_available( $this->dist_index, $dev_mode );

    if ( $this->available && class_exists( 'Xophz_Compass_Dev_Proxy' ) ) {
      new Xophz_Compass_Dev_Proxy( [
        'slug'         => self::SLUG,
        'default_slug' => self::SLUG,
        'dev_port'     => self::DEV_PORT,
        'query_var'    => self::QUERY_VAR,
        'plugin_path'  => $plugin_dir,
        'plugin_url'   => $plugin_url,
        'version'      => $version,
      ] );
    }
  }

  /**
   * The build is mountable once its index.html is uploaded, or in dev mode, where the
   * proxy serves the dev server on :8100 (and says so when neither is there).
   */
  public static function is_available( string $dist_index, bool $dev_mode ): bool {
    return $dev_mode || file_exists( $dist_index );
  }

  /**
   * Lowercase a-z0-9 and dashes per path segment; nested paths (play/doit) are allowed.
   * Empty input, or a first segment WordPress owns, falls back to the default slug.
   */
  public static function sanitize_slug( $value ): string {
    $segments = [];
    foreach ( explode( '/', strtolower( (string) $value ) ) as $segment ) {
      $segment = trim( preg_replace( '/[^a-z0-9.]+/', '-', $segment ), '-.' );
      if ( '' !== $segment ) {
        $segments[] = $segment;
      }
    }

    if ( empty( $segments ) || in_array( $segments[0], self::RESERVED_SEGMENTS, true ) ) {
      return self::SLUG;
    }

    return implode( '/', $segments );
  }

  public static function sanitize_load_mode( $value ): string {
    return in_array( $value, self::LOAD_MODES, true ) ? $value : 'routes_only';
  }

  /**
   * Everything the rewrite rules depend on; when it changes, the rules are flushed.
   */
  public static function mount_signature( bool $available, string $slug, string $mode, int $page_id ): string {
    return $available ? md5( implode( '|', [ $slug, $mode, $page_id ] ) ) : 'off';
  }

  /**
   * Point the page at the build's files and tell webpack where its chunks live.
   *
   * The page is served at the permalink, but the files sit in public/dist, so relative
   * src/href attributes are made absolute and window.__XP_PUBLIC_PATH__ is set for the
   * app to hand to webpack. Dev server HTML already uses absolute URLs to :$dev_port;
   * only its origin is passed on.
   */
  public static function prepare_html( string $html, string $dist_url, int $dev_port ): string {
    if ( preg_match( '#(?:src|href)="(//[^/"]+:' . $dev_port . '/)#', $html, $match ) ) {
      return self::inject_public_path( $html, $match[1] );
    }

    $html = preg_replace(
      '#(\s(?:src|href)=")(?:\./)?(?![a-z][a-z0-9+.-]*:|/|\#)([^"]+)"#i',
      '$1' . $dist_url . '$2"',
      $html
    );

    return self::inject_public_path( $html, $dist_url );
  }

  private static function inject_public_path( string $html, string $public_path ): string {
    $script = '<script>window.__XP_PUBLIC_PATH__=' . json_encode( $public_path, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . ';</script>';
    return preg_replace( '#<head[^>]*>#i', '$0' . $script, $html, 1 );
  }

  /**
   * The page carries a REST nonce and must reflect an upload at once, so page caches
   * (Hummingbird and friends honour DONOTCACHEPAGE) must not keep it.
   */
  public function filter_html( $html ) {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
      define( 'DONOTCACHEPAGE', true );
    }
    return self::prepare_html( (string) $html, $this->dist_url, self::DEV_PORT );
  }

  /**
   * Flush rewrite rules once whenever the build appears or disappears or the mount
   * settings change. Runs after the proxy registered its rules on init, so the flush
   * writes the current ones.
   */
  public function sync_rewrites() {
    $signature = self::mount_signature(
      $this->available,
      self::sanitize_slug( get_option( self::OPT_SLUG, self::SLUG ) ),
      self::sanitize_load_mode( get_option( self::OPT_MODE, 'routes_only' ) ),
      (int) get_option( self::OPT_PAGE, 0 )
    );

    if ( get_option( self::OPT_MOUNTED ) !== $signature ) {
      flush_rewrite_rules();
      update_option( self::OPT_MOUNTED, $signature );
    }
  }

  public function register_settings() {
    register_setting( self::OPTION_GROUP, self::OPT_SLUG, [
      'type'              => 'string',
      'default'           => self::SLUG,
      'sanitize_callback' => [ self::class, 'sanitize_slug' ],
    ] );
    register_setting( self::OPTION_GROUP, self::OPT_MODE, [
      'type'              => 'string',
      'default'           => 'routes_only',
      'sanitize_callback' => [ self::class, 'sanitize_load_mode' ],
    ] );
    register_setting( self::OPTION_GROUP, self::OPT_PAGE, [
      'type'              => 'integer',
      'default'           => 0,
      'sanitize_callback' => 'absint',
    ] );
  }

  public function add_settings_page() {
    add_options_page(
      __( 'Do It For The XP', 'xophz-compass-xp' ),
      __( 'Do It For The XP', 'xophz-compass-xp' ),
      'manage_options',
      'xophz-compass-xp-doit',
      [ $this, 'render_settings_page' ]
    );
  }

  public function render_settings_page() {
    $available   = $this->available;
    $has_build   = file_exists( $this->dist_index );
    $upload_path = 'wp-content/plugins/' . basename( dirname( __DIR__ ) ) . '/public/dist/';
    $slug        = self::sanitize_slug( get_option( self::OPT_SLUG, self::SLUG ) );
    $load_mode   = self::sanitize_load_mode( get_option( self::OPT_MODE, 'routes_only' ) );
    $page_id     = (int) get_option( self::OPT_PAGE, 0 );
    $app_url     = home_url( '/' . $slug . '/' );
    require plugin_dir_path( dirname( __FILE__ ) ) . 'admin/partials/doit-mount-settings.php';
  }
}
