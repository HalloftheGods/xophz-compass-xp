<?php

/**
 * The admin-specific functionality for XP Actions (The Builder).
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */

class Xophz_Compass_Xp_Actions {

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'init' => 'register_xp_action_cpt',
    'add_meta_boxes' => 'add_action_meta_boxes',
    'save_post_xp_action' => ['save_action_meta', 10, 3],
    'rest_api_init' => 'register_action_rest_routes',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
   * Register the xp_action Custom Post Type for defining webhook triggers.
   */
  public function register_xp_action_cpt() {
    $labels = array(
      'name'               => __( 'XP Actions', 'post type general name', 'xophz-compass-xp' ),
      'singular_name'      => __( 'XP Action', 'post type singular name', 'xophz-compass-xp' ),
      'menu_name'          => __( 'XP Actions', 'admin menu', 'xophz-compass-xp' ),
      'name_admin_bar'     => __( 'XP Action', 'add new on admin bar', 'xophz-compass-xp' ),
      'add_new'            => __( 'Add New', 'xp_action', 'xophz-compass-xp' ),
      'add_new_item'       => __( 'Add New XP Action', 'xophz-compass-xp' ),
      'new_item'           => __( 'New XP Action', 'xophz-compass-xp' ),
      'edit_item'          => __( 'Edit XP Action', 'xophz-compass-xp' ),
      'view_item'          => __( 'View XP Action', 'xophz-compass-xp' ),
      'all_items'          => __( 'All XP Actions', 'xophz-compass-xp' ),
      'search_items'       => __( 'Search XP Actions', 'xophz-compass-xp' ),
      'not_found'          => __( 'No XP Actions found.', 'xophz-compass-xp' ),
      'not_found_in_trash' => __( 'No XP Actions found in Trash.', 'xophz-compass-xp' )
    );

    $args = array(
      'labels'             => $labels,
      'description'        => __( 'Defined gamification triggers and webhook endpoints.', 'xophz-compass-xp' ),
      'public'             => false,
      'publicly_queryable' => false,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'show_in_rest'       => true,
      'query_var'          => true,
      'capability_type'    => 'post',
      'has_archive'        => false,
      'hierarchical'       => false,
      'menu_position'      => null,
      'menu_icon'          => 'dashicons-hammer',
      'supports'           => array( 'title', 'editor' )
    );

    register_post_type( 'xp_action', $args );
  }

  public function add_action_meta_boxes() {
    add_meta_box(
      'xp_action_config_box',
      __( 'Action Configuration & Webhook', 'xophz-compass-xp' ),
      [ $this, 'render_action_config_box' ],
      'xp_action',
      'normal',
      'high'
    );
  }

  public function render_action_config_box( $post ) {
    wp_nonce_field( 'xp_action_config_save', 'xp_action_config_nonce' );

    $action_slug = get_post_meta( $post->ID, '_xp_action_slug', true );
    $action_token = get_post_meta( $post->ID, '_xp_action_token', true );

    // Auto-generate token if not exists
    if ( empty($action_token) ) {
      $action_token = bin2hex(random_bytes(16));
      update_post_meta( $post->ID, '_xp_action_token', $action_token );
    }

    if ( empty($action_slug) ) {
      $action_slug = $post->post_name; // Fallback to WP slug
    }

    $webhook_url = rest_url( 'xp/v1/fire/' . $action_slug );
    ?>
    <p>
      <label for="xp_action_slug"><strong>Action Slug (Metric Key)</strong></label><br>
      <input type="text" id="xp_action_slug" name="xp_action_slug" value="<?php echo esc_attr( $action_slug ); ?>" style="width: 100%; max-width: 400px;">
      <br><small>This is the unique identifier (e.g., <code>attended_workshop</code>) that Badges will track.</small>
    </p>

    <p>
      <label for="xp_action_token"><strong>Security Token</strong></label><br>
      <input type="text" id="xp_action_token" name="xp_action_token" value="<?php echo esc_attr( $action_token ); ?>" style="width: 100%; max-width: 400px;" readonly>
      <br><small>Required for external webhook triggers.</small>
    </p>

    <hr>
    <h4>Clean URL Endpoint</h4>
    <p>You can trigger this action externally by sending a GET or POST request to the following URL:</p>
    <pre style="background: #1e1e1e; color: #a6e22e; padding: 15px; border-radius: 5px; overflow-x: auto;">
<?php echo esc_html( $webhook_url . '?token=' . $action_token . '&user_id=USER_ID_HERE' ); ?>
    </pre>
    <p><em>Note: You must pass either <code>user_id</code> or <code>email</code> as a parameter so the system knows who to award the action to. If the user is logged in natively, this parameter can be omitted.</em></p>
    <?php
  }

  public function save_action_meta( $post_id, $post, $update ) {
    if ( ! isset( $_POST['xp_action_config_nonce'] ) || ! wp_verify_nonce( $_POST['xp_action_config_nonce'], 'xp_action_config_save' ) ) {
      return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

    if ( isset( $_POST['xp_action_slug'] ) ) {
      update_post_meta( $post_id, '_xp_action_slug', sanitize_title( $_POST['xp_action_slug'] ) );
    }
  }

  public function register_action_rest_routes() {
    register_rest_route( 'xp/v1', '/fire/(?P<action_slug>[a-zA-Z0-9_-]+)', array(
      'methods'  => ['GET', 'POST'],
      'callback' => array( $this, 'rest_fire_action' ),
      'permission_callback' => '__return_true', // Validation handled in callback via token
    ));

    register_rest_route( 'xp/v1', '/actions', array(
      'methods'  => WP_REST_Server::READABLE,
      'callback' => array( $this, 'rest_get_actions' ),
      'permission_callback' => function () {
        return current_user_can( 'edit_posts' );
      }
    ));

    register_rest_route( 'xp/v1', '/actions(?:/(?P<id>\d+))?', array(
      'methods'  => WP_REST_Server::CREATABLE,
      'callback' => array( $this, 'rest_save_action' ),
      'permission_callback' => function () {
        return current_user_can( 'edit_posts' );
      }
    ));
  }

  public function rest_get_actions() {
    $args = array(
      'post_type' => 'xp_action',
      'posts_per_page' => -1,
      'post_status' => 'any'
    );
    $query = new WP_Query($args);
    $actions = [];

    foreach ($query->posts as $post) {
      $slug = get_post_meta( $post->ID, '_xp_action_slug', true );
      $token = get_post_meta( $post->ID, '_xp_action_token', true );

      if ( empty($slug) ) {
        $slug = $post->post_name;
      }

      $actions[] = array(
        'id' => $post->ID,
        'title' => $post->post_title,
        'status' => $post->post_status,
        'action_slug' => $slug,
        'token' => $token,
        'icon' => get_post_meta( $post->ID, '_xp_action_icon', true ) ?: 'fas fa-bolt',
        'data_schema' => json_decode( get_post_meta( $post->ID, '_xp_data_schema', true ), true ) ?: [],
        'webhook_url' => rest_url( 'xp/v1/fire/' . $slug )
      );
    }

    return rest_ensure_response($actions);
  }

  public function rest_save_action( WP_REST_Request $request ) {
    $id = $request->get_param( 'id' );
    $params = $request->get_json_params();
    
    $title = sanitize_text_field( $params['title'] ?? 'New Action' );
    $status = sanitize_text_field( $params['status'] ?? 'publish' );
    $action_slug = sanitize_title( $params['action_slug'] ?? '' );
    
    if ( empty($action_slug) ) {
      $action_slug = sanitize_title( $title );
    }

    $post_data = array(
      'post_title'   => $title,
      'post_status'  => $status,
      'post_type'    => 'xp_action',
      'post_name'    => $action_slug
    );

    if ( $id ) {
      $post_data['ID'] = $id;
      $post_id = wp_update_post( $post_data );
    } else {
      $post_id = wp_insert_post( $post_data );
      // Auto-generate token for new actions
      update_post_meta( $post_id, '_xp_action_token', bin2hex(random_bytes(16)) );
    }

    if ( is_wp_error( $post_id ) ) {
      return new WP_Error( 'save_failed', 'Failed to save action', array( 'status' => 500 ) );
    }

    update_post_meta( $post_id, '_xp_action_slug', $action_slug );

    if ( isset( $params['data_schema'] ) && is_array( $params['data_schema'] ) ) {
      $clean_schema = array_map( function( $field ) {
        return array(
          'key'         => sanitize_text_field( $field['key'] ?? '' ),
          'type'        => sanitize_text_field( $field['type'] ?? 'string' ),
          'description' => sanitize_text_field( $field['description'] ?? '' ),
        );
      }, $params['data_schema'] );
      update_post_meta( $post_id, '_xp_data_schema', wp_json_encode( $clean_schema ) );
    }

    if ( isset( $params['icon'] ) ) {
      update_post_meta( $post_id, '_xp_action_icon', sanitize_text_field( $params['icon'] ) );
    }

    $token = get_post_meta( $post_id, '_xp_action_token', true );

    return rest_ensure_response( array(
      'id' => $post_id,
      'title' => $title,
      'status' => $status,
      'action_slug' => $action_slug,
      'token' => $token,
      'icon' => get_post_meta( $post_id, '_xp_action_icon', true ),
      'data_schema' => $clean_schema ?? [],
      'webhook_url' => rest_url( 'xp/v1/fire/' . $action_slug )
    ));
  }

  public function rest_fire_action( WP_REST_Request $request ) {
    $action_slug = $request->get_param( 'action_slug' );
    $token = $request->get_param( 'token' );
    
    // Find the registered action
    $actions = get_posts(array(
      'post_type' => 'xp_action',
      'meta_key' => '_xp_action_slug',
      'meta_value' => $action_slug,
      'posts_per_page' => 1,
      'post_status' => 'publish'
    ));

    if ( empty($actions) ) {
      return new WP_Error( 'not_found', 'Action not found', array( 'status' => 404 ) );
    }

    $action_post = $actions[0];
    $valid_token = get_post_meta( $action_post->ID, '_xp_action_token', true );

    // Check auth
    $is_logged_in = is_user_logged_in();
    if ( !$is_logged_in && (empty($token) || $token !== $valid_token) ) {
      return new WP_Error( 'unauthorized', 'Invalid or missing security token', array( 'status' => 401 ) );
    }

    // Determine target user
    $user_id = $request->get_param( 'user_id' );
    $email = $request->get_param( 'email' );

    if ( empty($user_id) && !empty($email) ) {
      $user = get_user_by( 'email', $email );
      if ( $user ) {
        $user_id = $user->ID;
      }
    }

    if ( empty($user_id) && $is_logged_in ) {
      $user_id = get_current_user_id();
    }

    if ( empty($user_id) ) {
      return new WP_Error( 'missing_user', 'A user_id or email must be provided to fire an action.', array( 'status' => 400 ) );
    }

    // Collect payload (everything else passed in the request)
    $payload = $request->get_params();
    unset($payload['action_slug']);
    unset($payload['token']);
    unset($payload['user_id']);
    unset($payload['email']);

    // Fire the internal gamification hook!
    do_action( 'xophz_compass_record_action', $action_slug, $user_id, $payload );

    return rest_ensure_response( array(
      'success' => true,
      'action' => $action_slug,
      'user_id' => $user_id,
      'message' => 'Action successfully recorded and logged.'
    ));
  }
}
