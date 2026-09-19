<?php

/**
 * API Key Authority & Gatekeeper Bridge for XP SaaS.
 *
 * Delegates key generation, hashing, scoping, and REST authentication to
 * Xophz Gatekeeper when present, with a graceful local fallback for standalone installs.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */

class Xophz_Compass_Xp_Keys {

  private $plugin_name;
  private $version;

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
        'permission_callback' => array( $this, 'check_user_auth' ),
      ),
      array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => array( $this, 'rest_create_key' ),
        'permission_callback' => array( $this, 'check_user_auth' ),
      ),
    ) );

    register_rest_route( 'xp/v1', '/keys/(?P<id>[a-zA-Z0-9_-]+)', array(
      array(
        'methods'             => WP_REST_Server::DELETABLE,
        'callback'            => array( $this, 'rest_revoke_key' ),
        'permission_callback' => array( $this, 'check_user_auth' ),
      ),
    ) );
  }

  /**
   * Check if request is authenticated via WP session or capabilities.
   */
  public function check_user_auth( $request ) {
    return is_user_logged_in() || current_user_can( 'read' ) || ! empty( get_current_user_id() );
  }

  /**
   * List API keys for current user or site administrator.
   */
  public function rest_get_keys( WP_REST_Request $request ) {
    $user_id = get_current_user_id() ?: 1;

    // Delegate to Gatekeeper Key Authority when available
    if ( class_exists( 'Gatekeeper_Keys' ) ) {
      $all_keys  = Gatekeeper_Keys::list_keys();
      $is_admin  = current_user_can( 'manage_options' );
      $formatted = array();

      foreach ( $all_keys as $k ) {
        $is_owner     = (int) ( $k['user_id'] ?? 0 ) === (int) $user_id;
        $is_xp_prefix = strpos( $k['prefix'] ?? '', 'xp_' ) === 0 || strpos( $k['display_key'] ?? '', 'xp_' ) === 0;

        // Show keys owned by user or, for admins, show all xp-scoped keys
        if ( $is_owner || ( $is_admin && $is_xp_prefix ) ) {
          $tier = 'developer';
          if ( ! empty( $k['scopes'] ) ) {
            if ( in_array( 'admin:all', $k['scopes'], true ) || in_array( 'xp:admin', $k['scopes'], true ) ) {
              $tier = 'enterprise';
            } elseif ( in_array( 'xp:write', $k['scopes'], true ) ) {
              $tier = 'developer';
            } else {
              $tier = 'hobby';
            }
          }

          $formatted[] = array(
            'id'          => $k['id'],
            'label'       => $k['name'] ?? 'XP Key',
            'tier'        => $tier,
            'masked_key'  => $k['display_key'] ?? ( $k['prefix'] . '...' ),
            'key'         => $k['display_key'] ?? '',
            'scopes'      => $k['scopes'] ?? array( 'xp:read' ),
            'created_at'  => $k['created_at'] ?? '',
            'last_used'   => ! empty( $k['last_used_at'] ) ? $k['last_used_at'] : 'Never',
            'status'      => $k['status'] ?? 'active',
          );
        }
      }

      return rest_ensure_response( array(
        'success'   => true,
        'authority' => 'gatekeeper',
        'keys'      => $formatted,
      ) );
    }

    // Local fallback when running standalone without Gatekeeper
    $keys = get_user_meta( $user_id, '_xp_api_keys', true );
    if ( ! is_array( $keys ) ) {
      $keys = array();
    }

    $formatted = array_map( function( $k ) {
      $prefix = substr( $k['key'] ?? 'xp_live_', 0, 12 );
      return array(
        'id'          => $k['id'],
        'label'       => $k['label'] ?? 'API Key',
        'tier'        => $k['tier'] ?? 'developer',
        'masked_key'  => $prefix . '...' . substr( $k['key'] ?? '0000', -4 ),
        'key'         => $k['key'] ?? '',
        'scopes'      => $k['scopes'] ?? array( 'xp:read', 'xp:write' ),
        'created_at'  => $k['created_at'] ?? date( 'Y-m-d H:i:s' ),
        'last_used'   => $k['last_used'] ?? 'Never',
        'status'      => $k['status'] ?? 'active',
      );
    }, array_values( $keys ) );

    return rest_ensure_response( array(
      'success'   => true,
      'authority' => 'local',
      'keys'      => $formatted,
    ) );
  }

  /**
   * Create a new API key through Gatekeeper authority.
   */
  public function rest_create_key( WP_REST_Request $request ) {
    $user_id = get_current_user_id() ?: 1;
    $params  = $request->get_json_params() ?: array();

    $label = sanitize_text_field( $params['label'] ?? $request->get_param( 'label' ) ?: 'Production Key' );
    $tier  = sanitize_key( $params['tier'] ?? $request->get_param( 'tier' ) ?: 'developer' );

    // Determine scopes based on selected tier
    $tier_scopes = array(
      'hobby'      => array( 'xp:read', 'xp:actions:trigger' ),
      'developer'  => array( 'xp:read', 'xp:write', 'xp:actions:trigger', 'xp:leaderboards' ),
      'enterprise' => array( 'xp:read', 'xp:write', 'xp:actions:trigger', 'xp:leaderboards', 'xp:bank', 'xp:admin' ),
    );
    $scopes = $tier_scopes[ $tier ] ?? $tier_scopes['developer'];

    // If custom scopes provided, merge them
    if ( ! empty( $params['scopes'] ) && is_array( $params['scopes'] ) ) {
      $scopes = array_unique( array_merge( $scopes, array_map( 'sanitize_key', $params['scopes'] ) ) );
    }

    // 1. Delegate to Gatekeeper when present
    if ( class_exists( 'Gatekeeper_Keys' ) ) {
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

    // 2. Standalone fallback
    $key_id  = 'key_' . bin2hex( random_bytes( 6 ) );
    $secret  = 'xp_live_' . bin2hex( random_bytes( 16 ) );
    $created = date( 'Y-m-d H:i:s' );

    $keys = get_user_meta( $user_id, '_xp_api_keys', true );
    if ( ! is_array( $keys ) ) {
      $keys = array();
    }

    $new_key = array(
      'id'         => $key_id,
      'key'        => $secret,
      'label'      => $label,
      'tier'       => $tier,
      'scopes'     => $scopes,
      'created_at' => $created,
      'last_used'  => 'Never',
      'status'     => 'active',
    );

    $keys[ $key_id ] = $new_key;
    update_user_meta( $user_id, '_xp_api_keys', $keys );

    return rest_ensure_response( array(
      'success'   => true,
      'authority' => 'local',
      'key'       => array(
        'id'         => $key_id,
        'label'      => $label,
        'tier'       => $tier,
        'masked_key' => substr( $secret, 0, 12 ) . '...' . substr( $secret, -4 ),
        'plain_key'  => $secret,
        'scopes'     => $scopes,
        'created_at' => $created,
        'status'     => 'active',
      ),
      'message'   => 'XP API key successfully created.',
    ) );
  }

  /**
   * Revoke an API key.
   */
  public function rest_revoke_key( WP_REST_Request $request ) {
    $user_id = get_current_user_id() ?: 1;
    $key_id  = sanitize_text_field( (string) $request->get_param( 'id' ) );

    // Delegate to Gatekeeper when present
    if ( class_exists( 'Gatekeeper_Keys' ) ) {
      $revoked = Gatekeeper_Keys::revoke_key( $key_id );
      if ( $revoked ) {
        return rest_ensure_response( array(
          'success' => true,
          'message' => "Key '{$key_id}' successfully revoked by Gatekeeper.",
        ) );
      }
      return new WP_Error( 'key_not_found', 'API key not found in Gatekeeper authority.', array( 'status' => 404 ) );
    }

    // Standalone fallback
    $keys = get_user_meta( $user_id, '_xp_api_keys', true );
    if ( is_array( $keys ) && isset( $keys[ $key_id ] ) ) {
      unset( $keys[ $key_id ] );
      update_user_meta( $user_id, '_xp_api_keys', $keys );
      return rest_ensure_response( array(
        'success' => true,
        'message' => 'API key revoked successfully.',
      ) );
    }

    return new WP_Error( 'key_not_found', 'API key not found.', array( 'status' => 404 ) );
  }

  /**
   * Authenticate REST requests via Gatekeeper authority or local API key.
   */
  public function authenticate_api_key( $result, $server, $request ) {
    $route = $request->get_route();

    if ( strpos( $route, '/xp/v1' ) !== 0 ) {
      return $result;
    }

    $api_key = $request->get_header( 'x-api-key' ) ?: $request->get_header( 'x_api_key' );
    if ( empty( $api_key ) ) {
      $auth_header = $request->get_header( 'authorization' );
      if ( ! empty( $auth_header ) && preg_match( '/Bearer\s+([a-zA-Z0-9_\-]+)/i', $auth_header, $matches ) ) {
        $api_key = $matches[1];
      }
    }

    if ( empty( $api_key ) ) {
      $api_key = $request->get_param( 'api_key' );
    }

    if ( empty( $api_key ) ) {
      return $result;
    }

    // 1. Primary: Validate through Gatekeeper
    if ( class_exists( 'Gatekeeper_Keys' ) ) {
      $validation = Gatekeeper_Keys::validate_key( (string) $api_key );
      if ( $validation['valid'] ) {
        if ( ! empty( $validation['user_id'] ) ) {
          wp_set_current_user( (int) $validation['user_id'] );
        }
        $request->set_param( '_gatekeeper_key_id', $validation['key_id'] ?? null );
        $request->set_param( '_gatekeeper_scopes', $validation['scopes'] ?? array() );
        return $result;
      }
      // If key was explicitly passed but invalid, return error immediately
      return new WP_Error( 'gatekeeper_invalid_key', $validation['error'] ?? 'Invalid API key.', array( 'status' => 401 ) );
    }

    // 2. Standalone fallback: Scan local user meta
    $users = get_users( array(
      'meta_key'     => '_xp_api_keys',
      'meta_compare' => 'EXISTS',
    ) );

    foreach ( $users as $user ) {
      $keys = get_user_meta( $user->ID, '_xp_api_keys', true );
      if ( is_array( $keys ) ) {
        foreach ( $keys as $k_id => $k_data ) {
          if ( isset( $k_data['key'] ) && hash_equals( $k_data['key'], (string) $api_key ) ) {
            $keys[ $k_id ]['last_used'] = date( 'Y-m-d H:i:s' );
            update_user_meta( $user->ID, '_xp_api_keys', $keys );
            wp_set_current_user( $user->ID );
            return $result;
          }
        }
      }
    }

    return new WP_Error( 'xp_invalid_key', 'Invalid XP API key.', array( 'status' => 401 ) );
  }
}
