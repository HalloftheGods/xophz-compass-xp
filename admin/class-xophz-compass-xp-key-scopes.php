<?php

/**
 * Which scope an XP API key needs on each xp/v1 route, and the check that enforces it (#5992).
 *
 * Scopes apply to requests that Xophz_Compass_Xp_Keys::authenticate_api_key() authenticated with
 * an API key (X-API-Key or Authorization: Bearer). Signed-in cookie/nonce requests are not scoped:
 * they keep each route's own capability check. A key request also passes that capability check,
 * as the key's owner, and then needs the route's scope.
 *
 * Rules:
 * - ROUTES lists every xp/v1 route and method. A route or method missing from it refuses API keys
 *   (fail closed), so a new route stays closed to keys until it is mapped here.
 * - Scopes are compared in one normalised form, sanitize_key(), the form Gatekeeper stores
 *   ("xp:admin" is stored as "xpadmin").
 * - A key whose stored scopes include any value XP does not recognise is refused on every route,
 *   never treated as broader. A key with no scopes has none: Gatekeeper's "no scopes means every
 *   scope" does not apply to XP routes.
 * - admin:all, Gatekeeper's superscope, covers every XP scope, as Gatekeeper_Keys::validate_key()
 *   treats it. xp:admin does not imply the other XP scopes; the enterprise tier lists them all.
 * - xp:leaderboards is recognised (developer, growth and enterprise keys carry it) but no route
 *   needs it yet.
 * - HEAD is scoped as GET; PUT and PATCH as POST.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */

class Xophz_Compass_Xp_Key_Scopes {

  const READ         = 'xp:read';
  const WRITE        = 'xp:write';
  const TRIGGER      = 'xp:actions:trigger';
  const LEADERBOARDS = 'xp:leaderboards';
  const BANK         = 'xp:bank';
  const ADMIN        = 'xp:admin';

  /** Gatekeeper's superscope. */
  const SUPERSCOPE = 'admin:all';

  /** Route value: an API key cannot use the route at all (key management, billing). */
  const SESSION_ONLY = 'session';

  /**
   * Route pattern, exactly as registered => [ HTTP method => scope or SESSION_ONLY ].
   */
  const ROUTES = array(
    '/xp/v1'                                      => array( 'GET' => self::READ ),
    '/xp/v1/state'                                => array( 'GET' => self::READ ),
    '/xp/v1/logs'                                 => array( 'GET' => self::READ ),
    '/xp/v1/my-badges'                            => array( 'GET' => self::READ ),
    '/xp/v1/my-metrics'                           => array( 'GET' => self::READ ),
    '/xp/v1/badges'                               => array( 'GET' => self::READ ),
    '/xp/v1/goals'                                => array( 'GET' => self::READ, 'POST' => self::ADMIN ),
    '/xp/v1/goals/(?P<id>\d+)'                    => array( 'POST' => self::ADMIN ),
    '/xp/v1/simulate-decay'                       => array( 'POST' => self::WRITE ),
    '/xp/v1/spend-gp'                             => array( 'POST' => self::BANK ),
    '/xp/v1/transaction'                          => array( 'POST' => self::BANK ),
    '/xp/v1/pro'                                  => array( 'POST' => self::ADMIN ),
    '/xp/v1/fire/(?P<action_slug>[a-zA-Z0-9_-]+)' => array( 'GET' => self::TRIGGER, 'POST' => self::TRIGGER ),
    '/xp/v1/actions'                              => array( 'GET' => self::ADMIN ),
    '/xp/v1/actions(?:/(?P<id>\d+))?'             => array( 'POST' => self::ADMIN ),
    '/xp/v1/subscriptions/checkout-url'           => array( 'GET' => self::READ ),
    '/xp/v1/subscriptions/checkout'               => array( 'POST' => self::SESSION_ONLY ),
    '/xp/v1/keys'                                 => array( 'GET' => self::SESSION_ONLY, 'POST' => self::SESSION_ONLY ),
    '/xp/v1/keys/(?P<id>[a-zA-Z0-9_-]+)'          => array( 'DELETE' => self::SESSION_ONLY ),
  );

  /** The request authenticated by an API key in this PHP process, and that key's stored scopes. */
  private static $key_request = null;
  private static $key_scopes  = array();

  private $plugin_name;
  private $version;

  public $filter_hooks = [
    // Late, so a scope refusal is the final dispatch result whatever other filters returned.
    'rest_dispatch_request' => ['enforce', 999, 4],
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /** Record that $request was authenticated by an API key holding $scopes (stored form). */
  public static function bind( WP_REST_Request $request, array $scopes ): void {
    self::$key_request = $request;
    self::$key_scopes  = $scopes;
  }

  /** True when $request came in on an API key. */
  public static function is_key_request( $request ): bool {
    return null !== self::$key_request && $request === self::$key_request;
  }

  /** A scope in the one form scopes are compared in. */
  public static function normalise( $scope ): string {
    return Xophz_Compass_Xp_Key_Grants::scope_key( $scope );
  }

  /** Every scope XP recognises, normalised. */
  public static function recognised(): array {
    $scopes = array( self::READ, self::WRITE, self::TRIGGER, self::LEADERBOARDS, self::BANK, self::ADMIN, self::SUPERSCOPE );
    return array_map( array( __CLASS__, 'normalise' ), $scopes );
  }

  /** What a route and method need: a scope, SESSION_ONLY, or '' when the pair is not mapped. */
  public static function required_scope( string $route, string $method ): string {
    $method = strtoupper( $method );
    if ( 'HEAD' === $method ) {
      $method = 'GET';
    } elseif ( 'PUT' === $method || 'PATCH' === $method ) {
      $method = 'POST';
    }
    return (string) ( self::ROUTES[ $route ][ $method ] ?? '' );
  }

  /**
   * Whether a key holding $stored_scopes may call $route with $method.
   *
   * @return true|WP_Error 403 `xp_insufficient_scope`, or 403 `key_session_required`.
   */
  public static function check( array $stored_scopes, string $route, string $method ) {
    $required = self::required_scope( $route, $method );
    if ( self::SESSION_ONLY === $required ) {
      return new WP_Error( 'key_session_required', 'API keys cannot use this route. Sign in to use it.', array( 'status' => 403 ) );
    }
    if ( '' === $required ) {
      return self::insufficient( 'This route does not accept API keys.', '' );
    }

    $held = array_map( array( __CLASS__, 'normalise' ), $stored_scopes );
    if ( array_diff( $held, self::recognised() ) ) {
      return self::insufficient( 'This API key carries scopes XP does not recognise, so XP refuses it. Create a new key.', $required );
    }

    $covers = in_array( self::normalise( self::SUPERSCOPE ), $held, true ) || in_array( self::normalise( $required ), $held, true );
    return $covers ? true : self::insufficient( "This API key does not have the {$required} scope this route needs.", $required );
  }

  /**
   * rest_dispatch_request: refuse a key request whose key lacks the route's scope.
   *
   * Runs after the route's permission callback and before its callback.
   */
  public function enforce( $dispatch_result, $request, $route, $handler ) {
    if ( ! self::is_key_request( $request ) ) {
      return $dispatch_result;
    }
    $allowed = self::check( self::$key_scopes, (string) $route, (string) $request->get_method() );
    return is_wp_error( $allowed ) ? $allowed : $dispatch_result;
  }

  private static function insufficient( string $message, string $required ): WP_Error {
    return new WP_Error( 'xp_insufficient_scope', $message, array( 'status' => 403, 'required_scope' => $required ) );
  }
}
