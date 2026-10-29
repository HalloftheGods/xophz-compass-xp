<?php

/**
 * The party routes of Do It For The XP (xp-party/v1): forming a party, joining by code, the leader's yes, leaving,
 * the code, raids and strikes, and the fixed words heroes may send.
 *
 * Registered on a family site only, and every route needs XP Pro for the caller's family. The caller is a parent of a
 * family or a hero's own key (Xophz_Compass_Xp_Party_Identity); each route asks the rule class, then the store. A
 * party's writes run under its lock, so counters and strikes never interleave. What leaves a family is a hero card and
 * the strikes: the wire formats below carry nothing else.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/party
 */

class Xophz_Compass_Xp_Party_Rest {

  const NAMESPACE = 'xp-party/v1';

  /** Events a feed read returns when the caller names no limit, and the most it returns. */
  const EVENT_LIMIT     = 100;
  const EVENT_LIMIT_MAX = 200;

  /** The most damage one strike may claim: a chore of the biggest Fibonacci size, thirteen steps of ten. */
  const MAX_STRIKE_DAMAGE = 130;

  /** A strike older than this is stale: the longest window, plus a day. */
  const STRIKE_MAX_AGE = 15 * 86400;

  const NAME_MAX = 40;

  private $plugin_name;
  private $version;

  /** @var Xophz_Compass_Xp_Party_Store|null */
  private $store;

  /** @var object|null Answers caller( WP_REST_Request ) with a family id in it. */
  private $identity;

  /** @var callable|null Answers the time now as unix seconds. */
  private $clock;

  public $action_hooks = [
    'rest_api_init' => 'register_routes',
  ];

  public function __construct( $plugin_name, $version, $store = null, $identity = null, $clock = null ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
    $this->store       = $store;
    $this->identity    = $identity;
    $this->clock       = $clock;
  }

  private function store(): Xophz_Compass_Xp_Party_Store {
    if ( null === $this->store ) {
      $this->store = new Xophz_Compass_Xp_Party_Store();
    }

    return $this->store;
  }

  private function identity() {
    if ( null === $this->identity ) {
      $this->identity = new Xophz_Compass_Xp_Party_Identity(
        new Xophz_Compass_Xp_Family_Rest( $this->plugin_name, $this->version ),
        new Xophz_Compass_Xp_Family_Store()
      );
    }

    return $this->identity;
  }

  private function now(): int {
    return null === $this->clock ? time() : (int) call_user_func( $this->clock );
  }

  // --- routes ----------------------------------------------------------------------------------

  /** Every route lets the request in; each handler judges the caller itself (gate). */
  public function allow(): bool {
    return true;
  }

  /** The routes, on a family site only. */
  public function register_routes(): void {
    if ( ! Xophz_Compass_Xp_Family_Schema::is_family_site() ) {
      return;
    }

    $party  = '(?P<id>[A-Za-z0-9-]{1,36})';
    $routes = [
      [ '/parties', 'POST', 'form' ],
      [ '/parties/join', 'POST', 'join' ],
      [ '/parties/mine', 'GET', 'mine' ],
      [ "/parties/{$party}/requests/(?P<rid>[A-Za-z0-9-]{1,36})", 'POST', 'resolve_request' ],
      [ "/parties/{$party}/members/(?P<family_id>[A-Za-z0-9-]{1,36})/(?P<hero_id>[A-Za-z0-9_-]{1,64})", 'DELETE', 'remove_member' ],
      [ "/parties/{$party}/code/rotate", 'POST', 'rotate_code' ],
      [ '/codes/(?P<code>[A-Za-z0-9-]{1,20})/block', 'POST', 'block' ],
      [ "/parties/{$party}/feed", 'GET', 'feed' ],
      [ "/parties/{$party}/raids", 'POST', 'start_raid' ],
      [ "/raids/{$party}/strikes", 'POST', 'strike' ],
      [ "/raids/{$party}/strikes/(?P<key>[A-Za-z0-9:._-]{1,80})", 'DELETE', 'withdraw_strike' ],
      [ "/parties/{$party}/presets", 'POST', 'preset' ],
    ];

    foreach ( $routes as [ $route, $methods, $handler ] ) {
      register_rest_route(
        self::NAMESPACE,
        $route,
        [
          'methods'             => $methods,
          'callback'            => [ $this, $handler ],
          'permission_callback' => [ $this, 'allow' ],
        ]
      );
    }
  }

  // --- who is calling --------------------------------------------------------------------------

  /**
   * The caller, when it may use party routes at all: signed in, and its family holds XP Pro.
   *
   * @return array|WP_Error The caller (see Xophz_Compass_Xp_Party_Identity), or why not.
   */
  private function gate( WP_REST_Request $r ) {
    $caller = $this->identity()->caller( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    if ( 'none' === $caller['kind'] ) {
      return self::error( 'rest_not_logged_in', 'Sign in to the family site first.', 401 );
    }

    if ( ! Xophz_Compass_Xp_Family_Pro::entitled( $caller['family_id'], $this->now() ) ) {
      return self::error( 'xp_party_pro_required', 'Parties with other families need XP Pro.', 403 );
    }

    return $caller;
  }

  /** Whether the caller speaks for this hero of this family: its parent, or the hero's own key. */
  private static function acts_for( array $caller, string $family_id, string $hero_id ): bool {
    if ( 'parent' === $caller['kind'] ) {
      return $caller['family_id'] === $family_id;
    }

    return 'hero' === $caller['kind'] && $caller['family_id'] === $family_id && $caller['hero_id'] === $hero_id;
  }

  /** Whether the caller speaks for the party's leader. */
  private static function leader_side( array $caller, array $party ): bool {
    return self::acts_for( $caller, $party['leader_family_id'], $party['leader_hero_id'] );
  }

  /** Whether the caller speaks for at least one member of the party. */
  private static function member_side( array $caller, array $members ): bool {
    foreach ( $members as $member ) {
      if ( self::acts_for( $caller, $member['family_id'], $member['hero_id'] ) ) {
        return true;
      }
    }

    return false;
  }

  // --- forming and joining ---------------------------------------------------------------------

  /** POST /parties: a parent forms a party led by one of its heroes. */
  public function form( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    if ( 'parent' !== $caller['kind'] ) {
      return self::forbidden();
    }

    $name    = self::clean_name( $r->get_param( 'name' ) );
    $emblem  = $r->get_param( 'emblem' );
    $rule    = $r->get_param( 'invite_rule' ) ?? 'leader';
    $card    = Xophz_Compass_Xp_Party_Rules::card( $r->get_param( 'leader' ) );
    $code    = $this->fresh_code();
    $emblem_ok = is_string( $emblem ) && 1 === preg_match( '/^[a-z0-9-]{1,24}$/', $emblem );

    if ( null === $name ) {
      return self::error( 'xp_party_bad_name', 'Give the party a name of up to 40 letters.', 400 );
    }
    if ( ! $emblem_ok ) {
      return self::error( 'xp_party_bad_emblem', 'Choose one of the emblems.', 400 );
    }
    if ( ! in_array( $rule, [ 'leader', 'members' ], true ) ) {
      return self::error( 'xp_party_bad_rule', 'Invites are for the leader or for members.', 400 );
    }
    if ( ! $card['ok'] ) {
      return self::error( $card['code'], 'That hero card carries more than a party may see.', 400 );
    }
    if ( null === $code ) {
      return self::error( 'xp_party_busy', 'Could not make a party code. Try again.', 503 );
    }

    $now      = $this->now();
    $party_id = wp_generate_uuid4();
    $leader   = $card['card'];

    $this->store()->create_party( [
      'party_id'         => $party_id,
      'name'             => $name,
      'emblem'           => $emblem,
      'code'             => $code,
      'invite_rule'      => $rule,
      'leader_family_id' => $caller['family_id'],
      'leader_hero_id'   => $leader['heroId'],
      'now'              => $now,
    ] );

    $made = $this->store()->locked(
      $party_id,
      function () use ( $party_id, $caller, $leader, $now ) {
        $this->store()->add_member( $party_id, $caller['family_id'], $leader['heroId'], $leader, 'leader', $now );
        $this->store()->append_event( $party_id, 'join', $caller['family_id'], $leader['heroId'], [ 'nickname' => $leader['nickname'] ], $now );

        return true;
      }
    );
    if ( is_wp_error( $made ) ) {
      return $made;
    }

    return new WP_REST_Response( [ 'party' => $this->party_wire( $this->store()->party( $party_id ), $caller ), 'code' => $code ], 201 );
  }

  /** POST /parties/join: a parent asks for one of its heroes to join the party that holds a code. */
  public function join( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    if ( 'parent' !== $caller['kind'] ) {
      return self::forbidden();
    }

    $card = Xophz_Compass_Xp_Party_Rules::card( $r->get_param( 'card' ) );
    if ( ! $card['ok'] ) {
      return self::error( $card['code'], 'That hero card carries more than a party may see.', 400 );
    }

    $code  = Xophz_Compass_Xp_Party_Rules::normalize_code( $r->get_param( 'code' ) );
    $party = Xophz_Compass_Xp_Party_Rules::is_code_shaped( $code ) ? $this->store()->party_by_code( $code ) : null;
    if ( null === $party ) {
      return self::error( 'xp_party_code_unknown', 'No party has that code.', 404 );
    }

    if ( $this->store()->is_blocked( $caller['family_id'], $party['party_id'] ) ) {
      return self::error( 'xp_party_blocked', 'This family blocked that party.', 403 );
    }

    $hero_id = $card['card']['heroId'];

    return $this->store()->locked(
      $party['party_id'],
      function () use ( $party, $caller, $card, $hero_id ) {
        if ( null !== $this->store()->member( $party['party_id'], $caller['family_id'], $hero_id ) ) {
          return self::error( 'xp_party_already_member', 'That hero is already in the party.', 409 );
        }
        if ( null !== $this->store()->open_request_for( $party['party_id'], $caller['family_id'], $hero_id ) ) {
          return self::error( 'xp_party_request_pending', 'That hero already asked to join.', 409 );
        }

        $request_id = wp_generate_uuid4();
        $this->store()->create_request( [
          'request_id' => $request_id,
          'party_id'   => $party['party_id'],
          'family_id'  => $caller['family_id'],
          'hero_id'    => $hero_id,
          'card'       => $card['card'],
          'now'        => $this->now(),
        ] );

        return new WP_REST_Response( [ 'request' => $this->request_wire( $this->store()->request( $request_id ), $party ) ], 201 );
      }
    );
  }

  /**
   * POST /parties/{id}/requests/{rid}: the leader accepts or declines a request; the family that asked may only
   * withdraw it (decline).
   */
  public function resolve_request( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $decision = $r->get_param( 'decision' );
    if ( ! in_array( $decision, [ 'approve', 'decline' ], true ) ) {
      return self::error( 'xp_party_bad_decision', 'Approve or decline.', 400 );
    }

    $party_id = (string) $r->get_param( 'id' );
    $gone     = self::error( 'xp_party_request_gone', 'That request is not open any more.', 404 );
    $party    = $this->store()->party( $party_id );
    if ( null === $party ) {
      return $gone;
    }

    return $this->store()->locked(
      $party_id,
      function () use ( $r, $caller, $decision, $party, $gone ) {
        $request = $this->store()->request( (string) $r->get_param( 'rid' ) );
        if ( null === $request || $request['party_id'] !== $party['party_id'] || null !== $request['resolved_at'] ) {
          return $gone;
        }

        $leader = self::leader_side( $caller, $party );
        $asker  = 'parent' === $caller['kind'] && $caller['family_id'] === $request['family_id'];
        if ( ! $leader && ! $asker ) {
          return self::forbidden();
        }
        if ( 'approve' === $decision && ! $leader ) {
          return self::forbidden();
        }

        $now = $this->now();
        if ( 'decline' === $decision ) {
          $this->store()->resolve_request( $request['request_id'], 'declined', $now );

          return new WP_REST_Response( [ 'request' => [ 'id' => $request['request_id'], 'resolution' => 'declined' ] ], 200 );
        }

        if ( null !== $this->store()->member( $party['party_id'], $request['family_id'], $request['hero_id'] ) ) {
          return self::error( 'xp_party_already_member', 'That hero is already in the party.', 409 );
        }

        $this->store()->resolve_request( $request['request_id'], 'accepted', $now );
        $this->store()->add_member( $party['party_id'], $request['family_id'], $request['hero_id'], $request['card'], 'member', $now );
        $this->store()->append_event( $party['party_id'], 'join', $request['family_id'], $request['hero_id'], [ 'nickname' => $request['card']['nickname'] ?? '' ], $now );

        return new WP_REST_Response(
          [
            'request' => [ 'id' => $request['request_id'], 'resolution' => 'accepted' ],
            'member'  => self::member_wire( $this->store()->member( $party['party_id'], $request['family_id'], $request['hero_id'] ) ),
          ],
          200
        );
      }
    );
  }

  // --- leaving, the code and blocking ----------------------------------------------------------

  /** DELETE /parties/{id}/members/{family_id}/{hero_id}: a hero leaves, or its family takes it out. */
  public function remove_member( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $party_id  = (string) $r->get_param( 'id' );
    $family_id = (string) $r->get_param( 'family_id' );
    $hero_id   = (string) $r->get_param( 'hero_id' );

    if ( null === $this->store()->party( $party_id ) ) {
      return self::error( 'xp_party_not_found', 'No such party.', 404 );
    }
    if ( ! self::acts_for( $caller, $family_id, $hero_id ) ) {
      return self::forbidden();
    }

    return $this->store()->locked(
      $party_id,
      function () use ( $party_id, $family_id, $hero_id ) {
        $now    = $this->now();
        $member = $this->store()->member( $party_id, $family_id, $hero_id );
        if ( null === $member ) {
          return self::error( 'xp_party_not_member', 'That hero is not in the party.', 404 );
        }

        $this->store()->remove_member( $party_id, $family_id, $hero_id, $now );
        $this->store()->append_event( $party_id, 'leave', $family_id, $hero_id, [], $now );
        $disbanded = $this->after_leave( $this->store()->party( $party_id ), $member, $now );

        return new WP_REST_Response( [ 'left' => true, 'disbanded' => $disbanded ], 200 );
      }
    );
  }

  /**
   * After a member left: the party ends when nobody is left, and passes to the member who joined next when its leader
   * left. Answers whether the party ended.
   */
  private function after_leave( array $party, array $left, int $now ): bool {
    $remaining = $this->store()->members( $party['party_id'] );
    if ( array() === $remaining ) {
      $this->store()->disband( $party['party_id'], $now );

      return true;
    }

    $was_leader = $party['leader_family_id'] === $left['family_id'] && $party['leader_hero_id'] === $left['hero_id'];
    if ( $was_leader ) {
      $next = $remaining[0];
      $this->store()->set_leader( $party['party_id'], $next['family_id'], $next['hero_id'] );
      $this->store()->set_role( $party['party_id'], $next['family_id'], $next['hero_id'], 'leader' );
    }

    return false;
  }

  /** POST /parties/{id}/code/rotate: the leader makes a new code, which kills the old one. */
  public function rotate_code( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $party = $this->store()->party( (string) $r->get_param( 'id' ) );
    if ( null === $party ) {
      return self::error( 'xp_party_not_found', 'No such party.', 404 );
    }
    if ( ! self::leader_side( $caller, $party ) ) {
      return self::forbidden();
    }

    $code = $this->fresh_code();
    if ( null === $code ) {
      return self::error( 'xp_party_busy', 'Could not make a party code. Try again.', 503 );
    }

    $this->store()->set_code( $party['party_id'], $code );

    return new WP_REST_Response( [ 'code' => $code ], 200 );
  }

  /** POST /codes/{code}/block: a parent takes its heroes out of the party that holds a code, for good. */
  public function block( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    if ( 'parent' !== $caller['kind'] ) {
      return self::forbidden();
    }

    $code  = Xophz_Compass_Xp_Party_Rules::normalize_code( $r->get_param( 'code' ) );
    $party = Xophz_Compass_Xp_Party_Rules::is_code_shaped( $code ) ? $this->store()->party_by_code( $code ) : null;
    if ( null === $party ) {
      return self::error( 'xp_party_code_unknown', 'No party has that code.', 404 );
    }

    return $this->store()->locked(
      $party['party_id'],
      function () use ( $party, $caller ) {
        $now = $this->now();
        $this->store()->block( $caller['family_id'], $party['party_id'], $now );

        foreach ( $this->store()->members( $party['party_id'] ) as $member ) {
          if ( $member['family_id'] !== $caller['family_id'] ) {
            continue;
          }
          $this->store()->remove_member( $party['party_id'], $member['family_id'], $member['hero_id'], $now );
          $this->store()->append_event( $party['party_id'], 'leave', $member['family_id'], $member['hero_id'], [], $now );
          $current = $this->store()->party( $party['party_id'] );
          if ( null !== $current ) {
            $this->after_leave( $current, $member, $now );
          }
        }

        foreach ( $this->store()->open_requests_of_family( $caller['family_id'] ) as $request ) {
          if ( $request['party_id'] === $party['party_id'] ) {
            $this->store()->resolve_request( $request['request_id'], 'declined', $now );
          }
        }

        return new WP_REST_Response( [ 'partyId' => $party['party_id'] ], 200 );
      }
    );
  }

  // --- reading ---------------------------------------------------------------------------------

  /** GET /parties/mine: the parties of the caller (a parent: its family; a hero key: that hero) and the open requests. */
  public function mine( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $ids = 'parent' === $caller['kind']
      ? $this->store()->party_ids_of( $caller['family_id'] )
      : $this->store()->party_ids_of( $caller['family_id'], $caller['hero_id'] );
    sort( $ids );

    $parties  = [];
    $incoming = [];
    foreach ( $ids as $id ) {
      $party = $this->store()->party( $id );
      if ( null === $party ) {
        continue;
      }
      $parties[] = $this->party_wire( $party, $caller );
      if ( self::leader_side( $caller, $party ) ) {
        foreach ( $this->store()->open_requests_of_party( $id ) as $request ) {
          $incoming[] = $this->request_wire( $request, $party );
        }
      }
    }

    $outgoing = [];
    if ( 'parent' === $caller['kind'] ) {
      foreach ( $this->store()->open_requests_of_family( $caller['family_id'] ) as $request ) {
        $party = $this->store()->party( $request['party_id'] );
        if ( null !== $party ) {
          $outgoing[] = $this->request_wire( $request, $party );
        }
      }
    }

    return new WP_REST_Response( [ 'parties' => $parties, 'requests' => [ 'incoming' => $incoming, 'outgoing' => $outgoing ] ], 200 );
  }

  /** GET /parties/{id}/feed?since=&limit=: the party now, and its events after a number. */
  public function feed( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $party = $this->store()->party( (string) $r->get_param( 'id' ) );
    if ( null === $party ) {
      return self::error( 'xp_party_not_found', 'No such party.', 404 );
    }
    if ( ! self::member_side( $caller, $this->store()->members( $party['party_id'] ) ) ) {
      return self::error( 'xp_party_not_member', 'That party is not yours to read.', 403 );
    }

    $since  = max( 0, (int) $r->get_param( 'since' ) );
    $asked  = (int) $r->get_param( 'limit' );
    $limit  = $asked > 0 ? min( $asked, self::EVENT_LIMIT_MAX ) : self::EVENT_LIMIT;
    $events = array_map( [ self::class, 'event_wire' ], $this->store()->events_since( $party['party_id'], $since, $limit ) );

    return new WP_REST_Response(
      [ 'party' => $this->party_wire( $party, $caller ), 'events' => $events, 'seq' => (int) $party['events_seq'] ],
      200
    );
  }

  // --- raids -----------------------------------------------------------------------------------

  /** POST /parties/{id}/raids: the leader starts a raid on a library beast. */
  public function start_raid( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $party = $this->store()->party( (string) $r->get_param( 'id' ) );
    if ( null === $party ) {
      return self::error( 'xp_party_not_found', 'No such party.', 404 );
    }
    if ( ! self::leader_side( $caller, $party ) ) {
      return self::forbidden();
    }

    $beast   = $r->get_param( 'beast_id' );
    $window  = $r->get_param( 'window' );
    $beast_ok = is_string( $beast ) && strlen( $beast ) <= 64 && 1 === preg_match( '/^beast-[a-z0-9]+(-[a-z0-9]+)*$/', $beast );
    if ( ! $beast_ok ) {
      return self::error( 'xp_party_bad_beast', 'Choose a beast from the library.', 400 );
    }
    if ( ! Xophz_Compass_Xp_Party_Rules::is_window( $window ) ) {
      return self::error( 'xp_party_bad_window', 'A raid lasts a quick, standard or epic window.', 400 );
    }

    return $this->store()->locked(
      $party['party_id'],
      function () use ( $party, $beast, $window ) {
        $now     = $this->now();
        $members = $this->store()->members( $party['party_id'] );
        if ( count( $members ) < 2 ) {
          return self::error( 'xp_party_too_small', 'A raid needs at least two heroes in the party.', 409 );
        }

        $latest = $this->store()->latest_raid( $party['party_id'] );
        if ( null !== $latest && 'active' === $this->raid_status( $latest, $now ) ) {
          return self::error( 'xp_party_raid_running', 'The party is already fighting a beast.', 409 );
        }

        $raid_id = wp_generate_uuid4();
        $max_hp  = Xophz_Compass_Xp_Party_Rules::raid_max_hp( $window, count( $members ) );
        $this->store()->create_raid( [
          'raid_id'      => $raid_id,
          'party_id'     => $party['party_id'],
          'beast_id'     => $beast,
          'window'       => $window,
          'max_hp'       => $max_hp,
          'member_count' => count( $members ),
          'started_at'   => $now,
          'ends_at'      => Xophz_Compass_Xp_Party_Rules::raid_ends_at( $now, $window ),
        ] );
        $this->store()->append_event( $party['party_id'], 'raid', null, null, [ 'raidId' => $raid_id, 'beastId' => $beast, 'window' => $window, 'maxHp' => $max_hp ], $now );

        return new WP_REST_Response( [ 'raid' => $this->raid_wire( $this->store()->raid( $raid_id ), $now ) ], 201 );
      }
    );
  }

  /** POST /raids/{id}/strikes: a member's strike, from its family's parent or its own key. A resend counts once. */
  public function strike( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $key     = $r->get_param( 'key' );
    $hero_id = $r->get_param( 'hero_id' );
    $damage  = self::whole_number( $r->get_param( 'damage' ) );
    $day     = $r->get_param( 'day' );
    $at      = is_string( $r->get_param( 'at' ) ) ? strtotime( $r->get_param( 'at' ) ) : false;

    $valid = is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9:._-]{1,80}$/', $key )
      && is_string( $hero_id ) && 1 === preg_match( Xophz_Compass_Xp_Party_Rules::HERO_ID_PATTERN, $hero_id )
      && null !== $damage && $damage >= 1 && $damage <= self::MAX_STRIKE_DAMAGE
      && is_string( $day ) && false !== $at;
    if ( ! $valid ) {
      return self::error( 'xp_party_strike_invalid', 'That strike is not shaped like one.', 400 );
    }

    $raid  = $this->store()->raid( (string) $r->get_param( 'id' ) );
    $party = null === $raid ? null : $this->store()->party( $raid['party_id'] );
    if ( null === $raid || null === $party ) {
      return self::error( 'xp_party_raid_not_found', 'No such raid.', 404 );
    }

    if ( 'hero' === $caller['kind'] && $caller['hero_id'] !== $hero_id ) {
      return self::forbidden();
    }

    $now = $this->now();
    $at  = min( (int) $at, $now );
    if ( $at < $now - self::STRIKE_MAX_AGE ) {
      return self::error( 'xp_party_strike_stale', 'That strike is too old to count.', 409 );
    }

    return $this->store()->locked(
      $party['party_id'],
      function () use ( $raid, $party, $caller, $key, $hero_id, $damage, $day, $at, $now ) {
        $family_id = $caller['family_id'];
        $members   = array_map(
          static function ( array $m ): string {
            return $m['family_id'] . '|' . $m['hero_id'];
          },
          $this->store()->members( $party['party_id'] )
        );
        $strikes   = $this->store()->strikes_of( $raid['raid_id'] );
        $rules_raid = $this->rules_raid( $raid );
        $before    = Xophz_Compass_Xp_Party_Rules::raid_state( $rules_raid, $strikes, $now );

        $verdict = Xophz_Compass_Xp_Party_Rules::strike(
          $rules_raid,
          $strikes,
          $members,
          [ 'strike_key' => $key, 'member' => $family_id . '|' . $hero_id, 'damage' => $damage, 'day' => $day, 'at' => $at ]
        );

        if ( 'not_member' === $verdict['reason'] ) {
          return self::error( 'xp_party_not_member', 'That hero is not in the party.', 403 );
        }
        if ( 'bad_day' === $verdict['reason'] ) {
          return self::error( 'xp_party_strike_invalid', 'That strike is not shaped like one.', 400 );
        }

        if ( $verdict['accepted'] ) {
          $this->store()->add_strike( [
            'raid_id'    => $raid['raid_id'],
            'strike_key' => $key,
            'family_id'  => $family_id,
            'hero_id'    => $hero_id,
            'damage'     => $verdict['damage'],
            'day'        => $day,
            'at'         => $at,
            'now'        => $now,
          ] );
          $this->store()->append_event( $party['party_id'], 'strike', $family_id, $hero_id, [ 'key' => $key, 'damage' => $verdict['damage'] ], $now );

          $after = Xophz_Compass_Xp_Party_Rules::raid_state( $rules_raid, $this->store()->strikes_of( $raid['raid_id'] ), $now );
          if ( $before['hp'] > 0 && 0 === $after['hp'] ) {
            $this->store()->append_event( $party['party_id'], 'defeat', $family_id, $hero_id, [ 'raidId' => $raid['raid_id'] ], $now );
          }
        }

        return new WP_REST_Response(
          [
            'accepted' => $verdict['accepted'],
            'damage'   => $verdict['damage'],
            'reason'   => $verdict['reason'],
            'raid'     => $this->raid_wire( $raid, $now ),
          ],
          200
        );
      }
    );
  }

  /** DELETE /raids/{id}/strikes/{key}: takes a strike back (a chore that was undone), for the hero it belongs to. */
  public function withdraw_strike( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $raid  = $this->store()->raid( (string) $r->get_param( 'id' ) );
    $party = null === $raid ? null : $this->store()->party( $raid['party_id'] );
    if ( null === $raid || null === $party ) {
      return self::error( 'xp_party_raid_not_found', 'No such raid.', 404 );
    }

    $key = (string) $r->get_param( 'key' );

    return $this->store()->locked(
      $party['party_id'],
      function () use ( $raid, $party, $caller, $key ) {
        $found = null;
        foreach ( $this->store()->strikes_of( $raid['raid_id'] ) as $strike ) {
          if ( $strike['strike_key'] === $key ) {
            $found = $strike;
          }
        }
        if ( null === $found ) {
          return self::error( 'xp_party_strike_not_found', 'No such strike.', 404 );
        }
        if ( ! self::acts_for( $caller, $found['family_id'], $found['hero_id'] ) ) {
          return self::forbidden();
        }

        $this->store()->remove_strike( $raid['raid_id'], $key );
        $this->store()->append_event( $party['party_id'], 'unstrike', $found['family_id'], $found['hero_id'], [ 'key' => $key ], $this->now() );

        return new WP_REST_Response( [ 'removed' => true ], 200 );
      }
    );
  }

  // --- words -----------------------------------------------------------------------------------

  /** POST /parties/{id}/presets: a member sends one of the fixed words, to the party or to one member. */
  public function preset( WP_REST_Request $r ) {
    $caller = $this->gate( $r );
    if ( is_wp_error( $caller ) ) {
      return $caller;
    }

    $party = $this->store()->party( (string) $r->get_param( 'id' ) );
    if ( null === $party ) {
      return self::error( 'xp_party_not_found', 'No such party.', 404 );
    }

    $preset  = $r->get_param( 'preset' );
    $hero_id = (string) $r->get_param( 'hero_id' );
    if ( ! Xophz_Compass_Xp_Party_Rules::is_preset( $preset ) ) {
      return self::error( 'xp_party_bad_preset', 'Only the preset words can be sent.', 400 );
    }
    if ( 'hero' === $caller['kind'] && $caller['hero_id'] !== $hero_id ) {
      return self::forbidden();
    }

    return $this->store()->locked(
      $party['party_id'],
      function () use ( $party, $caller, $preset, $hero_id, $r ) {
        if ( null === $this->store()->member( $party['party_id'], $caller['family_id'], $hero_id ) ) {
          return self::error( 'xp_party_not_member', 'That hero is not in the party.', 403 );
        }

        $data      = [ 'preset' => $preset ];
        $to_family = $r->get_param( 'to_family' );
        $to_hero   = $r->get_param( 'to_hero' );
        if ( null !== $to_family || null !== $to_hero ) {
          $target_ok = is_string( $to_family ) && is_string( $to_hero ) && null !== $this->store()->member( $party['party_id'], $to_family, $to_hero );
          if ( ! $target_ok ) {
            return self::error( 'xp_party_bad_target', 'That word is for a member of the party.', 400 );
          }
          $data['toFamily'] = $to_family;
          $data['toHero']   = $to_hero;
        }

        $seq = $this->store()->append_event( $party['party_id'], 'preset', $caller['family_id'], $hero_id, $data, $this->now() );

        return new WP_REST_Response( [ 'seq' => $seq ], 201 );
      }
    );
  }

  // --- the wire --------------------------------------------------------------------------------

  /** The party as a caller sees it; the code only for the leader, or for any member when invites are open to members. */
  private function party_wire( array $party, array $caller ): array {
    $members = $this->store()->members( $party['party_id'] );
    $raid    = $this->store()->latest_raid( $party['party_id'] );
    $wire    = [
      'id'         => $party['party_id'],
      'name'       => $party['name'],
      'emblem'     => $party['emblem'],
      'inviteRule' => $party['invite_rule'],
      'leader'     => [ 'familyId' => $party['leader_family_id'], 'heroId' => $party['leader_hero_id'] ],
      'members'    => array_map( [ self::class, 'member_wire' ], $members ),
      'raid'       => null === $raid ? null : $this->raid_wire( $raid, $this->now() ),
      'seq'        => (int) $party['events_seq'],
    ];

    $shares = 'members' === $party['invite_rule'] && self::member_side( $caller, $members );
    if ( self::leader_side( $caller, $party ) || $shares ) {
      $wire['code'] = $party['code'];
    }

    return $wire;
  }

  private static function member_wire( array $member ): array {
    return [
      'familyId' => $member['family_id'],
      'heroId'   => $member['hero_id'],
      'card'     => $member['card'],
      'role'     => $member['role'],
      'joinedAt' => self::iso( $member['joined_at'] ),
    ];
  }

  private static function request_wire( array $request, array $party ): array {
    return [
      'id'        => $request['request_id'],
      'partyId'   => $request['party_id'],
      'partyName' => $party['name'],
      'familyId'  => $request['family_id'],
      'heroId'    => $request['hero_id'],
      'card'      => $request['card'],
      'createdAt' => self::iso( $request['created_at'] ),
    ];
  }

  private static function event_wire( array $event ): array {
    return [
      'seq'      => $event['seq'],
      'kind'     => $event['kind'],
      'familyId' => $event['family_id'],
      'heroId'   => $event['hero_id'],
      'data'     => $event['data'],
      'at'       => self::iso( $event['at'] ),
    ];
  }

  /** The raid with its health, status, top striker, last hit and every counted strike. */
  private function raid_wire( array $raid, int $now ): array {
    $strikes = $this->store()->strikes_of( $raid['raid_id'] );
    $state   = Xophz_Compass_Xp_Party_Rules::raid_state( $this->rules_raid( $raid ), $strikes, $now );

    return [
      'id'          => $raid['raid_id'],
      'beastId'     => $raid['beast_id'],
      'window'      => $raid['window'],
      'maxHp'       => $raid['max_hp'],
      'memberCount' => $raid['member_count'],
      'startedAt'   => self::iso( $raid['started_at'] ),
      'endsAt'      => self::iso( $raid['ends_at'] ),
      'hp'          => $state['hp'],
      'status'      => $state['status'],
      'top'         => self::member_ref( $state['top'] ),
      'last'        => self::member_ref( $state['last'] ),
      'strikes'     => array_map(
        static function ( array $s ): array {
          return [
            'key'      => $s['strike_key'],
            'familyId' => $s['family_id'],
            'heroId'   => $s['hero_id'],
            'damage'   => $s['damage'],
            'day'      => $s['day'],
            'at'       => self::iso( $s['at'] ),
          ];
        },
        $strikes
      ),
    ];
  }

  private function raid_status( array $raid, int $now ): string {
    return Xophz_Compass_Xp_Party_Rules::raid_state( $this->rules_raid( $raid ), $this->store()->strikes_of( $raid['raid_id'] ), $now )['status'];
  }

  /** The raid in the shape the rule class reads. */
  private function rules_raid( array $raid ): array {
    return [ 'max_hp' => $raid['max_hp'], 'window' => $raid['window'], 'started_at' => $raid['started_at'] ];
  }

  // --- small helpers ---------------------------------------------------------------------------

  /** A code no party holds, or null after a few tries. */
  private function fresh_code(): ?string {
    for ( $try = 0; $try < 10; $try++ ) {
      $code = Xophz_Compass_Xp_Party_Rules::new_code();
      if ( ! $this->store()->code_taken( $code ) ) {
        return $code;
      }
    }

    return null;
  }

  /** A party name: 1 to 40 characters, none of them control characters; null if it is not one. */
  private static function clean_name( $name ): ?string {
    if ( ! is_string( $name ) ) {
      return null;
    }

    $name   = trim( $name );
    $length = mb_strlen( $name );

    return $length >= 1 && $length <= self::NAME_MAX && 1 !== preg_match( '/[\x00-\x1F\x7F]/u', $name ) ? $name : null;
  }

  /** A whole number from a JSON number or a string of digits; null for anything else. */
  private static function whole_number( $value ): ?int {
    if ( is_int( $value ) ) {
      return $value;
    }

    return is_string( $value ) && 1 === preg_match( '/^-?\d{1,9}$/', $value ) ? (int) $value : null;
  }

  private static function iso( ?int $unix ): ?string {
    return null === $unix ? null : gmdate( 'Y-m-d\TH:i:s\Z', $unix );
  }

  /** "family|hero" as a reference the app reads; null for nobody. */
  private static function member_ref( ?string $member ): ?array {
    if ( null === $member ) {
      return null;
    }

    [ $family_id, $hero_id ] = explode( '|', $member, 2 );

    return [ 'familyId' => $family_id, 'heroId' => $hero_id ];
  }

  private static function forbidden(): WP_Error {
    return self::error( 'xp_party_forbidden', 'This caller may not do that.', 403 );
  }

  private static function error( string $code, string $message, int $status ): WP_Error {
    return new WP_Error( $code, $message, [ 'status' => $status ] );
  }
}
