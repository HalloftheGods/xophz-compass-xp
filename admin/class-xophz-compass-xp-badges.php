<?php

/**
 * The admin-specific functionality for XP Badges and Metrics.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */

class Xophz_Compass_Xp_Badges {

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'init' => 'register_xp_badge_cpt',
    'rest_api_init' => 'register_badge_rest_routes',
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
   * Register the xp_badge Custom Post Type.
   */
  public function register_xp_badge_cpt() {
    $labels = array(
      'name'               => __( 'XP Badges', 'post type general name', 'xophz-compass-xp' ),
      'singular_name'      => __( 'XP Badge', 'post type singular name', 'xophz-compass-xp' ),
      'menu_name'          => __( 'XP Badges', 'admin menu', 'xophz-compass-xp' ),
      'name_admin_bar'     => __( 'XP Badge', 'add new on admin bar', 'xophz-compass-xp' ),
      'add_new'            => __( 'Add New', 'xp_badge', 'xophz-compass-xp' ),
      'add_new_item'       => __( 'Add New XP Badge', 'xophz-compass-xp' ),
      'new_item'           => __( 'New XP Badge', 'xophz-compass-xp' ),
      'edit_item'          => __( 'Edit XP Badge', 'xophz-compass-xp' ),
      'view_item'          => __( 'View XP Badge', 'xophz-compass-xp' ),
      'all_items'          => __( 'All XP Badges', 'xophz-compass-xp' ),
      'search_items'       => __( 'Search XP Badges', 'xophz-compass-xp' ),
      'not_found'          => __( 'No XP Badges found.', 'xophz-compass-xp' ),
      'not_found_in_trash' => __( 'No XP Badges found in Trash.', 'xophz-compass-xp' )
    );

    $args = array(
      'labels'             => $labels,
      'description'        => __( 'A milestone badge unlocked by tracking metric counts.', 'xophz-compass-xp' ),
      'public'             => true,
      'publicly_queryable' => true,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'show_in_rest'       => true,
      'query_var'          => true,
      'capability_type'    => 'post',
      'has_archive'        => true,
      'hierarchical'       => false,
      'menu_position'      => null,
      'menu_icon'          => 'dashicons-awards',
      'supports'           => array( 'title', 'editor', 'thumbnail' )
    );

    register_post_type( 'xp_badge', $args );

    // Removed legacy meta fields, badge is now purely a visual asset awarded by Goals.
  }

  public function register_badge_rest_routes() {
    register_rest_route( 'xp/v1', '/my-badges', array(
      'methods'  => 'GET',
      'callback' => array( $this, 'rest_get_my_badges' ),
      'permission_callback' => function() { return is_user_logged_in(); },
    ));

    register_rest_route( 'xp/v1', '/my-metrics', array(
      'methods'  => 'GET',
      'callback' => array( $this, 'rest_get_my_metrics' ),
      'permission_callback' => function() { return is_user_logged_in(); },
    ));

    register_rest_route( 'xp/v1', '/badges', array(
      'methods'  => 'GET',
      'callback' => array( $this, 'rest_get_all_badges' ),
      'permission_callback' => function() { return is_user_logged_in(); },
    ));
  }

  public function rest_get_my_badges() {
    $user_id = get_current_user_id();
    $json = get_user_meta( $user_id, '_xp_unlocked_badges', true );
    $unlocked = empty( $json ) ? array() : json_decode( $json, true );
    return rest_ensure_response( $unlocked );
  }

  public function rest_get_my_metrics() {
    $user_id = get_current_user_id();
    $all_meta = get_user_meta( $user_id );
    $metrics = array();

    foreach ( $all_meta as $key => $values ) {
      $is_metric = strpos( $key, '_xp_metric_' ) === 0;
      if ( ! $is_metric ) continue;

      $metric_slug = str_replace( '_xp_metric_', '', $key );
      $label = ucwords( str_replace( '_', ' ', $metric_slug ) );
      $metrics[] = array(
        'key'   => $metric_slug,
        'label' => $label,
        'count' => (float) $values[0],
      );
    }

    return rest_ensure_response( $metrics );
  }

  public function rest_get_all_badges() {
    $badges = get_posts( array(
      'post_type'      => 'xp_badge',
      'posts_per_page' => -1,
      'post_status'    => 'publish',
    ));

    $user_id = get_current_user_id();
    $unlocked_json = get_user_meta( $user_id, '_xp_unlocked_badges', true );
    $unlocked = empty( $unlocked_json ) ? array() : json_decode( $unlocked_json, true );

    $formatted = array();
    foreach ( $badges as $b ) {
      $formatted[] = array(
        'id'          => $b->ID,
        'title'       => $b->post_title,
        'description' => $b->post_content,
        'thumbnail'   => get_the_post_thumbnail_url( $b->ID, 'medium' ) ?: '',
        'unlocked'    => in_array( $b->ID, $unlocked ),
      );
    }

    return rest_ensure_response( $formatted );
  }

}
