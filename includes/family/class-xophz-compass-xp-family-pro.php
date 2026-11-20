<?php

/**
 * The Pro gate of family sync for Do It For The XP: is this family entitled to XP Pro?
 *
 * The answer comes from the Gatekeeper plugin's license engine (one xp-pro record per family,
 * judged by its status and its end plus the grace), so the family routes and the home rule
 * ask one question in one place. A site without Gatekeeper has no licences: every family is a
 * free one. The xp_family_pro_entitled filter has the last word. The answer is kept for the
 * rest of the request, so a batch or a route that asks twice reads the licence once.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Pro {

  /** The filter that can force the answer on or off: ( bool $entitled, string $family_id ). */
  const FILTER = 'xp_family_pro_entitled';

  /** The product id of XP Pro in Gatekeeper's license registry. */
  const PRODUCT = 'xp-pro';

  /** Rehearsal only: while WP_DEBUG is on, a truthy value makes every family entitled. */
  const OVERRIDE_OPTION = 'xp_family_pro_override';

  /** Family id => the answer already given in this request. */
  private static $answers = [];

  /**
   * Whether the family is entitled to XP Pro at the time given.
   *
   * @param string $family_id The family.
   * @param int    $now       Unix time the licence is judged at.
   */
  public static function entitled( string $family_id, int $now ): bool {
    if ( isset( self::$answers[ $family_id ] ) ) {
      return self::$answers[ $family_id ];
    }

    $default = self::rehearsal_override() || self::licensed( $family_id, $now );

    self::$answers[ $family_id ] = (bool) apply_filters( self::FILTER, $default, $family_id );

    return self::$answers[ $family_id ];
  }

  /** Forget the answers given so far (the tests; a request never needs it). */
  public static function reset(): void {
    self::$answers = [];
  }

  /**
   * Whether Gatekeeper holds an active licence for the family, grace included. The engine's
   * gatekeeper_license_entitled() answers for the xp-pro product; a Gatekeeper from before the
   * license products still has the Gatekeeper_Xp_Pro_Licenses class, which answers the same way.
   */
  private static function licensed( string $family_id, int $now ): bool {
    if ( function_exists( 'gatekeeper_license_entitled' ) ) {
      return (bool) gatekeeper_license_entitled( self::PRODUCT, $family_id, $now );
    }

    if ( ! class_exists( 'Gatekeeper_Xp_Pro_Licenses' ) ) {
      return false;
    }

    $record = Gatekeeper_Xp_Pro_Licenses::get( $family_id );

    return is_array( $record ) && Gatekeeper_Xp_Pro_Licenses::is_entitled( $record, $now );
  }

  /**
   * The rehearsal network sets the option to try the Pro routes without a licence. A production
   * site runs with WP_DEBUG off, so the option is never read there.
   */
  private static function rehearsal_override(): bool {
    return defined( 'WP_DEBUG' ) && WP_DEBUG && (bool) get_option( self::OVERRIDE_OPTION, false );
  }
}
