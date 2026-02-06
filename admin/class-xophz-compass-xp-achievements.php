<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 * @author     Your Name <email@example.com>
 */
class Xophz_Compass_Xp_Achievements {

  /**
  * The ID of this plugin.
  *
  * @since    1.0.0
  * @access   private
  * @var      string    $plugin_name    The ID of this plugin.
  */
  private $plugin_name;

  /**
  * The version of this plugin.
  *
  * @since    1.0.0
  * @access   private
  * @var      string    $version    The current version of this plugin.
  */
  private $version;

  private $post_type = "xp_achievement";

  public $action_hooks = [
    'init' => [
      'create_achievement_taxonomy',
      'codex_achievement_init',
    ],
    'add_meta_boxes'                     => 'meta_boxes',
    'manage_edit-xp_achievement_columns' => 'xp_achievement_columns',
    'manage_pages_custom_column'         => 'achievement_custom_column',
    'manage_posts_custom_column'         => 'achievement_custom_column',
    'save_post'                          => 'xp_achievement_xp_box_save',
    'wp_ajax_list_achievements'          => 'listAchievements',
    'wp_ajax_list_categories'            => 'listCategories',
    'wp_ajax_xp_complete_achievement'    => 'completeAchievement',
    'wp_ajax_xp_add_new_category'        => 'addNewCategory',
    'rest_api_init'                      => 'register_meta_keys'
  ];

  public $meta_keys = [
   '_xp_achievement_' => [
      "ap",
      "gp",
      "xp",
      "max_redo_limit",
      "repeat_count",
      "repeat_every",
      "repeat_on",
   ]
 ];

  /**
  * Initialize the class and set its properties.
  *
  * @since    1.0.0
  * @param      string    $plugin_name       The name of this plugin.
  * @param      string    $version    The version of this plugin.
  */
  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
  * Register a achievement post type.
  *
  * @link http://codex.wordpress.org/Function_Reference/register_post_type
  */

  public function codex_achievement_init() {
    add_role(
        'achiever',
        __( 'Achiever' ),
        array(
          'read'         => true,  // true allows this capability
          'edit_posts'   => true,
        )
    );
    $labels = array(
      'name'               => __( 'Achievements', 'post type general name', 'xophz-compass-xp-achievement' ),
      'singular_name'      => __( 'Achievement', 'post type singular name', 'xophz-compass-xp-achievement' ),
      'menu_name'          => __( 'Achievements', 'admin menu', 'xophz-compass-xp-achievement' ),
      'name_admin_bar'     => __( 'Achievement', 'add new on admin bar', 'xophz-compass-xp-achievement' ),
      'add_new'            => __( 'Add New Achievement', 'achievement', 'xophz-compass-xp-achievement' ),
      'add_new_item'       => __( 'Add New Achievement', 'xophz-compass-xp-achievement' ),
      'new_item'           => __( 'New Achievement', 'xophz-compass-xp-achievement' ),
      'edit_item'          => __( 'Edit Achievement', 'xophz-compass-xp-achievement' ),
      'view_item'          => __( 'View Achievement', 'xophz-compass-xp-achievement' ),
      'all_items'          => __( 'All Achievements', 'xophz-compass-xp-achievement' ),
      'search_items'       => __( 'Search Achievement', 'xophz-compass-xp-achievement' ),
      'parent_item_colon'  => __( 'Parent Achievement:', 'xophz-compass-xp-achievement' ),
      'not_found'          => __( 'No achievements found.', 'xophz-compass-xp-achievement' ),
      'not_found_in_trash' => __( 'No achievements found in Trash.', 'xophz-compass-xp-achievement' )
    );

    $args = array(
      'labels'             => $labels,
      'description'        => __( 'Description.', $this->post_type ),
      'public'             => true,
      'publicly_queryable' => true,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'show_in_rest'       => true,
      'query_var'          => true,
      'rewrite'            => array( 'slug' => 'xp/achievement' ),
      'capability_type'    => 'post',
      'has_archive'        => true,
      'hierarchical' => true,
      'menu_icon'          => 'dashicons-welcome-learn-more',
      'menu_position'      => null,
      'supports'           => array( 
        'title', 
        'editor', 
        'author', 
        'thumbnail', 
        'custom-fields',
        'page-attributes' /* This will show the post parent field */,
      )
    );

    register_post_type( $this->post_type, $args );
  }

  public function create_achievement_taxonomy() {
    $labels = array(
      'name'                           => 'Achievement Classes',
      'singular_name'                  => 'Achievement Class',
      'search_items'                   => 'Search Achievement Classes',
      'all_items'                      => 'All Achievement Classes',
      'edit_item'                      => 'Edit Achievement Class',
      'update_item'                    => 'Update Achievement Class',
      'add_new_item'                   => 'Add New Achievement Class',
      'new_item_name'                  => 'New Achievement Class',
      'menu_name'                      => 'Achievement Classes',
      'view_item'                      => 'View Achievement Classes',
      'popular_items'                  => 'Popular Classes',
      'separate_items_with_commas'     => 'Separate categories with commas',
      'add_or_remove_items'            => 'Add or remove categories',
      'choose_from_most_used'          => 'Choose from the most used categories',
      'not_found'                      => 'No category found'
    );

    register_taxonomy(
      'xp_achievement_category',
      $this->post_type,
      array(
        'label' => __( 'Achievement Class' ),
        'hierarchical' => true,
        'labels' => $labels,
        'public' => true,
        'show_in_nav_menus' => false,
        'show_tagcloud' => false,
        'show_admin_column' => true,
        'rewrite' => array(
          'slug' => 'achievements'
        )
      )
    );
  }

  public function meta_boxes(){
    add_meta_box(
      'xp_achievement_xp_box',
      __( 'XP Rewards', 'xophz-compass-xp' ),
      [$this,'xp_achievement_xp_box_content'],
      $this->post_type,
      'side',
      'high'
    );

    add_meta_box(
      'achievement_xp_repeat_box',
      __( 'Achievement Time Table', 'xophz-compass-xp' ),
      [$this,'xp_achievement_repeat_box_content'],
      $this->post_type,
      'side',
      'high'
    );

    add_meta_box(
      'achievement_xp_box',
      __( 'Unlock Mechanism', 'xophz-compass-xp' ),
      ['Xophz_Compass_Xp_Abilities','xp_ability_meta_box'],
      'xp_ability',
      'side',
      'high'
    );

    add_meta_box(
      'achievement_xp_box',
      __( 'Accessory For Sell', 'xophz-compass-xp' ),
      ['Xophz_Compass_Xp_Admin','xp_accessory_meta_box'],
      'xp_accessory',
      'side',
      'high'
    );
  }
  
  public function xp_achievement_xp_box_save( $post_id ) {
    $post = get_post($post_id);

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
    return;

    if ( !isset($_POST['job_xp_box_content_nonce']) || !wp_verify_nonce( $_POST['job_xp_box_content_nonce'], 'xophz-compass-xp-job-xp' ) ) {
      return;
    }

    $keys = [];

    switch($post->post_type){
      case $this->post_type:
        foreach($this->meta_keys as $key_prefix => $names ){
          foreach($names as $name){
            $keys[] = "{$key_prefix}{$name}";
          }
        }
      break;
      case 'xp_ability':
        $key_ = "_xp_ability_";

        $keys = [
          "{$key_}ap_to_unlock",
          "{$key_}repeat",
        ];
      break;
      case 'xp_accessory':
        $key_ = "_xp_accessory_";

        $keys = [
          "{$key_}gp_cost",
          "{$key_}ap_to_unlock",
          "{$key_}repeat",
        ];
      break;
      default:
      break;
    }

    if ( !empty($keys) ) {
      Xophz_Compass::update_post_meta($post_id, $keys, $_POST);
    }
  }

  public function register_meta_keys(){
    foreach($this->meta_keys as $key => $keys ){
      foreach($keys as $name){
        register_meta('post', "{$key}{$name}",[
          'show_in_rest' => true,
          'single'       => true,
          'type'         => $this->post_type,
          // 'description'  => $meta['description'],
        ]);
      }
    }
  }

  public function xp_achievement_repeat_box_content(){
    require('partials/achievement-repeat-box.php');
  }

  public function xp_achievement_xp_box_content($post){
    require('partials/achievement-xp-rewards-box.php');
  }

  public function addNewCategory(){
    $args = Xophz_Compass::get_input_json();

    Xophz_Compass::output_json(wp_insert_term(
      $args->name,
      'xp_achievement_category',
      $args
    ));
  }

  public function xp_achievement_columns($defaults){
    $defaults['achievement_repeat'] = __('Repeat Every');
    $defaults['achievement_xp'] = __('XP');
    $defaults['achievement_ap'] = __('AP');
    $defaults['achievement_redo_limit'] = __('Max Redo Limit');
    return $defaults;
  }

  public function achievement_custom_column($column, $id=null){
    $id = $id ? $id : get_the_ID();
      switch ( $column ) {
        case 'ability_unlocked':
          echo get_post_meta( $id, '_xp_ability_ap_to_unlock', true ); 
          echo " AP <br/>";
          echo get_post_meta( $id, '_xp_ability_repeat', true ); 
          break;
        case 'accessory_unlocked':
          echo get_post_meta( $id, '_xp_accessory_ap_to_unlock', true ); 
          echo "<br/>";
          echo get_post_meta( $id, '_xp_accessory_repeat', true ); 
          break;
        case 'gp_cost':
          echo get_post_meta( $id, '_xp_accessory_gp_cost', true ); 
          break;
        case 'achievement_xp':
          echo get_post_meta( $id, '_xp_achievement_xp', true ); 
          break;
        case 'achievement_redo_limit':
          echo get_post_meta( $id, '_xp_achievement_max_redo_limit', true ); 
          break;
        case 'achievement_ap':
          echo get_post_meta( $id, '_xp_achievement_ap', true ); 
          break;
        case 'achievement_repeat':
          echo get_post_meta( $id, '_xp_achievement_repeat_count', true ); 
          echo " ";
          echo get_post_meta( $id, '_xp_achievement_repeat_every', true ); 
          echo "<br/>";
          echo ucwords(implode(', ', get_post_meta( $id, '_xp_achievement_repeat_on', true ))); 
          break;
      }
  }

  ###################################################
  ### AJAX ##########################################
  ###################################################

  public function listCategories(){
    $args = Xophz_Compass::get_input_json();

    $args->taxonomy = 'xp_achievement_category';
    $args->hide_empty = false;

    $categories = get_categories($args);

    Xophz_Compass::output_json([
      'categories' => (array) $categories
    ]);
  }

  public function listAchievements(){
    global $wpdb;
    // $sql = "
    //   SELECT 
    //   post_id as id
    //     FROM {$wpdb->prefix}xp_achievements as a 
    // ";
    //
    // $completed = $wpdb->get_results($sql);
    // function theId($record){
    //   return $record->id;
    // }
    // $completed = array_map('theId',$completed);

    $args = Xophz_Compass::get_input_json();
    $args = array(
      'posts_per_page'   => -1,
      // 'offset'           => 0,
      // 'cat'         => '',
      // 'category_name'    => '',
      'orderby'          => 'post_title',
      'order'            => 'ASC',
      // 'include'          => '',
      // 'exclude'          => $completed,
      // 'meta_key'         => '',
      // 'meta_value'       => '',
      'post_type'        => $this->post_type,
      // 'post_mime_type'   => '',
      // 'post_parent'      => '',
      // 'author'	   => '',
      // 'author_name'	   => '',
      // 'post_status'      => 'publish',
      // 'suppress_filters' => true,
      // 'fields'           => '',
    );

    $posts = get_posts($args);

    $achievements = [];
    foreach ($posts as $p) {
      $repeat_on = get_post_meta($p->ID,'_xp_achievement_repeat_on',true); 

      $achievements[] = [
        'id' => $p->ID,
        'slug' => $p->post_name,
        'title' => $p->post_title,
        'img' => get_the_post_thumbnail_url($p->ID),
        'the_content' => apply_filters( 'the_content', $p->post_content ),
        'repeat_count' => get_post_meta($p->ID,'_xp_achievement_repeat_count',true),
        'repeat_every' => get_post_meta($p->ID,'_xp_achievement_repeat_every',true),
        'repeat_on' => $repeat_on ? $repeat_on : ['sun','mon','tue','wed','thu','fri','sat'],
        'xp' => get_post_meta($p->ID,'_xp_achievement_xp',true),
        'gp' => get_post_meta($p->ID,'_xp_achievement_gp',true),
        'ap' => get_post_meta($p->ID,'_xp_achievement_ap',true),
      ];
    }

    $out = [
      'achievements' => $achievements, 
      // 'completed' => $completed
    ];

    Xophz_Compass::output_json($out);
  }
  
  public function completeAchievement(){
    global $wpdb;

    $args = Xophz_Compass::get_input_json();
    $meta_ = "_xp_total_";
    $userId = get_current_user_id();
    $time = time(); 
    $achievement_meta = str_replace("-","_","_xp_achievement_{$args->slug}");

    $xp =  (int) get_user_meta($userId, "{$meta_}xp", true) + (int) $args->xp;
    $ap =  (int) get_user_meta($userId, "{$meta_}ap", true) + (int) $args->ap;
    $gp =  (int) get_user_meta($userId, "{$meta_}gp", true) + (int) $args->gp;
    // $achieved =  json_decode(get_user_meta($userId, $achievement_meta, true));

    // $key = "{$args->id}.{$time}";

    $achieved = [
      'post_id' => $args->id, 
      'user_id' => $userId, 
      'time' => date("Y-m-d H:i:s"),
      'xp' => $args->xp,
      'ap' => $args->ap,
      'gp' => $args->gp,
      'grade' => 0,
      'redo' => 0,
    ];

    $insert = $wpdb->insert("{$wpdb->prefix}xp_achievements", $achieved);

    update_user_meta($userId, "{$meta_}xp", $xp );
    update_user_meta($userId, "{$meta_}ap", $ap );
    update_user_meta($userId, "{$meta_}gp", $gp );
    // update_user_meta($userId, $achievement_meta , json_encode($achieved));



    Xophz_Compass::output_json([
      'insert' => $insert,
      'meta' => get_user_meta($userId)
    ]);
  }

  public function levelUp(){
    $args = Xophz_Compass::get_input_json();

    $userId = get_current_user_id();

    $meta_ = "_xp_total_";

    update_user_meta($userId, "{$meta_}level", $args->level);

    Xophz_Compass::output_json([
      'level' => get_user_meta($userId, $meta_.'level',true)
    ]);
  }
}
