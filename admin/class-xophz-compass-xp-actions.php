<?php

/**
 * The admin-specific functionality for XP Actions.
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
    'xophz_compass_record_action' => ['record_action', 10, 3],
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
   * Register the generic xp_action Custom Post Type.
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
      'description'        => __( 'A granular user action logged for XP extraction.', 'xophz-compass-xp' ),
      'public'             => false, // Actions shouldn't have public URLs natively
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
      'supports'           => array( 'title', 'editor', 'author', 'custom-fields' )
    );

    register_post_type( 'xp_action', $args );

    /**
     * Provide a generic hook for other plugins (like YouMeOS/Event Horizon) 
     * to register their own gamification taxonomies to the xp_action CPT.
     */
    do_action( 'xophz_register_xp_taxonomies' );
  }

  /**
   * Record a new gamification action into the system
   * 
   * @param string $action_name  A recognizable name or slug for the action
   * @param int    $user_id      The ID of the user performing the action
   * @param array  $payload      Any contextual metadata related to the action
   */
  public function record_action( $action_name, $user_id, $payload = [] ) {
    $post_id = wp_insert_post([
      'post_title'   => sanitize_text_field($action_name) . ' - ' . current_time('mysql'),
      'post_name'    => sanitize_title($action_name . '-' . time()),
      'post_type'    => 'xp_action',
      'post_status'  => 'publish',
      'post_author'  => $user_id,
      'post_content' => wp_json_encode($payload, JSON_PRETTY_PRINT),
    ]);

    if ( !is_wp_error($post_id) ) {
      update_post_meta( $post_id, '_action_payload', $payload );
      update_post_meta( $post_id, '_action_name', sanitize_text_field($action_name) );
    }
  }

  /**
   * Add meta boxes for the xp_action CPT to view recorded data
   */
  public function add_action_meta_boxes() {
    add_meta_box(
      'xp_action_payload_box',
      __( 'Action Payload', 'xophz-compass-xp' ),
      [ $this, 'render_action_payload_box' ],
      'xp_action',
      'normal',
      'high'
    );
  }

  /**
   * Render the Action Payload meta box
   */
  public function render_action_payload_box( $post ) {
    $payload = get_post_meta( $post->ID, '_action_payload', true );
    $action_name = get_post_meta( $post->ID, '_action_name', true );
    
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
}
