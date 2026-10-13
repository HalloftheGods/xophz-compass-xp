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

  /** The header a kid phone sends its hero key in. */
  const KEY_HEADER = 'X-XP-Hero-Key';

  /** Transient name prefix of the failed-claim counter of one address. */
  const CLAIM_FAIL_PREFIX = 'xp_family_claim_fail_';
  const CLAIM_FAIL_WINDOW = 3600;

  /** Seconds between two last_seen_at updates of one phone. */
  const TOUCH_EVERY = 60;

  /** Events GET /events returns when the caller names no limit, and the most it returns. */
  const READ_LIMIT     = 100;
  const READ_LIMIT_MAX = 200;

  private $plugin_name;
  private $version;

  /** @var Xophz_Compass_Xp_Family_Store|null */
  private $store;

  public $action_hooks = [
    'rest_api_init' => 'register_routes',
  ];

  public $filter_hooks = [
    'rest_allowed_cors_headers' => 'allow_cors_headers',
    'rest_exposed_cors_headers' => 'expose_cors_headers',
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

    register_rest_route( self::NAMESPACE, '/heroes/' . $hero . '/link-codes', [
      [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'make_code' ], 'permission_callback' => '__return_true' ],
    ] );

    register_rest_route( self::NAMESPACE, '/devices/claim', [
      [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'claim' ], 'permission_callback' => '__return_true' ],
    ] );

    register_rest_route( self::NAMESPACE, '/devices', [
      [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'list_devices' ], 'permission_callback' => '__return_true' ],
    ] );

    register_rest_route( self::NAMESPACE, '/devices/(?P<id>\d+)', [
      [ 'methods' => WP_REST_Server::DELETABLE, 'callback' => [ $this, 'unlink_device' ], 'permission_callback' => '__return_true' ],
    ] );

    register_rest_route( self::NAMESPACE, '/events', [
      [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'send_events' ], 'permission_callback' => '__return_true' ],
      [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'read_events' ], 'permission_callback' => '__return_true' ],
    ] );
  }

  /**
   * CORS: the app on another origin sends the hero key header. Only on a family site.
   *
   * @param mixed $headers The allowed request headers.
   */
  public function allow_cors_headers( $headers ) {
    if ( is_array( $headers ) && Xophz_Compass_Xp_Family_Schema::is_family_site() && ! in_array( self::KEY_HEADER, $headers, true ) ) {
      $headers[] = self::KEY_HEADER;
    }

    return $headers;
  }

  /**
   * CORS: the app reads Retry-After from a 429. Only on a family site.
   *
   * @param mixed $headers The response headers a script may read.
   */
  public function expose_cors_headers( $headers ) {
    if ( is_array( $headers ) && Xophz_Compass_Xp_Family_Schema::is_family_site() && ! in_array( 'Retry-After', $headers, true ) ) {
      $headers[] = 'Retry-After';
    }

    return $headers;
  }

  // --- who is calling --------------------------------------------------------------------------

  /**
   * Who is calling.
   *
   * A request with a hero key (X-XP-Hero-Key) is that phone's hero, whatever else it carries:
   * the key is hashed and looked up among the linked phones; an unknown or revoked key gets
   * 401 xp_family_key_revoked. Otherwise a signed-in WordPress user who is a member of this
   * site is a parent, and a signed-in user who is not a member gets xp_family_not_member.
   * Nobody signed in is none.
   *
   * @return array|WP_Error [ 'kind' => 'parent', 'user_id' => int ], [ 'kind' => 'hero',
   *                        'device' => array ] or [ 'kind' => 'none' ].
   */
  public function caller( WP_REST_Request $r ) {
    $key = $r->get_header( self::KEY_HEADER );
    if ( is_string( $key ) && '' !== trim( $key ) ) {
      $key    = trim( $key );
      $device = Xophz_Compass_Xp_Family_Rules::is_key_shaped( $key )
        ? $this->store()->device_by_key_hash( Xophz_Compass_Xp_Family_Rules::hash_secret( $key ) )
        : null;
      if ( null === $device ) {
        return self::error( 'xp_family_key_revoked', 'This phone was unlinked. Ask your Game Master to link it again.', 401 );
      }

      $now  = time();
      $seen = Xophz_Compass_Xp_Family_Store::unix( $device['last_seen_at'] ?? null );
      if ( null === $seen || $seen <= $now - self::TOUCH_EVERY ) {
        $this->store()->touch_device( (int) $device['id'], $now );
      }

      return [ 'kind' => 'hero', 'device' => $device ];
    }

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
   * The caller's family: the one a parent owns, or the family of a hero key's phone.
   *
   * @return array|WP_Error The family row, or 404 xp_family_no_family.
   */
  private function family_for( array $caller ) {
    $family = null;
    if ( 'parent' === $caller['kind'] ) {
      $family = $this->store()->family_of_user( (int) $caller['user_id'] );
    } elseif ( 'hero' === $caller['kind'] ) {
      $family = $this->store()->family( (string) $caller['device']['family_id'] );
    }

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

  // --- kid phones ------------------------------------------------------------------------------

  /** POST /heroes/{hero_id}/link-codes -> { code, expires_at } */
  public function make_code( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'make_code' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $family = $this->family_for( $caller );
    if ( is_wp_error( $family ) ) {
      return $family;
    }

    $hero_id = self::text( $r->get_param( 'hero_id' ) );
    if ( ! Xophz_Compass_Xp_Family_Rules::is_hero_id( $hero_id ) || null === $this->store()->hero( (string) $family['family_id'], $hero_id ) ) {
      return self::error( 'xp_family_unknown_hero', 'That hero is not in this family.', 404 );
    }

    $expires_at = time() + Xophz_Compass_Xp_Family_Rules::CODE_TTL;
    for ( $try = 0; $try < 3; $try++ ) {
      $code = Xophz_Compass_Xp_Family_Rules::new_code();
      $kept = $this->store()->put_code(
        Xophz_Compass_Xp_Family_Rules::hash_secret( $code ),
        (string) $family['family_id'],
        $hero_id,
        (int) $caller['user_id'],
        $expires_at
      );
      if ( $kept ) {
        return [ 'code' => $code, 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $expires_at ) ];
      }
    }

    return self::error( 'xp_family_server', 'Could not make a link code. Try again.', 500 );
  }

  /**
   * POST /devices/claim { code, device_id, label } -> { key, family_id, hero_id, card }
   *
   * Anyone may ask. A wrong, used or expired code counts against the caller's address; past
   * 10 in an hour every claim from it gets 429 until the hour is over. The key is shown once:
   * only its hash is kept.
   */
  public function claim( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'claim' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $counter = self::CLAIM_FAIL_PREFIX . md5( self::client_ip( $r ) );
    $fails   = self::claim_fails( $counter );
    if ( $fails['n'] >= Xophz_Compass_Xp_Family_Rules::CLAIM_FAILS_PER_HOUR ) {
      return self::limited( 'Too many wrong codes. Wait a while, then try again.', max( 1, $fails['until'] - time() ) );
    }

    $device_id = self::text( $r->get_param( 'device_id' ) );
    if ( ! Xophz_Compass_Xp_Family_Rules::is_device_id( $device_id ) ) {
      return self::error( 'xp_family_bad_request', 'A device id (16 letters and digits) is needed.', 400 );
    }

    $code = Xophz_Compass_Xp_Family_Rules::normalize_code( self::text( $r->get_param( 'code' ) ) );
    $row  = null === $code ? null : $this->store()->take_code( Xophz_Compass_Xp_Family_Rules::hash_secret( $code ), time() );
    $hero = null === $row ? null : $this->store()->hero( (string) $row['family_id'], (string) $row['hero_id'] );
    if ( null === $hero ) {
      self::count_claim_fail( $counter );
      return self::error( 'xp_family_code_invalid', "That code didn't work. Ask your Game Master for a new one.", 404 );
    }

    $key = Xophz_Compass_Xp_Family_Rules::new_key();
    $id  = $this->store()->add_device(
      (string) $row['family_id'],
      (string) $row['hero_id'],
      $device_id,
      Xophz_Compass_Xp_Family_Rules::device_label( $r->get_param( 'label' ) ),
      Xophz_Compass_Xp_Family_Rules::hash_secret( $key )
    );
    if ( $id < 1 ) {
      return self::error( 'xp_family_server', 'Could not link this phone. Ask your Game Master for a new code.', 500 );
    }

    return [
      'key'       => $key,
      'family_id' => (string) $row['family_id'],
      'hero_id'   => (string) $row['hero_id'],
      'card'      => self::card_wire( $hero ),
    ];
  }

  /** GET /devices -> [ { id, hero_id, label, created_at, last_seen_at } ] (linked phones only) */
  public function list_devices( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'list_devices' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }
    if ( 'parent' !== $caller['kind'] ) {
      return self::error( 'xp_family_forbidden', 'This caller may not do that.', 403 );
    }

    $family = $this->family_for( $caller );
    if ( is_wp_error( $family ) ) {
      return $family;
    }

    $phones = [];
    foreach ( $this->store()->devices( (string) $family['family_id'] ) as $row ) {
      $phones[] = [
        'id'           => (int) $row['id'],
        'hero_id'      => (string) $row['hero_id'],
        'label'        => (string) $row['label'],
        'created_at'   => Xophz_Compass_Xp_Family_Store::iso( $row['created_at'] ?? null ),
        'last_seen_at' => Xophz_Compass_Xp_Family_Store::iso( $row['last_seen_at'] ?? null ),
      ];
    }

    return $phones;
  }

  /**
   * DELETE /devices/{id} -> { ok: true }
   *
   * A parent unlinks any phone of their own family (a phone of another family is never
   * touched, and an already unlinked one answers ok). A hero key unlinks only its own phone.
   */
  public function unlink_device( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'unlink_device' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $id = (int) $r->get_param( 'id' );

    if ( 'hero' === $caller['kind'] ) {
      if ( (int) $caller['device']['id'] !== $id ) {
        return self::error( 'xp_family_forbidden', 'A hero phone can only unlink itself.', 403 );
      }
      $this->store()->revoke_device( (string) $caller['device']['family_id'], $id );
      return [ 'ok' => true ];
    }

    $family = $this->family_for( $caller );
    if ( is_wp_error( $family ) ) {
      return $family;
    }

    $this->store()->revoke_device( (string) $family['family_id'], $id );

    return [ 'ok' => true ];
  }

  // --- the event stream ------------------------------------------------------------------------

  /**
   * POST /events { events: [...] } -> { accepted, duplicate, rejected: [ { id, code, message } ] }
   * (see Xophz_Compass_Xp_Family_Events::send). Over the hourly limits: 429 with Retry-After.
   */
  public function send_events( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'send_events' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $family = $this->family_for( $caller );
    if ( is_wp_error( $family ) ) {
      return $family;
    }

    $result = Xophz_Compass_Xp_Family_Events::send( $this->store(), $family, $caller, $r->get_param( 'events' ) );
    if ( is_wp_error( $result ) && 'xp_family_rate_limited' === $result->get_error_code() ) {
      return self::limited( $result->get_error_message(), Xophz_Compass_Xp_Family_Event_Rules::RETRY_AFTER );
    }

    return $result;
  }

  /**
   * GET /events?since=&limit= -> [ { seq, id, hero_id, device_id, kind, scope, at, received_at, data } ]
   * (see Xophz_Compass_Xp_Family_Events::read): limit 100 by default, at most 200.
   */
  public function read_events( WP_REST_Request $r ) {
    $caller = $this->guard( $r, 'read_events' );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $family = $this->family_for( $caller );
    if ( is_wp_error( $family ) ) {
      return $family;
    }

    $since = max( 0, (int) $r->get_param( 'since' ) );
    $limit = null === $r->get_param( 'limit' ) ? self::READ_LIMIT : (int) $r->get_param( 'limit' );

    return Xophz_Compass_Xp_Family_Events::read( $this->store(), $family, $caller, $since, max( 1, min( self::READ_LIMIT_MAX, $limit ) ) );
  }

  // --- helpers ---------------------------------------------------------------------------------

  /**
   * The caller's address: Hookshot_Client_Ip::resolve() when Hookshot is active (it trusts
   * X-Forwarded-For only from a configured proxy), else REMOTE_ADDR. Behind a proxy without
   * Hookshot every caller would share one claim counter.
   */
  public static function client_ip( WP_REST_Request $r ): string {
    if ( class_exists( 'Hookshot_Client_Ip' ) ) {
      $resolved = Hookshot_Client_Ip::resolve( $r );
      if ( is_string( $resolved ) && '' !== $resolved ) {
        return $resolved;
      }
    }

    return isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
  }

  /**
   * The failed claims of one address in its current hour.
   *
   * @return array [ 'n' => failures, 'until' => unix end of the hour ]
   */
  private static function claim_fails( string $counter ): array {
    $v = get_transient( $counter );
    if ( ! is_array( $v ) || (int) ( $v['until'] ?? 0 ) <= time() ) {
      return [ 'n' => 0, 'until' => 0 ];
    }

    return [ 'n' => (int) ( $v['n'] ?? 0 ), 'until' => (int) $v['until'] ];
  }

  /** One more failed claim; the first one starts the hour. */
  private static function count_claim_fail( string $counter ): void {
    $v = self::claim_fails( $counter );
    if ( 0 === $v['n'] ) {
      $v['until'] = time() + self::CLAIM_FAIL_WINDOW;
    }
    $v['n']++;

    set_transient( $counter, $v, max( 1, $v['until'] - time() ) );
  }

  /** A 429 xp_family_rate_limited with a Retry-After header, in the shape of a WordPress error. */
  private static function limited( string $message, int $retry_after ): WP_REST_Response {
    $res = new WP_REST_Response(
      [ 'code' => 'xp_family_rate_limited', 'message' => $message, 'data' => [ 'status' => 429 ] ],
      429
    );
    $res->header( 'Retry-After', (string) $retry_after );

    return $res;
  }

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
