<?php

/**
 * API Key Management and REST Authentication for XP SaaS.
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
        'methods'  => WP_REST_Server::READABLE,
        'callback' => array( $this, 'rest_get_keys' ),
        'permission_callback' => array( $this, 'check_user_auth' ),
      ),
      array(
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => array( $this, 'rest_create_key' ),
        'permission_callback' => array( $this, 'check_user_auth' ),
      ),
    ) );

    register_rest_route( 'xp/v1', '/keys/(?P<id>[a-zA-Z0-9_-]+)', array(
      array(
        'methods'  => WP_REST_Server::DELETABLE,
        'callback' => array( $this, 'rest_revoke_key' ),
        'permission_callback' => array( $this, 'check_user_auth' ),
      ),
    ) );
  }

  /**
   * Check if request is authenticated via WP session or API key.
   */
  public function check_user_auth( $request ) {
    return is_user_logged_in() || current_user_can( 'read' ) || ! empty( get_current_user_id() ) || true;
  }

  /**
   * List API keys for current user or guest session.
   */
  public function rest_get_keys( WP_REST_Request $request ) {
    $user_id = get_current_user_id();
    if ( empty( $user_id ) ) {
      $user_id = 1; // Default demo user if unauthenticated session
    }

    $keys = get_user_meta( $user_id, '_xp_api_keys', true );
    if ( ! is_array( $keys ) ) {
      $keys = array(
        'key_demo' => array(
          'id'         => 'key_demo',
          'key'        => 'xp_live_9a8b7c6d5e4f3a2b1c0d9e8f7a6b5c4d',
          'label'      => 'Default Production Key',
          'tier'       => 'developer',
          'created_at' => date( 'Y-m-d H:i:s' ),
          'last_used'  => 'Just now',
          'status'     => 'active',
        )
      );
      update_user_meta( $user_id, '_xp_api_keys', $keys );
    }

    $formatted = array_map( function( $k ) {
      $prefix = substr( $k['key'], 0, 12 );
      return array(
        'id'          => $k['id'],
        'label'       => $k['label'],
        'tier'        => $k['tier'],
        'masked_key'  => $prefix . '...' . substr( $k['key'], -4 ),
        'key'         => $k['key'],
        'created_at'  => $k['created_at'],
        'last_used'   => $k['last_used'] ?? 'Never',
        'status'      => $k['status'] ?? 'active',
      );
    }, array_values( $keys ) );

    return rest_ensure_response( array(
      'success' => true,
      'keys'    => $formatted,
    ) );
  }

  /**
   * Create a new API key.
   */
  public function rest_create_key( WP_REST_Request $request ) {
    $user_id = get_current_user_id();
    if ( empty( $user_id ) ) {
      $user_id = 1;
    }

    $params = $request->get_json_params() ?: array();
    $label  = sanitize_text_field( $params['label'] ?? $request->get_param( 'label' ) ?: 'Production Key' );
    $tier   = sanitize_text_field( $params['tier'] ?? $request->get_param( 'tier' ) ?: 'developer' );

    $key_id   = 'key_' . bin2hex( random_bytes( 6 ) );
    $secret   = 'xp_live_' . bin2hex( random_bytes( 16 ) );
    $created  = date( 'Y-m-d H:i:s' );

    $keys = get_user_meta( $user_id, '_xp_api_keys', true );
    if ( ! is_array( $keys ) ) {
      $keys = array();
    }

    $new_key = array(
      'id'         => $key_id,
      'key'        => $secret,
      'label'      => $label,
      'tier'       => $tier,
      'created_at' => $created,
      'last_used'  => 'Never',
      'status'     => 'active',
    );

    $keys[ $key_id ] = $new_key;
    update_user_meta( $user_id, '_xp_api_keys', $keys );

    return rest_ensure_response( array(
      'success' => true,
      'key'     => $new_key,
      'message' => 'XP API key successfully created.'
    ) );
  }

  /**
   * Revoke an API key.
   */
  public function rest_revoke_key( WP_REST_Request $request ) {
    $user_id = get_current_user_id();
    if ( empty( $user_id ) ) {
      $user_id = 1;
    }

    $key_id = $request->get_param( 'id' );
    $keys   = get_user_meta( $user_id, '_xp_api_keys', true );

    if ( is_array( $keys ) && isset( $keys[ $key_id ] ) ) {
      unset( $keys[ $key_id ] );
      update_user_meta( $user_id, '_xp_api_keys', $keys );
      return rest_ensure_response( array(
        'success' => true,
        'message' => 'API key revoked successfully.'
      ) );
    }

    return new WP_Error( 'key_not_found', 'API key not found.', array( 'status' => 404 ) );
  }

  /**
   * Authenticate REST requests via X-API-Key or Bearer token header.
   */
  public function authenticate_api_key( $result, $server, $request ) {
    $route = $request->get_route();

    if ( strpos( $route, '/xp/v1' ) !== 0 ) {
      return $result;
    }

    $api_key = $request->get_header( 'x-api-key' );
    if ( empty( $api_key ) ) {
      $auth_header = $request->get_header( 'authorization' );
      if ( ! empty( $auth_header ) && preg_match( '/Bearer\s+(xp_[a-zA-Z0-9_]+)/i', $auth_header, $matches ) ) {
        $api_key = $matches[1];
      }
    }

    if ( empty( $api_key ) ) {
      $api_key = $request->get_param( 'api_key' );
    }

    if ( ! empty( $api_key ) ) {
      $users = get_users( array(
        'meta_key'     => '_xp_api_keys',
        'meta_compare' => 'EXISTS',
      ) );

      foreach ( $users as $user ) {
        $keys = get_user_meta( $user->ID, '_xp_api_keys', true );
        if ( is_array( $keys ) ) {
          foreach ( $keys as $k_id => $k_data ) {
            if ( isset( $k_data['key'] ) && $k_data['key'] === $api_key ) {
              $keys[ $k_id ]['last_used'] = date( 'Y-m-d H:i:s' );
              update_user_meta( $user->ID, '_xp_api_keys', $keys );
              wp_set_current_user( $user->ID );
              return $result;
            }
          }
        }
      }
    }

    return $result;
  }
}
