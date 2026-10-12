<?php

/**
 * XP Pro for Do It For The XP: the plan catalog and the Bazaar buy resolver.
 *
 * Hooks xophz_resolve_buy_request for /buy/doitforthexp/{plan}, and fills Gatekeeper's
 * gatekeeper_xp_pro_plans filter, so neither plugin calls the other's classes. This is a
 * different thing from the admin-set _xp_is_pro user flag of the XP network: family
 * entitlement lives only in Gatekeeper's gk_xp_pro_* records.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes
 */

class Xophz_Compass_Xp_Pro {

  const PRODUCT = 'doitforthexp';

  private $plugin_name;
  private $version;

  public $filter_hooks = [
    'xophz_resolve_buy_request' => [ 'resolve_buy_request', 10, 3 ],
    'gatekeeper_xp_pro_plans'   => [ 'provide_plans', 10, 1 ],
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /**
   * The plans, keyed monthly, yearly, lifetime. Lifetime is sold only while the option
   * xp_pro_lifetime_on_sale is true (default true).
   *
   * @param bool|null $lifetime_on_sale Overrides the option when given.
   * @return array
   */
  public static function get_plans( $lifetime_on_sale = null ): array {
    if ( null === $lifetime_on_sale ) {
      $stored = get_option( 'xp_pro_lifetime_on_sale', true );
      // '', '0', 'false', 'off' and 'no' close the sale; anything unreadable leaves it open.
      $parsed = is_bool( $stored ) ? $stored : filter_var( $stored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
      $lifetime_on_sale = null === $parsed ? true : $parsed;
    }

    return [
      'monthly'  => [
        'price'            => 4.99,
        'mode'             => 'subscription',
        'interval'         => 'month',
        'trial_days'       => 7,
        'store_product_id' => 'xp_pro_monthly',
        'on_sale'          => true,
      ],
      'yearly'   => [
        'price'            => 39.99,
        'mode'             => 'subscription',
        'interval'         => 'year',
        'trial_days'       => 7,
        'store_product_id' => 'xp_pro_yearly',
        'on_sale'          => true,
      ],
      'lifetime' => [
        'price'            => 79.00,
        'mode'             => 'payment',
        'interval'         => null,
        'trial_days'       => 0,
        'store_product_id' => 'xp_pro_lifetime',
        'on_sale'          => (bool) $lifetime_on_sale,
      ],
    ];
  }

  /**
   * Gatekeeper reads the catalog through this filter.
   */
  public function provide_plans( array $plans ): array {
    return self::get_plans();
  }

  /**
   * Resolve /buy/doitforthexp/{plan}?family_id=...&device_id=...&return_url=... for Bazaar.
   * success_url and cancel_url stay unset: Bazaar builds them from return_url after checking
   * it against the trusted redirect domains.
   *
   * @param mixed $resolved  Earlier resolver result.
   * @param array $segments  Path segments after /buy/.
   * @param array $query     Query string parameters.
   * @return mixed The resolved checkout array, a WP_Error, or $resolved for other products.
   */
  public function resolve_buy_request( $resolved, array $segments, array $query ) {
    if ( ( $segments[0] ?? '' ) !== self::PRODUCT ) {
      return $resolved;
    }

    $plan_key = isset( $segments[1] ) && is_string( $segments[1] ) ? $segments[1] : '';
    $plans    = self::get_plans();
    $plan     = $plans[ $plan_key ] ?? null;
    if ( null === $plan || empty( $plan['on_sale'] ) ) {
      return new WP_Error( 'not_found', 'That XP Pro plan is not available.', [ 'status' => 404 ] );
    }

    $family_id = $query['family_id'] ?? null;
    $device_id = $query['device_id'] ?? null;
    $isValid   = is_string( $family_id ) && self::is_uuid4( $family_id )
      && is_string( $device_id ) && self::is_device_id( $device_id );
    if ( ! $isValid ) {
      return new WP_Error( 'invalid_input', 'Invalid family or device id.', [ 'status' => 400 ] );
    }

    return [
      'handled'      => true,
      'product_name' => 'Do It For The XP: XP Pro (' . ucfirst( $plan_key ) . ')',
      'price'        => $plan['price'],
      'mode'         => $plan['mode'],
      'interval'     => $plan['interval'],
      'trial_days'   => $plan['trial_days'],
      'metadata'     => [
        'product'     => self::PRODUCT,
        'plan'        => $plan_key,
        'family_id'   => $family_id,
        'device_id'   => $device_id,
        'plugin_slug' => self::PRODUCT,
      ],
    ];
  }

  /** Lowercase UUID v4, the family id the app generates. */
  private static function is_uuid4( string $value ): bool {
    return 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value );
  }

  /** 8 to 64 characters of letters, digits, underscore and dash. */
  private static function is_device_id( string $value ): bool {
    return 1 === preg_match( '/\A[A-Za-z0-9_-]{8,64}\z/', $value );
  }
}
