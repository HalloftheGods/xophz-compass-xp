<?php

/**
 * API Key Authority & Gatekeeper Bridge for XP SaaS.
 *
 * Delegates key generation, hashing, scoping, and REST authentication to
 * Xophz Gatekeeper when present, with a local fallback for standalone installs.
 *
 * Rules (#5982):
 * - Who may mint which key (own account, paid tier's scopes, xp:admin for admins only) is
 *   decided by Xophz_Compass_Xp_Key_Grants.
 * - Keys are managed from a signed-in session. A request authenticated by an API key cannot
 *   list, create or revoke keys, so a narrow key cannot mint a broader one.
 * - An API key is read only from the X-API-Key header or Authorization: Bearer. A key in the
 *   query string is refused (URLs land in server and proxy logs); a body field is ignored.
 * - The plain key appears once, in the create response. Lists return a masked hint.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */

class Xophz_Compass_Xp_Keys {

  /** User meta for the standalone (no Gatekeeper) key store. */
  const LOCAL_META = '_xp_api_keys';

  private $plugin_name;
  private $version;

  /** The request that authenticate_api_key() authenticated with an API key, if any. */
  private $key_authenticated_request = null;

  public $action_hooks = [
    'rest_api_init' => 'register_key_rest_routes',
  ];

  public $filter_hooks = [
    'rest_pre_dispatch' => ['authenticate_api_key', 10, 3],
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
   * Register REST routes for key management.
   */
  public function register_key_rest_routes() {
    register_rest_route( 'xp/v1', '/keys', array(
      array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => array( $this, 'rest_get_keys' ),
        'permission_callback' => array( $this, 'can_manage_keys' ),
      ),
      array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => array( $this, 'rest_create_key' ),
        'permission_callback' => array( $this, 'can_manage_keys' ),
      ),
    ) );

    register_rest_route( 'xp/v1', '/keys/(?P<id>[a-zA-Z0-9_-]+)', array(
      array(
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => array( $this, 'rest_revoke_key' ),
        'permission_callback' => array( $this, 'can_manage_keys' ),
      ),
    ) );
  }

  /**
   * Key management needs a signed-in session, not an API key.
   *
   * @return bool|WP_Error False (401) when signed out, 403 when the request came in on an API key.
   */
  public function can_manage_keys( $request ) {
    if ( null !== $this->key_authenticated_request && $request === $this->key_authenticated_request ) {
      return new WP_Error(
        'key_session_required',
        'API keys cannot list, create or revoke API keys. Sign in to manage keys.',
        array( 'status' => 403 )
      );
    }
    return is_user_logged_in();
  }

  /** Masked hint for a plain key: its prefix and last four characters. */
  private static function mask_key( string $plain ): string {
    $prefix = 0 === strpos( $plain, 'xp_live_' ) ? 'xp_live_' : '';
    return $prefix . '...' . substr( $plain, -4 );
  }

  /** Display tier for a scope set (compared in Gatekeeper's stored form: admin:all is "adminall"). */
  private static function tier_from_scopes( array $scopes ): string {
    $keys          = array_map( array( 'Xophz_Compass_Xp_Key_Grants', 'scope_key' ), $scopes );
    $is_enterprise = array_intersect( array( 'adminall', 'xpadmin', 'xpbank' ), $keys );
    if ( ! empty( $is_enterprise ) ) {
      return 'enterprise';
    }
    return in_array( 'xpwrite', $keys, true ) ? 'developer' : 'hobby';
  }

  /**
   * List API keys for the current user (administrators also see every xp_ key).
   * Never returns a plain key.
   */
  public function rest_get_keys( WP_REST_Request $request ) {
    $user_id  = get_current_user_id();
    $entitled = Xophz_Compass_Xp_Key_Grants::get_entitled_tier( $user_id );

    // Delegate to Gatekeeper Key Authority when available
    if ( Xophz_Compass_Xp_Key_Grants::gatekeeper_active() ) {
      $all_keys  = Gatekeeper_Keys::list_keys();
      $is_admin  = current_user_can( Xophz_Compass_Xp_Key_Grants::ADMIN_CAPABILITY );
      $formatted = array();

      foreach ( $all_keys as $k ) {
        $is_owner     = (int) ( $k['user_id'] ?? 0 ) === (int) $user_id;
        $is_xp_prefix = strpos( $k['prefix'] ?? '', 'xp_' ) === 0 || strpos( $k['display_key'] ?? '', 'xp_' ) === 0;

        // Show keys owned by user or, for admins, show all xp-scoped keys
        if ( $is_owner || ( $is_admin && $is_xp_prefix ) ) {
          $scopes = (array) ( $k['scopes'] ?? array() );
          $formatted[] = array(
            'id'          => $k['id'],
            'label'       => $k['name'] ?? 'XP Key',
            'tier'        => empty( $scopes ) ? 'developer' : self::tier_from_scopes( $scopes ),
            'masked_key'  => $k['display_key'] ?? ( ( $k['prefix'] ?? '' ) . '...' ),
            'scopes'      => empty( $scopes ) ? array( 'xp:read' ) : $scopes,
            'created_at'  => $k['created_at'] ?? '',
            'last_used'   => ! empty( $k['last_used_at'] ) ? $k['last_used_at'] : 'Never',
            'status'      => $k['status'] ?? 'active',
          );
        }
      }

      return rest_ensure_response( array(
        'success'       => true,
        'authority'     => 'gatekeeper',
        'entitled_tier' => '' === $entitled ? null : $entitled,
        'keys'          => $formatted,
      ) );
    }

    // Local fallback when running standalone without Gatekeeper
    $keys = get_user_meta( $user_id, self::LOCAL_META, true );
    if ( ! is_array( $keys ) ) {
      $keys = array();
    }

    $formatted = array_map( function( $k ) {
      $masked = $k['masked_key'] ?? ( isset( $k['key'] ) ? self::mask_key( (string) $k['key'] ) : '...' );
      return array(
        'id'          => $k['id'],
        'label'       => $k['label'] ?? 'API Key',
        'tier'        => $k['tier'] ?? 'developer',
        'masked_key'  => $masked,
        'scopes'      => $k['scopes'] ?? array( 'xp:read', 'xp:write' ),
        'created_at'  => $k['created_at'] ?? '',
        'last_used'   => $k['last_used'] ?? 'Never',
        'status'      => $k['status'] ?? 'active',
      );
    }, array_values( $keys ) );

    return rest_ensure_response( array(
      'success'       => true,
      'authority'     => 'local',
      'entitled_tier' => '' === $entitled ? null : $entitled,
      'keys'          => $formatted,
    ) );
  }

  /**
   * Create a new API key through Gatekeeper authority.
   */
  public function rest_create_key( WP_REST_Request $request ) {
    $grant = Xophz_Compass_Xp_Key_Grants::resolve( $request );
    if ( is_wp_error( $grant ) ) {
      return $grant;
    }
    $label   = $grant['label'];
    $user_id = $grant['user_id'];
    $tier    = $grant['tier'];
    $scopes  = $grant['scopes'];

    // 1. Delegate to Gatekeeper when present
    if ( Xophz_Compass_Xp_Key_Grants::gatekeeper_active() ) {
      $key_data = Gatekeeper_Keys::generate_key(
        $user_id,
        $label,
        $scopes,
        'xp_live'
      );

      return rest_ensure_response( array(
        'success'   => true,
        'authority' => 'gatekeeper',
        'key'       => array(
          'id'         => $key_data['id'],
          'label'      => $key_data['name'],
          'tier'       => $tier,
          'masked_key' => $key_data['display_key'],
          'plain_key'  => $key_data['plain_key'], // Only exposed once upon creation
          'scopes'     => $key_data['scopes'],
          'created_at' => $key_data['created_at'],
          'status'     => 'active',
        ),
        'message'   => 'XP API key generated by Gatekeeper authority. Store plain_key securely; it will not be shown again.',
      ) );
    }

    // 2. Standalone fallback: store a SHA-256 hash and a masked hint, never the plain key.
    $key_id  = 'key_' . bin2hex( random_bytes( 6 ) );
    $secret  = 'xp_live_' . bin2hex( random_bytes( 16 ) );
    $created = current_time( 'mysql' );

    $keys = get_user_meta( $user_id, self::LOCAL_META, true );
    if ( ! is_array( $keys ) ) {
      $keys = array();
    }

    $keys[ $key_id ] = array(
      'id'         => $key_id,
      'key_hash'   => hash( 'sha256', $secret ),
      'masked_key' => self::mask_key( $secret ),
      'label'      => $label,
      'tier'       => $tier,
      'scopes'     => $scopes,
      'created_at' => $created,
      'last_used'  => 'Never',
      'status'     => 'active',
    );
    update_user_meta( $user_id, self::LOCAL_META, $keys );

    return rest_ensure_response( array(
      'success'   => true,
      'authority' => 'local',
      'key'       => array(
        'id'         => $key_id,
        'label'      => $label,
        'tier'       => $tier,
        'masked_key' => self::mask_key( $secret ),
        'plain_key'  => $secret, // Only exposed once upon creation
        'scopes'     => $scopes,
        'created_at' => $created,
        'status'     => 'active',
      ),
      'message'   => 'XP API key created. Store plain_key securely; it will not be shown again.',
    ) );
  }

  /**
   * Revoke an API key: the owner's own, or any with manage_options.
   *
   * A key the caller does not own gets the same 404 as a missing key, so key ids cannot be probed.
   */
  public function rest_revoke_key( WP_REST_Request $request ) {
    $user_id   = get_current_user_id();
    $is_admin  = current_user_can( Xophz_Compass_Xp_Key_Grants::ADMIN_CAPABILITY );
    $key_id    = sanitize_text_field( (string) $request->get_param( 'id' ) );
    $not_found = new WP_Error( 'key_not_found', 'API key not found.', array( 'status' => 404 ) );

    // Delegate to Gatekeeper when present
    if ( Xophz_Compass_Xp_Key_Grants::gatekeeper_active() ) {
      $record = null;
      foreach ( Gatekeeper_Keys::list_keys() as $k ) {
        if ( (string) ( $k['id'] ?? '' ) === $key_id ) {
          $record = $k;
          break;
        }
      }

      $is_owner = $record && (int) ( $record['user_id'] ?? 0 ) === (int) $user_id;
      if ( ! $record || ( ! $is_owner && ! $is_admin ) || ! Gatekeeper_Keys::revoke_key( $key_id ) ) {
        return $not_found;
      }

      return rest_ensure_response( array(
        'success' => true,
        'message' => 'API key revoked by Gatekeeper.',
      ) );
    }

    // Standalone fallback: the caller's own keys, or (administrators) any user's.
    $owner_ids = array( (int) $user_id );
    if ( $is_admin ) {
      $owner_ids = array_merge( $owner_ids, array_map( 'intval', get_users( array(
        'meta_key'     => self::LOCAL_META,
        'meta_compare' => 'EXISTS',
        'fields'       => 'ID',
      ) ) ) );
    }

    foreach ( array_unique( $owner_ids ) as $owner_id ) {
      $keys = get_user_meta( $owner_id, self::LOCAL_META, true );
      if ( is_array( $keys ) && isset( $keys[ $key_id ] ) ) {
        unset( $keys[ $key_id ] );
        update_user_meta( $owner_id, self::LOCAL_META, $keys );
        return rest_ensure_response( array(
          'success' => true,
          'message' => 'API key revoked successfully.',
        ) );
      }
    }

    return $not_found;
  }

  /**
   * Authenticate REST requests via Gatekeeper authority or local API key.
   *
   * The key is read from the X-API-Key header or Authorization: Bearer only.
   */
  public function authenticate_api_key( $result, $server, $request ) {
    $route = $request->get_route();

    if ( strpos( $route, '/xp/v1' ) !== 0 ) {
      return $result;
    }

    // A key in the URL is already in server and proxy logs: refuse it so the caller notices.
    $query = $request->get_query_params();
    if ( isset( $query['api_key'] ) && '' !== $query['api_key'] ) {
      return new WP_Error(
        'xp_api_key_in_url',
        'Send the API key in the X-API-Key header or as Authorization: Bearer, not in the URL. Treat a key that was sent in a URL as exposed and rotate it.',
        array( 'status' => 400 )
      );
    }

    $api_key = $request->get_header( 'x-api-key' ) ?: $request->get_header( 'x_api_key' );
    if ( empty( $api_key ) ) {
      $auth_header = $request->get_header( 'authorization' );
      if ( ! empty( $auth_header ) && preg_match( '/Bearer\s+([a-zA-Z0-9_\-]+)/i', $auth_header, $matches ) ) {
        $api_key = $matches[1];
      }
    }

    if ( empty( $api_key ) ) {
      return $result;
    }

    // 1. Primary: Validate through Gatekeeper
    if ( Xophz_Compass_Xp_Key_Grants::gatekeeper_active() ) {
      $validation = Gatekeeper_Keys::validate_key( (string) $api_key );
      if ( $validation['valid'] ) {
        if ( ! empty( $validation['user_id'] ) ) {
          wp_set_current_user( (int) $validation['user_id'] );
        }
        $request->set_param( '_gatekeeper_key_id', $validation['key_id'] ?? null );
        $request->set_param( '_gatekeeper_scopes', $validation['scopes'] ?? array() );
        $this->key_authenticated_request = $request;
        return $result;
      }
      // If key was explicitly passed but invalid, return error immediately
      return new WP_Error( 'gatekeeper_invalid_key', $validation['error'] ?? 'Invalid API key.', array( 'status' => 401 ) );
    }

    // 2. Standalone fallback: Scan local user meta (hashed keys; legacy plain keys are hashed on first use)
    $presented_hash = hash( 'sha256', (string) $api_key );
    $users = get_users( array(
      'meta_key'     => self::LOCAL_META,
      'meta_compare' => 'EXISTS',
    ) );

    foreach ( $users as $user ) {
      $keys = get_user_meta( $user->ID, self::LOCAL_META, true );
      if ( ! is_array( $keys ) ) {
        continue;
      }
      foreach ( $keys as $k_id => $k_data ) {
        $is_active  = ( $k_data['status'] ?? 'active' ) === 'active';
        $hash_match = isset( $k_data['key_hash'] ) && hash_equals( (string) $k_data['key_hash'], $presented_hash );
        $is_legacy  = ! $hash_match && isset( $k_data['key'] ) && hash_equals( (string) $k_data['key'], (string) $api_key );
        if ( ! $is_active || ( ! $hash_match && ! $is_legacy ) ) {
          continue;
        }
        if ( $is_legacy ) {
          $keys[ $k_id ]['key_hash']   = $presented_hash;
          $keys[ $k_id ]['masked_key'] = self::mask_key( (string) $api_key );
          unset( $keys[ $k_id ]['key'] );
        }
        $keys[ $k_id ]['last_used'] = current_time( 'mysql' );
        update_user_meta( $user->ID, self::LOCAL_META, $keys );
        wp_set_current_user( $user->ID );
        $this->key_authenticated_request = $request;
        return $result;
      }
    }

    return new WP_Error( 'xp_invalid_key', 'Invalid XP API key.', array( 'status' => 401 ) );
  }
}
