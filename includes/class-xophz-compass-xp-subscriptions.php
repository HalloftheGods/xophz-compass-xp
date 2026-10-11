<?php

/**
 * Stripe Subscriptions, Bazaar Buy Router Hooks, and Discount Codes for XP SaaS API.
 *
 * Provides hook-driven tier registration into Xophz Bazaar, single-click checkout URLs,
 * and extensible filter hooks for subscription resolution.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes
 */

class Xophz_Compass_Xp_Subscriptions {

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'rest_api_init' => 'register_subscription_routes',
  ];

  public $filter_hooks = [
    'xophz_resolve_buy_request' => [ 'resolve_xp_buy_request', 10, 3 ],
    'xophz_checkout_catalog'    => [ 'filter_checkout_catalog', 10, 1 ],
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /**
   * Register REST routes for subscriptions and checkout URLs.
   */
  public function register_subscription_routes() {
    register_rest_route( 'xp/v1', '/subscriptions/checkout', array(
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => array( $this, 'create_checkout_session' ),
      'permission_callback' => '__return_true',
    ) );

    register_rest_route( 'xp/v1', '/subscriptions/checkout-url', array(
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => array( $this, 'get_checkout_url_endpoint' ),
      'permission_callback' => '__return_true',
    ) );
  }

  /**
   * Pricing matrix definition for XP SaaS subscriptions.
   *
   * @return array
   */
  public static function get_pricing_matrix(): array {
    return array(
      'hobby' => array(
        'name'     => 'Developer Lite',
        'monthly'  => 19,
        'yearly'   => 180, // ~20% savings
        'requests' => '100,000 req/mo',
        'scopes'   => array( 'xp:read', 'xp:actions:trigger' ),
      ),
      'developer' => array(
        'name'     => 'Growth Engine',
        'monthly'  => 49,
        'yearly'   => 470, // ~20% savings
        'requests' => '1,000,000 req/mo',
        'scopes'   => array( 'xp:read', 'xp:write', 'xp:actions:trigger', 'xp:leaderboards' ),
      ),
      'growth' => array(
        'name'     => 'Growth Engine',
        'monthly'  => 49,
        'yearly'   => 470,
        'requests' => '1,000,000 req/mo',
        'scopes'   => array( 'xp:read', 'xp:write', 'xp:actions:trigger', 'xp:leaderboards' ),
      ),
      'enterprise' => array(
        'name'     => 'Enterprise Sovereign',
        'monthly'  => 199,
        'yearly'   => 1900,
        'requests' => 'Unlimited req/mo',
        'scopes'   => array( 'xp:read', 'xp:write', 'xp:actions:trigger', 'xp:leaderboards', 'xp:bank', 'xp:admin' ),
      ),
    );
  }

  /**
   * Hook into Bazaar's dynamic /buy/... request resolution.
   *
   * Catches `/buy/xp/{tier}` or `/buy/forthexp/{tier}` and provides the dynamic checkout definition.
   *
   * @param array|null $resolved
   * @param array      $segments Path segments (e.g. ['xp', 'developer']).
   * @param array      $query    Query string parameters ($_GET).
   * @return array|null
   */
  public function resolve_xp_buy_request( $resolved, array $segments, array $query ) {
    $first_seg = $segments[0] ?? '';
    if ( $first_seg !== 'xp' && $first_seg !== 'forthexp' ) {
      return $resolved;
    }

    $tier_slug = sanitize_key( $segments[1] ?? 'developer' );
    $pricing   = self::get_pricing_matrix();
    $tier      = $pricing[ $tier_slug ] ?? $pricing['developer'];
    $interval  = sanitize_key( $query['interval'] ?? 'monthly' );
    $coupon    = strtoupper( trim( sanitize_text_field( $query['coupon'] ?? '' ) ) );

    $base_price = ( $interval === 'yearly' ) ? $tier['yearly'] : $tier['monthly'];

    // Apply coupon discounts
    $discount = 0;
    if ( ! empty( $coupon ) ) {
      if ( in_array( $coupon, array( 'EARLYBIRD', 'EARLY25', 'FOUNDER25', 'COMPASS25' ), true ) ) {
        $discount = 25;
      } elseif ( in_array( $coupon, array( 'VIP50', 'EARLY50' ), true ) ) {
        $discount = 50;
      }
    }

    $final_price = $base_price;
    if ( $discount > 0 ) {
      $final_price = round( $base_price * ( 1 - ( $discount / 100 ) ), 2 );
    }

    $user_id     = get_current_user_id() ?: 0;
    $success_url = esc_url_raw( $query['return_url'] ?? home_url( '/#xp-api-keys?session_id={CHECKOUT_SESSION_ID}&tier=' . $tier_slug ) );
    $cancel_url  = esc_url_raw( home_url( '/#xp-settings?checkout=cancelled' ) );

    return array(
      'handled'     => true,
      'name'        => 'XP Gamification Core - ' . $tier['name'],
      'description' => $tier['requests'] . ' (' . ucfirst( $interval ) . ' Plan)',
      'price'       => $final_price,
      'mode'        => 'subscription',
      'interval'    => ( $interval === 'yearly' ) ? 'year' : 'month',
      'metadata'    => array(
        'product'      => 'forthexp',
        'tier'         => $tier_slug,
        'scopes'       => implode( ',', $tier['scopes'] ),
        'customer_uid' => (string) $user_id,
      ),
      'success_url' => $success_url,
      'cancel_url'  => $cancel_url,
    );
  }

  /**
   * Hook into Bazaar's static catalog filter to expose XP products.
   *
   * @param array $catalog
   * @return array
   */
  public function filter_checkout_catalog( array $catalog ): array {
    $pricing = self::get_pricing_matrix();

    $catalog['xp/hobby'] = array(
      'name'     => 'XP Gamification Core: Developer Lite',
      'price'    => $pricing['hobby']['monthly'],
      'mode'     => 'subscription',
      'interval' => 'month',
      'product'  => 'forthexp',
      'tier'     => 'hobby',
    );

    $catalog['xp/developer'] = array(
      'name'     => 'XP Gamification Core: Growth Engine',
      'price'    => $pricing['developer']['monthly'],
      'mode'     => 'subscription',
      'interval' => 'month',
      'product'  => 'forthexp',
      'tier'     => 'developer',
    );

    $catalog['xp/growth'] = array(
      'name'     => 'XP Gamification Core: Growth Engine',
      'price'    => $pricing['developer']['monthly'],
      'mode'     => 'subscription',
      'interval' => 'month',
      'product'  => 'forthexp',
      'tier'     => 'developer',
    );

    $catalog['xp/enterprise'] = array(
      'name'     => 'XP Gamification Core: Sovereign Enterprise',
      'price'    => $pricing['enterprise']['monthly'],
      'mode'     => 'subscription',
      'interval' => 'month',
      'product'  => 'forthexp',
      'tier'     => 'enterprise',
    );

    return $catalog;
  }

  /**
   * Get dynamic checkout link for a tier via filters.
   *
   * @param string $tier     Tier slug ('hobby', 'developer', 'enterprise').
   * @param string $interval Billing interval ('monthly' or 'yearly').
   * @param array  $args     Additional URL parameters.
   * @return string
   */
  public static function get_checkout_url( string $tier = 'developer', string $interval = 'monthly', array $args = array() ): string {
    $default_url = home_url( "/buy/xp/{$tier}" );
    $query_params = array_merge(
      array(
        'interval' => $interval,
      ),
      $args
    );

    $default_url = add_query_arg( $query_params, $default_url );

    /**
     * Filter the subscription checkout URL.
     *
     * Enables companion plugins or remote SaaS hubs to override checkout destinations.
     */
    return apply_filters( 'xp_checkout_link', $default_url, $tier, $interval, $args );
  }

  /**
   * REST endpoint to resolve dynamic checkout link.
   */
  public function get_checkout_url_endpoint( WP_REST_Request $request ): WP_REST_Response {
    $tier     = sanitize_key( $request->get_param( 'tier' ) ?: 'developer' );
    $interval = sanitize_key( $request->get_param( 'interval' ) ?: 'monthly' );
    $coupon   = sanitize_text_field( $request->get_param( 'coupon' ) ?: '' );

    $args = array();
    if ( ! empty( $coupon ) ) {
      $args['coupon'] = $coupon;
    }

    $url = self::get_checkout_url( $tier, $interval, $args );

    return rest_ensure_response( array(
      'success'  => true,
      'tier'     => $tier,
      'interval' => $interval,
      'url'      => $url,
    ) );
  }

  /**
   * Create checkout session with extensible filter hook and Bazaar integration.
   */
  public function create_checkout_session( WP_REST_Request $request ) {
    $params   = $request->get_json_params() ?: array();
    $tier     = sanitize_text_field( $params['tier'] ?? $request->get_param( 'tier' ) ?: 'developer' );
    $interval = sanitize_text_field( $params['interval'] ?? $request->get_param( 'interval' ) ?: 'monthly' );
    $coupon   = strtoupper( trim( sanitize_text_field( $params['coupon'] ?? $request->get_param( 'coupon' ) ?: '' ) ) );
    // Anonymous callers stay user 0. They are never attributed to user 1 (the site owner).
    $user_id  = get_current_user_id();

    // 1. Allow external plugins first right of refusal via custom hook
    $custom_session = apply_filters( 'xp_subscription_checkout_session', null, $tier, $interval, $user_id, $coupon, $request );
    if ( ! empty( $custom_session ) ) {
      return rest_ensure_response( $custom_session );
    }

    // 2. Delegate to Bazaar Checkout Service when present
    if ( class_exists( 'Xophz_Bazaar_Checkout_Service' ) ) {
      $query_args = array(
        'interval' => $interval,
        'coupon'   => $coupon,
      );
      if ( ! empty( $params['success_url'] ) ) {
        $query_args['return_url'] = esc_url_raw( $params['success_url'] );
      }

      $bazaar_result = Xophz_Bazaar_Checkout_Service::process_buy_request(
        array( 'xp', $tier ),
        $query_args,
        'POST'
      );

      if ( ! is_wp_error( $bazaar_result ) && is_array( $bazaar_result ) && ! empty( $bazaar_result['url'] ) ) {
        return rest_ensure_response( $bazaar_result );
      }
    }

    // 3. Fallback: Local pricing calculation
    $pricing       = self::get_pricing_matrix();
    $selected_tier = $pricing[ $tier ] ?? $pricing['developer'];
    $base_price    = ( $interval === 'yearly' ) ? $selected_tier['yearly'] : $selected_tier['monthly'];

    $discount_percent = 0;
    if ( ! empty( $coupon ) ) {
      if ( in_array( $coupon, array( 'EARLYBIRD', 'EARLY25', 'FOUNDER25', 'COMPASS25' ), true ) ) {
        $discount_percent = 25;
      } elseif ( in_array( $coupon, array( 'VIP50', 'EARLY50' ), true ) ) {
        $discount_percent = 50;
      }
    }

    $final_price = $base_price * ( 1 - ( $discount_percent / 100 ) );
    $unit_cents  = intval( round( $final_price * 100 ) );

    $success_url = esc_url_raw( $params['success_url'] ?? home_url( '/?subscription=success' ) );
    $cancel_url  = esc_url_raw( $params['cancel_url'] ?? home_url( '/?subscription=cancelled' ) );

    $secret_key = $this->get_secret_key();

    // Fail closed. Without a real Stripe key there is no checkout, and this route never issues an API key:
    // a paid key comes from the Stripe webhook (Gatekeeper) after a completed payment.
    if ( '' === $secret_key || 0 === strpos( $secret_key, 'sk_test_Mock' ) ) {
      return new WP_Error(
        'payments_not_configured',
        'Payments are not configured on this site, so no checkout session was created.',
        array( 'status' => 503 )
      );
    }

    // Direct Stripe API invocation fallback
    $post_fields = array(
      'payment_method_types'  => array( 'card' ),
      'allow_promotion_codes' => 'true',
      'line_items'            => array(
        array(
          'price_data' => array(
            'currency'     => 'usd',
            'product_data' => array(
              'name'        => 'XP Gamification Core - ' . $selected_tier['name'],
              'description' => $selected_tier['requests'] . ' (' . ucfirst( $interval ) . ' Plan)',
            ),
            'unit_amount'  => $unit_cents,
            'recurring'    => array(
              'interval' => ( $interval === 'yearly' ) ? 'year' : 'month',
            ),
          ),
          'quantity'   => 1,
        ),
      ),
      'mode'                  => 'subscription',
      'success_url'           => $success_url,
      'cancel_url'            => $cancel_url,
    );

    $response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
      'headers' => array(
        'Authorization' => 'Bearer ' . $secret_key,
        'Content-Type'  => 'application/x-www-form-urlencoded',
      ),
      'body'    => http_build_query( $post_fields ),
    ) );

    if ( is_wp_error( $response ) ) {
      return new WP_Error( 'stripe_error', $response->get_error_message(), array( 'status' => 500 ) );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( isset( $body['error'] ) ) {
      return new WP_Error( 'stripe_api_error', $body['error']['message'], array( 'status' => 400 ) );
    }

    return rest_ensure_response( array(
      'id'               => $body['id'],
      'url'              => $body['url'],
      'is_mock'          => false,
      'final_price'      => $final_price,
      'discount_percent' => $discount_percent,
    ) );
  }

  /**
   * The Stripe secret key, resolved server-side through the WP Connectors API.
   *
   * @return string The key, or '' when the `stripe_secret_key` connector is unregistered or unset.
   */
  private function get_secret_key(): string {
    return self::resolve_connector_key( 'stripe_secret_key' );
  }

  /**
   * The one credential resolver for this plugin.
   *
   * Core exposes no public function that returns a key's value, so this follows core's own
   * precedence (environment variable, PHP constant, database option). Every name comes from
   * the connector's `authentication` definition; nothing is hard-coded here. The value is for
   * server-side use only: never echo it, log it or place it in a URL.
   *
   * @param string $connector_id Connector id, e.g. 'stripe_secret_key'.
   * @return string
   */
  private static function resolve_connector_key( string $connector_id ): string {
    if ( ! function_exists( 'wp_get_connector' ) || ! wp_is_connector_registered( $connector_id ) ) {
      return '';
    }

    $connector = wp_get_connector( $connector_id );
    $auth      = is_array( $connector ) ? ( $connector['authentication'] ?? array() ) : array();

    $env_name = (string) ( $auth['env_var_name'] ?? '' );
    $env      = '' === $env_name ? false : getenv( $env_name );
    if ( is_string( $env ) && '' !== trim( $env ) ) {
      return trim( $env );
    }

    $constant_name = (string) ( $auth['constant_name'] ?? '' );
    if ( '' !== $constant_name && defined( $constant_name ) && is_scalar( constant( $constant_name ) ) ) {
      $from_constant = trim( (string) constant( $constant_name ) );
      if ( '' !== $from_constant ) {
        return $from_constant;
      }
    }

    $setting_name = (string) ( $auth['setting_name'] ?? '' );
    $from_option  = '' === $setting_name ? '' : get_option( $setting_name, '' );
    return is_scalar( $from_option ) ? trim( (string) $from_option ) : '';
  }
}
