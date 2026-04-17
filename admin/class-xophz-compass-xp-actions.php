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
      'show_in_menu'       => 'edit.php?post_type=xp_ability', // Put under Abilities or generic XP menu
      'show_in_rest'       => true,
      'query_var'          => true,
      'capability_type'    => 'post',
      'has_archive'        => false,
      'hierarchical'       => false,
      'menu_position'      => null,
      'supports'           => array( 'title', 'editor', 'author', 'custom-fields' )
    );

    register_post_type( 'xp_action', $args );

    /**
     * Provide a generic hook for other plugins (like YouMeOS/Event Horizon) 
     * to register their own gamification taxonomies to the xp_action CPT.
     */
    do_action( 'xophz_register_xp_taxonomies' );
  }
}
