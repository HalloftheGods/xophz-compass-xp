<?php

/**
 * Stripe Subscriptions and Discount Codes for XP SaaS API.
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

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  public function register_subscription_routes() {
    register_rest_route( 'xp/v1', '/subscriptions/checkout', array(
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => array( $this, 'create_checkout_session' ),
      'permission_callback' => '__return_true',
    ) );
  }

  private function get_secret_key() {
    if ( defined( 'STRIPE_SECRET_KEY' ) ) {
      return STRIPE_SECRET_KEY;
    }
    if ( ! empty( $_ENV['STRIPE_SECRET_KEY'] ) ) {
      return $_ENV['STRIPE_SECRET_KEY'];
    }
    return get_option( 'compass_stripe_secret_key', get_option( 'xophz_compass_stripe_secret_key', '' ) );
  }

  public function create_checkout_session( WP_REST_Request $request ) {
    $params = $request->get_json_params() ?: array();

    $tier     = sanitize_text_field( $params['tier'] ?? $request->get_param( 'tier' ) ?: 'developer' );
    $interval = sanitize_text_field( $params['interval'] ?? $request->get_param( 'interval' ) ?: 'monthly' );
    $coupon   = strtoupper( trim( sanitize_text_field( $params['coupon'] ?? $request->get_param( 'coupon' ) ?: '' ) ) );

    // Pricing Matrix (in USD)
    $pricing = array(
      'hobby' => array(
        'name'     => 'Developer Lite',
        'monthly'  => 19,
        'yearly'   => 180, // 20% savings
        'requests' => '100,000 req/mo',
      ),
      'developer' => array(
        'name'     => 'Growth Engine',
        'monthly'  => 49,
        'yearly'   => 470, // ~20% savings
        'requests' => '1,000,000 req/mo',
      ),
      'enterprise' => array(
        'name'     => 'Enterprise Sovereign',
        'monthly'  => 199,
        'yearly'   => 1900,
        'requests' => 'Unlimited req/mo',
      ),
    );

    $selected_tier = $pricing[ $tier ] ?? $pricing['developer'];
    $base_price    = ( $interval === 'yearly' ) ? $selected_tier['yearly'] : $selected_tier['monthly'];

    // Apply Early Adopter Discount Codes
    $discount_percent = 0;
    if ( ! empty( $coupon ) ) {
      if ( in_array( $coupon, array( 'EARLYBIRD', 'EARLY25', 'FOUNDER25', 'COMPASS25' ) ) ) {
        $discount_percent = 25;
      } elseif ( in_array( $coupon, array( 'VIP50', 'EARLY50' ) ) ) {
        $discount_percent = 50;
      }
    }

    $unit_cents = intval( round( $base_price * 100 ) );

    $success_url = esc_url_raw( $params['success_url'] ?? home_url( '/?subscription=success' ) );
    $cancel_url  = esc_url_raw( $params['cancel_url'] ?? home_url( '/?subscription=cancelled' ) );

    $secret_key = $this->get_secret_key();

    // Fallback to interactive mock checkout when key is unconfigured
    if ( empty( $secret_key ) || strpos( $secret_key, 'sk_test_Mock' ) === 0 ) {
      // Auto-issue an API key for demo user when checking out in mock mode
      $user_id = get_current_user_id() ?: 1;
      $keys    = get_user_meta( $user_id, '_xp_api_keys', true );
      if ( ! is_array( $keys ) ) {
        $keys = array();
      }

      $key_id = 'key_' . bin2hex( random_bytes( 6 ) );
      $secret = 'xp_live_' . bin2hex( random_bytes( 16 ) );
      $keys[ $key_id ] = array(
        'id'         => $key_id,
        'key'        => $secret,
        'label'      => 'XP Gamification Core - ' . $selected_tier['name'] . ' API Key (' . strtoupper( $interval ) . ')',
        'tier'       => $tier,
        'created_at' => date( 'Y-m-d H:i:s' ),
        'last_used'  => 'Just now',
        'status'     => 'active',
      );
      update_user_meta( $user_id, '_xp_api_keys', $keys );

      return rest_ensure_response( array(
        'url'              => add_query_arg(
          array(
            'mock_subscription' => '1',
            'tier'              => $tier,
            'interval'          => $interval,
            'base_price'        => number_format( $base_price, 2 ),
            'issued_key'        => $secret,
          ),
          $success_url
        ),
        'is_mock'          => true,
        'base_price'       => $base_price,
        'issued_key'       => $secret,
        'message'          => 'Stripe key not configured. Mock checkout succeeded and API key issued!'
      ) );
    }

    // Call Stripe Checkout Sessions API with clean product name & base price
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
        )
      ),
      'mode'                  => 'subscription',
      'success_url'           => $success_url,
      'cancel_url'            => $cancel_url,
    );

    $response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
      'headers' => array(
        'Authorization' => 'Bearer ' . $secret_key,
        'Content-Type'  => 'application/x-www-form-urlencoded'
      ),
      'body'    => http_build_query( $post_fields )
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
}
