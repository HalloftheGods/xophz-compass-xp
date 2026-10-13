<?php

/**
 * The family routes of Do It For The XP (xp-family/v1): binding a family to its account,
 * its heroes, kid phones and the event stream.
 *
 * Registered only on the family sites (the Town Hall's list, filter xp_town_hall_site_urls),
 * so the plugin stays inert on every other site of the network. The xp/v1 key filter never
 * sees these routes. Every handler first works out who is calling (a parent, a hero key or
 * nobody) and asks Xophz_Compass_Xp_Family_Rules::may before it touches the store.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Rest {

  const NAMESPACE = 'xp-family/v1';

  private $plugin_name;
  private $version;

  /** @var Xophz_Compass_Xp_Family_Store|null */
  private $store;

  public $action_hooks = [
    'rest_api_init' => 'register_routes',
  ];

  public function __construct( $plugin_name, $version, $store = null ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
    $this->store       = $store;
  }

  public function store(): Xophz_Compass_Xp_Family_Store {
    if ( null === $this->store ) {
      $this->store = new Xophz_Compass_Xp_Family_Store();
    }

    return $this->store;
  }

  /** The routes, on a family site only. */
  public function register_routes(): void {
    if ( ! Xophz_Compass_Xp_Family_Schema::is_family_site() ) {
      return;
    }

    $hero = '(?P<hero_id>[A-Za-z0-9_-]{1,64})';

    register_rest_route( self::NAMESPACE, '/family', [
      [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'bind' ], 'permission_callback' => '__return_true' ],
      [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'read_family' ], 'permission_callback' => '__return_true' ],
    ] );

    register_rest_route( self::NAMESPACE, '/family/move', [
      [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'move' ], 'permission_callback' => '__return_true' ],
    ] );

    register_rest_route( self::NAMESPACE, '/heroes/' . $hero, [
      [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'save_hero' ], 'permission_callback' => '__return_true' ],
      [ 'methods' => WP_REST_Server::DELETABLE, 'callback' => [ $this, 'remove_hero' ], 'permission_callback' => '__return_true' ],
    ] );
  }

  // --- who is calling --------------------------------------------------------------------------

  /**
   * Who is calling.
   *
   * A signed-in WordPress user who is a member of this site is a parent; a signed-in user who
   * is not a member gets xp_family_not_member. Nobody signed in is none.
   *
   * @return array|WP_Error [ 'kind' => 'parent', 'user_id' => int ] or [ 'kind' => 'none' ].
   */
  public function caller( WP_REST_Request $r ) {
    $user_id = (int) get_current_user_id();
    if ( $user_id > 0 ) {
      if ( ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
        return self::error( 'xp_family_not_member', 'This account is not a member of the family site yet. Register the family first.', 403 );
      }
      return [ 'kind' => 'parent', 'user_id' => $user_id ];
    }

    return [ 'kind' => 'none' ];
  }

  /**
   * The caller, when it may take this action.
   *
   * @return array|WP_Error The caller (see caller()), or why not: 401 for nobody, 403 for a
   *                        caller who may not.
   */
  private function guard( WP_REST_Request $r, string $action ) {
    $caller = $this->caller( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    if ( Xophz_Compass_Xp_Family_Rules::may( $caller['kind'], $action ) ) {
      return $caller;
    }

    if ( 'none' === $caller['kind'] ) {
      return self::error( 'rest_not_logged_in', 'Sign in to the family site first.', 401 );
    }

    return self::error( 'xp_family_forbidden', 'This caller may not do that.', 403 );
  }

  /**
   * The caller's family: the one a parent owns.
   *
   * @return array|WP_Error The family row, or 404 xp_family_no_family.
   */
  private function family_for( array $caller ) {
    $family = 'parent' === $caller['kind'] ? $this->store()->family_of_user( (int) $caller['user_id'] ) : null;

    return null === $family
      ? self::error( 'xp_family_no_family', 'This account has no family on the site yet.', 404 )
      : $family;
  }

  // --- the family ------------------------------------------------------------------------------

  /** POST /family { family_id, device_id } -> { answer, family_id } */
  public function bind( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'bind' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $family_id = self::text( $r->get_param( 'family_id' ) );
    $device_id = self::text( $r->get_param( 'device_id' ) );
    if ( ! Xophz_Compass_Xp_Family_Rules::is_family_id( $family_id ) || ! Xophz_Compass_Xp_Family_Rules::is_device_id( $device_id ) ) {
      return self::error( 'xp_family_bad_request', 'A family id (a v4 UUID) and a device id (16 letters and digits) are needed.', 400 );
    }

    $user_id = (int) $caller['user_id'];
    $answer  = $this->bind_answer( $user_id, $family_id, $device_id );

    if ( 'created' === $answer ) {
      $this->store()->create_family( $family_id, $user_id, $device_id );
      // INSERT IGNORE: read again to learn whether this request (or an identical one) won.
      $answer = $this->bind_answer( $user_id, $family_id, $device_id );
      if ( 'home' === $answer ) {
        $answer = 'created';
      }
    }

    if ( 'taken' === $answer ) {
      return self::error( 'xp_family_taken', 'This family is registered to another account.', 403 );
    }

    $mine = $this->store()->family_of_user( $user_id );

    return [
      'answer'    => $answer,
      'family_id' => null === $mine ? $family_id : (string) $mine['family_id'],
    ];
  }

  /** POST /family/move { family_id, device_id } -> { ok: true } */
  public function move( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'move' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $family_id = self::text( $r->get_param( 'family_id' ) );
    $device_id = self::text( $r->get_param( 'device_id' ) );
    if ( ! Xophz_Compass_Xp_Family_Rules::is_family_id( $family_id ) || ! Xophz_Compass_Xp_Family_Rules::is_device_id( $device_id ) ) {
      return self::error( 'xp_family_bad_request', 'A family id (a v4 UUID) and a device id (16 letters and digits) are needed.', 400 );
    }

    $family = $this->family_for( $caller );
    if ( is_wp_error( $family ) ) {
      return $family;
    }
    if ( $family['family_id'] !== $family_id ) {
      return self::error( 'xp_family_forbidden', 'That is not the family of this account.', 403 );
    }

    $this->store()->move_home( $family_id, $device_id );

    return [ 'ok' => true ];
  }

  /** GET /family -> { family_id, heroes: [ { hero_id, nickname, age_group (parent only), card } ] } */
  public function read_family( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'read_family' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $family = $this->family_for( $caller );
    if ( is_wp_error( $family ) ) {
      return $family;
    }

    $heroes = [];
    foreach ( $this->store()->heroes( (string) $family['family_id'] ) as $row ) {
      $hero = [
        'hero_id'  => (string) $row['hero_id'],
        'nickname' => (string) $row['nickname'],
      ];
      if ( 'parent' === $caller['kind'] ) {
        $hero['age_group'] = (string) $row['age_group'];
      }
      $hero['card'] = self::card_wire( $row );
      $heroes[]     = $hero;
    }

    return [ 'family_id' => (string) $family['family_id'], 'heroes' => $heroes ];
  }

  // --- heroes ----------------------------------------------------------------------------------

  /** POST /heroes/{hero_id} { nickname, age_group } -> { ok: true } */
  public function save_hero( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'save_hero' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $family = $this->family_for( $caller );
    if ( is_wp_error( $family ) ) {
      return $family;
    }

    $hero_id   = self::text( $r->get_param( 'hero_id' ) );
    $nickname  = Xophz_Compass_Xp_Family_Rules::clean_nickname( $r->get_param( 'nickname' ) );
    $age_group = $r->get_param( 'age_group' );
    if ( ! Xophz_Compass_Xp_Family_Rules::is_hero_id( $hero_id ) || null === $nickname || ! Xophz_Compass_Xp_Family_Rules::is_age_group( $age_group ) ) {
      return self::error( 'xp_family_bad_request', 'A nickname of 1 to 40 characters and an age group (child, teen or adult) are needed.', 400 );
    }

    $this->store()->save_hero( (string) $family['family_id'], $hero_id, $nickname, $age_group );

    return [ 'ok' => true ];
  }

  /** DELETE /heroes/{hero_id} -> { ok: true } (also when the hero was not there) */
  public function remove_hero( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'remove_hero' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $family = $this->family_for( $caller );
    if ( is_wp_error( $family ) ) {
      return $family;
    }

    $hero_id = self::text( $r->get_param( 'hero_id' ) );
    if ( ! Xophz_Compass_Xp_Family_Rules::is_hero_id( $hero_id ) ) {
      return self::error( 'xp_family_bad_request', 'That is not a hero id.', 400 );
    }

    $this->store()->remove_hero( (string) $family['family_id'], $hero_id );

    return [ 'ok' => true ];
  }

  // --- helpers ---------------------------------------------------------------------------------

  /**
   * A hero's card as the wire carries it: the kept hero.card fields and updated_at (the ISO
   * time of card_at); null when the hero has no card yet.
   */
  public static function card_wire( array $hero_row ): ?array {
    $raw = $hero_row['card'] ?? null;
    if ( ! is_string( $raw ) || '' === $raw ) {
      return null;
    }

    $card = json_decode( $raw, true );
    if ( ! is_array( $card ) || [] === $card ) {
      return null;
    }

    $card['updated_at'] = Xophz_Compass_Xp_Family_Store::iso( $hero_row['card_at'] ?? null );

    return $card;
  }

  /** What the binding of this account, family and device comes to now. */
  private function bind_answer( int $user_id, string $family_id, string $device_id ): string {
    return Xophz_Compass_Xp_Family_Rules::bind_answer(
      $this->store()->family_of_user( $user_id ),
      $this->store()->family( $family_id ),
      $user_id,
      $family_id,
      $device_id
    );
  }

  /** @param mixed $v A string param, or '' for anything else. */
  private static function text( $v ): string {
    return is_string( $v ) ? $v : '';
  }

  private static function error( string $code, string $message, int $status ): WP_Error {
    return new WP_Error( $code, $message, [ 'status' => $status ] );
  }
}
