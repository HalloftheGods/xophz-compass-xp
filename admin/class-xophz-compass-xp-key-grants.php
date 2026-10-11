<?php

/**
 * Who may mint which XP API key (#5982).
 *
 * A signed-in user gets keys only for themselves, with only the scopes of the tier they paid
 * for. The xp:admin scope, any other tier and keys for other users need manage_options. A scope
 * outside the caller's plan is refused with a 403, never silently granted or dropped.
 *
 * The paid tier comes only from the paid path: the tier Gatekeeper's Stripe fulfilment reports
 * through `xp_subscription_activated` (stored in user meta), or, for subscriptions fulfilled
 * before that was recorded, the label Gatekeeper gives the paid key.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */

class Xophz_Compass_Xp_Key_Grants {

  /** Capability for xp:admin keys, other tiers and other users' keys. */
  const ADMIN_CAPABILITY = 'manage_options';

  /** Scope only an administrator may put on a key. */
  const ADMIN_SCOPE = 'xp:admin';

  /** User meta: the highest tier the user has paid for (written by the paid fulfilment path). */
  const TIER_META = '_xp_subscription_tier';

  /** Label Gatekeeper gives a key it mints after a paid checkout: "forthexp.com SaaS Key (<Tier>)". */
  const PAID_KEY_LABEL_PREFIX = 'forthexp.com SaaS Key (';

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'xp_subscription_activated' => ['record_subscription_tier', 10, 2],
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /**
   * Gatekeeper is the key authority when it is active.
   *
   * The `xophz_xp_keys_use_gatekeeper` filter can return false to keep keys in the local
   * user-meta store even while Gatekeeper is active.
   */
  public static function gatekeeper_active(): bool {
    return class_exists( 'Gatekeeper_Keys' ) && (bool) apply_filters( 'xophz_xp_keys_use_gatekeeper', true );
  }

  /**
   * A scope in the form Gatekeeper stores and compares it: sanitize_key(), so "xp:admin"
   * and "xpadmin" are the same scope.
   */
  public static function scope_key( $scope ): string {
    return is_scalar( $scope ) ? sanitize_key( (string) $scope ) : '';
  }

  /** The pricing matrix: tier slug => [ 'scopes' => string[], ... ]. */
  private static function pricing(): array {
    return class_exists( 'Xophz_Compass_Xp_Subscriptions' ) ? Xophz_Compass_Xp_Subscriptions::get_pricing_matrix() : array();
  }

  /**
   * A tier slug as the paid path would fulfil it. Bazaar charges developer pricing for an
   * unknown slug, so an unknown slug maps to 'developer'; '' stays ''.
   */
  private static function normalize_tier( string $tier ): string {
    $tier = sanitize_key( $tier );
    if ( '' === $tier ) {
      return '';
    }
    return isset( self::pricing()[ $tier ] ) ? $tier : 'developer';
  }

  /** Rank of a tier by how many scopes it grants (0 for none or unknown). */
  private static function tier_rank( string $tier ): int {
    $pricing = self::pricing();
    return isset( $pricing[ $tier ] ) ? count( (array) $pricing[ $tier ]['scopes'] ) : 0;
  }

  /** Tiers that grant the same scopes (developer and growth) are the same tier. */
  private static function same_tier( string $a, string $b ): bool {
    $pricing = self::pricing();
    if ( ! isset( $pricing[ $a ], $pricing[ $b ] ) ) {
      return false;
    }
    $scopes_a = (array) $pricing[ $a ]['scopes'];
    $scopes_b = (array) $pricing[ $b ]['scopes'];
    sort( $scopes_a );
    sort( $scopes_b );
    return $scopes_a === $scopes_b;
  }

  /**
   * Record a paid tier when Gatekeeper fulfils an XP subscription.
   *
   * Hooked to `xp_subscription_activated`, which only Gatekeeper's paid checkout fulfilment
   * fires. Keeps the highest tier: cancellations are not tracked (Gatekeeper does not handle them).
   */
  public function record_subscription_tier( $user_id, $tier ) {
    $user_id = absint( $user_id );
    $tier    = self::normalize_tier( (string) $tier );
    if ( $user_id <= 0 || '' === $tier ) {
      return;
    }
    $current = self::normalize_tier( (string) get_user_meta( $user_id, self::TIER_META, true ) );
    if ( self::tier_rank( $tier ) > self::tier_rank( $current ) ) {
      update_user_meta( $user_id, self::TIER_META, $tier );
    }
  }

  /**
   * The highest tier a user has paid for, or '' when none.
   *
   * Counts the tier recorded by record_subscription_tier() and Gatekeeper keys labelled by the
   * paid path (any status, so rotating the paid key keeps the entitlement). Keys minted by the
   * removed mock checkout ("XP SaaS Key - ...") and keys created through /xp/v1/keys never count:
   * resolve() refuses the paid label to non-admins.
   */
  public static function get_entitled_tier( int $user_id ): string {
    if ( $user_id <= 0 ) {
      return '';
    }

    $best = self::normalize_tier( (string) get_user_meta( $user_id, self::TIER_META, true ) );

    if ( self::gatekeeper_active() ) {
      $prefix_length = strlen( self::PAID_KEY_LABEL_PREFIX );
      foreach ( Gatekeeper_Keys::list_keys() as $k ) {
        $name          = (string) ( $k['name'] ?? '' );
        $is_paid_label = 0 === strpos( $name, self::PAID_KEY_LABEL_PREFIX ) && ')' === substr( $name, -1 );
        if ( (int) ( $k['user_id'] ?? 0 ) !== $user_id || ! $is_paid_label ) {
          continue;
        }
        $tier = self::normalize_tier( substr( $name, $prefix_length, -1 ) );
        if ( self::tier_rank( $tier ) > self::tier_rank( $best ) ) {
          $best = $tier;
        }
      }
    }

    return $best;
  }

  /**
   * Work out the owner, label, tier and scopes of a key the current user may create.
   *
   * @return array|WP_Error [ 'user_id' => int, 'label' => string, 'tier' => string, 'scopes' => string[] ]
   */
  public static function resolve( WP_REST_Request $request ) {
    $caller_id = get_current_user_id();
    $is_admin  = current_user_can( self::ADMIN_CAPABILITY );
    $pricing   = self::pricing();

    $label = sanitize_text_field( (string) ( $request->get_param( 'label' ) ?: 'Production Key' ) );
    if ( ! $is_admin && 0 === stripos( $label, self::PAID_KEY_LABEL_PREFIX ) ) {
      return new WP_Error( 'reserved_label', 'That label is reserved for keys issued by a paid checkout.', array( 'status' => 400 ) );
    }

    $user_id = absint( $request->get_param( 'user_id' ) ) ?: $caller_id;
    if ( $user_id !== $caller_id && ! $is_admin ) {
      return new WP_Error( 'forbidden_target', 'You can create API keys only for your own account.', array( 'status' => 403 ) );
    }
    if ( ! get_userdata( $user_id ) ) {
      return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
    }

    $raw_scopes = $request->get_param( 'scopes' );
    if ( null !== $raw_scopes && '' !== $raw_scopes && ! is_array( $raw_scopes ) ) {
      return new WP_Error( 'invalid_scopes', 'scopes must be an array of scope names.', array( 'status' => 400 ) );
    }
    $requested_scopes = array_values( array_unique( array_filter( array_map(
      function( $scope ) {
        return is_scalar( $scope ) ? strtolower( preg_replace( '/[^a-z0-9:_\-]/i', '', (string) $scope ) ) : '';
      },
      (array) $raw_scopes
    ) ) ) );

    $requested_tier = sanitize_key( (string) $request->get_param( 'tier' ) );
    $entitled       = $is_admin ? '' : self::get_entitled_tier( $user_id );

    if ( ! $is_admin && '' === $entitled ) {
      return new WP_Error( 'subscription_required', 'Creating an XP API key needs a paid XP plan.', array( 'status' => 403 ) );
    }

    $tier = '' !== $requested_tier ? $requested_tier : ( $is_admin ? 'developer' : $entitled );
    if ( ! isset( $pricing[ $tier ] ) ) {
      return new WP_Error( 'invalid_tier', 'Unknown tier.', array( 'status' => 400 ) );
    }

    $tier_scopes = (array) $pricing[ $tier ]['scopes'];

    if ( $is_admin ) {
      return array(
        'user_id' => $user_id,
        'label'   => $label,
        'tier'    => $tier,
        'scopes'  => array_values( array_unique( array_merge( $tier_scopes, $requested_scopes ) ) ),
      );
    }

    if ( ! self::same_tier( $tier, $entitled ) ) {
      return new WP_Error(
        'tier_not_allowed',
        'Your plan covers the ' . $entitled . ' tier. A key for another tier needs an administrator.',
        array( 'status' => 403 )
      );
    }

    // A subscriber gets the scopes of their tier, without xp:admin, which needs manage_options.
    // Compared in Gatekeeper's stored form, so "xpadmin" cannot stand in for "xp:admin".
    $allowed      = array_values( array_diff( $tier_scopes, array( self::ADMIN_SCOPE ) ) );
    $allowed_keys = array_map( array( __CLASS__, 'scope_key' ), $allowed );
    $refused      = array_values( array_filter( $requested_scopes, function( $scope ) use ( $allowed_keys ) {
      return ! in_array( self::scope_key( $scope ), $allowed_keys, true );
    } ) );
    if ( ! empty( $refused ) ) {
      return new WP_Error(
        'scope_not_allowed',
        'Your plan does not include these scopes: ' . implode( ', ', $refused ) . '.',
        array( 'status' => 403, 'refused_scopes' => $refused )
      );
    }

    return array(
      'user_id' => $user_id,
      'label'   => $label,
      'tier'    => $tier,
      'scopes'  => $allowed,
    );
  }
}
