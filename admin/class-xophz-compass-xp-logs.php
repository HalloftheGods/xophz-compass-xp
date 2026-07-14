<?php

/**
 * The admin-specific functionality for XP Actions.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */

class Xophz_Compass_Xp_Logs {

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'init' => 'register_xp_log_cpt',
    'add_meta_boxes' => 'add_log_meta_boxes',
    'xophz_compass_record_action' => ['record_log', 10, 3],
    'rest_api_init' => 'register_log_rest_routes',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
   * Register the generic xp_log Custom Post Type.
   */
  public function register_xp_log_cpt() {
    $labels = array(
      'name'               => __( 'XP Logs', 'post type general name', 'xophz-compass-xp' ),
      'singular_name'      => __( 'XP Log', 'post type singular name', 'xophz-compass-xp' ),
      'menu_name'          => __( 'XP Logs', 'admin menu', 'xophz-compass-xp' ),
      'name_admin_bar'     => __( 'XP Log', 'add new on admin bar', 'xophz-compass-xp' ),
      'add_new'            => __( 'Add New', 'xp_log', 'xophz-compass-xp' ),
      'add_new_item'       => __( 'Add New XP Log', 'xophz-compass-xp' ),
      'new_item'           => __( 'New XP Log', 'xophz-compass-xp' ),
      'edit_item'          => __( 'Edit XP Log', 'xophz-compass-xp' ),
      'view_item'          => __( 'View XP Log', 'xophz-compass-xp' ),
      'all_items'          => __( 'All XP Logs', 'xophz-compass-xp' ),
      'search_items'       => __( 'Search XP Logs', 'xophz-compass-xp' ),
      'not_found'          => __( 'No XP Logs found.', 'xophz-compass-xp' ),
      'not_found_in_trash' => __( 'No XP Logs found in Trash.', 'xophz-compass-xp' )
    );

    $args = array(
      'labels'             => $labels,
      'description'        => __( 'A granular user action logged for XP extraction.', 'xophz-compass-xp' ),
      'public'             => false, // Logs shouldn't have public URLs natively
      'publicly_queryable' => false,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'show_in_rest'       => true,
      'query_var'          => true,
      'capability_type'    => 'post',
      'has_archive'        => false,
      'hierarchical'       => false,
      'menu_position'      => null,
      'menu_icon'          => 'dashicons-media-text', // changing to a log icon
      'supports'           => array( 'title', 'editor', 'author', 'custom-fields' )
    );

    register_post_type( 'xp_log', $args );

    /**
     * Provide a generic hook for other plugins (like YouMeOS/Event Horizon) 
     * to register their own gamification taxonomies to the xp_log CPT.
     */
    do_action( 'xophz_register_xp_taxonomies' );
  }

  /**
   * Record a new gamification log into the system
   * 
   * @param string $action_name  A recognizable name or slug for the action
   * @param int    $user_id      The ID of the user performing the action
   * @param array  $payload      Any contextual metadata related to the action
   */
  public function record_log( $action_name, $user_id, $payload = [] ) {
    $post_id = wp_insert_post([
      'post_title'   => sanitize_text_field($action_name) . ' - ' . current_time('mysql'),
      'post_name'    => sanitize_title($action_name . '-' . time()),
      'post_type'    => 'xp_log',
      'post_status'  => 'publish',
      'post_author'  => $user_id,
      'post_content' => wp_json_encode($payload, JSON_PRETTY_PRINT),
    ]);

    if ( !is_wp_error($post_id) ) {
      update_post_meta( $post_id, '_log_payload', $payload );
      update_post_meta( $post_id, '_log_action_name', sanitize_text_field($action_name) );
    }
  }

  /**
   * Add meta boxes for the xp_log CPT to view recorded data
   */
  public function add_log_meta_boxes() {
    add_meta_box(
      'xp_log_payload_box',
      __( 'Log Payload', 'xophz-compass-xp' ),
      [ $this, 'render_log_payload_box' ],
      'xp_log',
      'normal',
      'high'
    );
  }

  /**
   * Render the Log Payload meta box
   */
  public function render_log_payload_box( $post ) {
    $payload = get_post_meta( $post->ID, '_log_payload', true );
    $action_name = get_post_meta( $post->ID, '_log_action_name', true );
    
    echo '<p><strong>Action Name:</strong> <code>' . esc_html($action_name ?: 'N/A') . '</code></p>';
    echo '<p><strong>Raw Payload:</strong></p>';
    echo '<pre style="background: #1e1e1e; color: #a6e22e; padding: 15px; border-radius: 5px; overflow-x: auto;">';
    if ( !empty($payload) ) {
      echo esc_html( print_r( $payload, true ) );
    } else {
      echo 'No payload recorded.';
    }
    echo '</pre>';
  }

  public function register_log_rest_routes() {
    register_rest_route( 'xp/v1', '/logs', array(
      'methods'  => 'GET',
      'callback' => array( $this, 'rest_get_logs' ),
      'permission_callback' => function() { return is_user_logged_in(); },
    ));
  }

  public function rest_get_logs() {
    $user_id = get_current_user_id();
    
    $args = [
        'post_type'      => 'xp_log',
        'author'         => $user_id,
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        'orderby'        => 'date',
        'order'          => 'DESC'
    ];
    
    $logs = get_posts($args);
    $out = [];
    
    foreach ($logs as $log) {
        $payload = get_post_meta($log->ID, '_log_payload', true);
        $action_name = get_post_meta($log->ID, '_log_action_name', true);
        
        $out[] = [
            'id'        => $log->ID,
            'timestamp' => $log->post_date,
            'message'   => $action_name,
            'type'      => 'api_call',
            'xpAdded'   => isset($payload['xp']) ? (int)$payload['xp'] : (isset($payload['xp_reward']) ? (int)$payload['xp_reward'] : 0),
            'payload'   => $payload
        ];
    }
    
    return rest_ensure_response($out);
  }
}
