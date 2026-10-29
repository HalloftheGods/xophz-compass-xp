<?php

/**
 * Who is calling a party route, in the terms parties use: a parent of a family, or a hero's own key.
 *
 * The family routes already know how to tell the two apart (a hero key header, or a signed-in member of the site), so
 * this asks them and only adds the family id to the answer: parties are made of heroes of several families, and every
 * party rule is about which family a caller speaks for.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/party
 */

class Xophz_Compass_Xp_Party_Identity {

  /** @var object Answers caller( WP_REST_Request ), as Xophz_Compass_Xp_Family_Rest does. */
  private $family_rest;

  /** @var object Answers family_of_user( int ), as Xophz_Compass_Xp_Family_Store does. */
  private $family_store;

  public function __construct( $family_rest, $family_store ) {
    $this->family_rest  = $family_rest;
    $this->family_store = $family_store;
  }

  /**
   * @return array|WP_Error [ 'kind' => 'parent', 'user_id' => int, 'family_id' => string ],
   *                        [ 'kind' => 'hero', 'family_id' => string, 'hero_id' => string ], [ 'kind' => 'none' ],
   *                        or why the caller cannot be told (a revoked key, a user not on the site, no family yet).
   */
  public function caller( WP_REST_Request $r ) {
    $caller = $this->family_rest->caller( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    if ( 'hero' === $caller['kind'] ) {
      return [
        'kind'      => 'hero',
        'family_id' => (string) $caller['device']['family_id'],
        'hero_id'   => (string) $caller['device']['hero_id'],
      ];
    }

    if ( 'parent' === $caller['kind'] ) {
      $family = $this->family_store->family_of_user( (int) $caller['user_id'] );
      if ( null === $family ) {
        return new WP_Error( 'xp_family_no_family', 'This account has no family on the site yet.', [ 'status' => 404 ] );
      }

      return [ 'kind' => 'parent', 'user_id' => (int) $caller['user_id'], 'family_id' => (string) $family['family_id'] ];
    }

    return [ 'kind' => 'none' ];
  }
}
