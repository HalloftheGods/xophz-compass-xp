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
class Xophz_Compass_Xp_Admin {

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

  public $taxonomy = 'xp_achievement_category';
  public $post_typeterm = 'xp_achievement';

  public  $action_hooks = [
    'init' => [
      // 'create_ability_taxonomy',
      // 'codex_ability_init',
      // 'codex_accessories_init',
    ],
    // 'manage_edit-xp_ability_columns' => 'xp_ability_columns',
    // 'wp_ajax_list_abilities' => 'listAbilities',
    // 'wp_ajax_list_jobs' => 'listJobs',
    'admin_menu' => 'addToMenu',
    'user_admin_menu' => 'addToMenu' ,
    'woocommerce_prevent_admin_access' => 'preventAdminAccess',
    'wp_ajax_level_up' => 'levelUp',
    'wp_ajax_list_billboard_chips' => 'listBillboardChips',
    'wp_ajax_xp_get_user' => 'getUser',
    'wp_ajax_xp_load_log' => 'loadLog',
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
   * undocumented function
   *
   * @return void
   */
  public function preventAdminAccess()
  {
    return false;
  }
  
  

  /**
  * Add menu item 
  *
  * @since    1.0.0
  */
  public function addToMenu(){
    Xophz_Compass::add_submenu($this->plugin_name,[
      'cap' => 'read',
    ]);
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
      'xp_achievement',
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

  /**
  * Register a job post type.
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
      'name'               => __( 'Achievements', 'post type general name', 'xophz-compass-xp-job' ),
      'singular_name'      => __( 'Achievement', 'post type singular name', 'xophz-compass-xp-job' ),
      'menu_name'          => __( 'Achievements', 'admin menu', 'xophz-compass-xp-job' ),
      'name_admin_bar'     => __( 'Achievement', 'add new on admin bar', 'xophz-compass-xp-job' ),
      'add_new'            => __( 'Add New Achievement', 'job', 'xophz-compass-xp-job' ),
      'add_new_item'       => __( 'Add New Achievement', 'xophz-compass-xp-job' ),
      'new_item'           => __( 'New Achievement', 'xophz-compass-xp-job' ),
      'edit_item'          => __( 'Edit Achievement', 'xophz-compass-xp-job' ),
      'view_item'          => __( 'View Achievement', 'xophz-compass-xp-job' ),
      'all_items'          => __( 'All Achievements', 'xophz-compass-xp-job' ),
      'search_items'       => __( 'Search Achievement', 'xophz-compass-xp-job' ),
      'parent_item_colon'  => __( 'Parent Achievement:', 'xophz-compass-xp-job' ),
      'not_found'          => __( 'No jobs found.', 'xophz-compass-xp-job' ),
      'not_found_in_trash' => __( 'No jobs found in Trash.', 'xophz-compass-xp-job' )
    );

    $args = array(
      'labels'             => $labels,
      'description'        => __( 'Description.', 'xp_achievement' ),
      'public'             => true,
      'publicly_queryable' => true,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'query_var'          => true,
      'rewrite'            => array( 'slug' => 'xp/job' ),
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
        'page-attributes' /* This will show the post parent field */,
      )
    );

    register_post_type( 'xp_achievement', $args );
  }
  

  public function job_xp_box(){
    add_meta_box(
      'job_xp_box',
      __( 'XP Rewards', 'xophz-compass-xp' ),
      [Xophz_Compass_Xp_Admin,'job_xp_box_content'],
      'xp_achievement',
      'side',
      'high'
    );
    add_meta_box(
      'job_xp_repeat_box',
      __( 'Achievement Time Table', 'xophz-compass-xp' ),
      [Xophz_Compass_Xp_Admin,'xp_achievement_repeat_box_content'],
      'xp_achievement',
      'side',
      'high'
    );

    add_meta_box(
      'job_xp_box',
      __( 'Unlock Mechanism', 'xophz-compass-xp' ),
      [Xophz_Compass_Xp_Admin,'xp_ability_meta_box'],
      'xp_ability',
      'side',
      'high'
    );

    add_meta_box(
      'job_xp_box',
      __( 'Accessory For Sell', 'xophz-compass-xp' ),
      [Xophz_Compass_Xp_Admin,'xp_accessory_meta_box'],
      'xp_accessory',
      'side',
      'high'
    );
  }
  
  public function jox_xp_box_save( $post_id ) {
    $post = get_post($post_id);

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
    return;

    switch($post->post_type){
      case 'xp_achievement':
        $key_ = "_xp_achievement_";

        $keys = [
          "{$key_}ap",
          "{$key_}gp",
          "{$key_}xp",
          "{$key_}max_redo_limit",
          "{$key_}repeat_count",
          "{$key_}repeat_every",
          "{$key_}repeat_on",
          "{$key_}max_redo_limit",
          "{$key_}repeat_count",
          "{$key_}repeat_every",
          "{$key_}repeat_on",
        ];
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

    Xophz_Compass::update_post_meta($post_id, $keys, $_POST);

    if ( !wp_verify_nonce( $_POST['job_xp_box_content_nonce'], 'xophz-compass-xp-job-xp' ) )
    return;
  }

  public static function xp_achievement_repeat_box_content(){
    require('partials/job-repeat-box.php');
  }

  public static function job_xp_box_content($post){
    require('partials/job-xp-rewards-box.php');
  }

  public static function xp_accessory_meta_box($post){
    require('partials/xp-accessory-meta-box.php');
  }

  public function addNewCategory(){
    $args = Xophz_Compass::get_input_json();

    Xophz_Compass::output_json(wp_insert_term(
      $args->name,
      'xp_achievement_category',
      $args
    ));
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

  public function listBillboardChips(){
    $user_id = get_current_user_id();

    $user_xp = get_user_meta($user_id, '_xp_total_xp', true) ;
    $user_ap = get_user_meta($user_id, '_xp_total_ap', true) ;
    $user_gp = get_user_meta($user_id, '_xp_total_gp', true) ;
    $user_level = get_user_meta($user_id, '_xp_total_level', true)  ;

    $chips = [];
    $level = (object)[];
    $xp = (object)[];
    $ap = (object)[];
    $gp = (object)[];

    $level->text = "level {$user_level}";
    $level->icon = "fa-hand-holding-seedling";
    $level->color= "green";


    $xp->text = "XP {$user_xp}";
    $xp->icon = "fa-hand-holding-magic";
    $xp->color= "blue";
    

    $ap->text = "AP $user_ap";
    $ap->icon = "fa-hand-holding-heart";
    $ap->color= "red";
    
    $gp->text = "GP $user_gp";
    $gp->icon = "fa-hand-holding-usd";
    $gp->color= "orange";

    $chips[] = $xp;
    $chips[] = $gp;
    $chips[] = $ap;
    $chips[] = $level;

    Xophz_Compass::output_json([
      'chips' => $chips 
    ]);
  }

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
    $args = Xophz_Compass::get_input_json();

    $args = array(
      'posts_per_page'   => -1,
      // 'offset'           => 0,
      // 'cat'         => '',
      // 'category_name'    => '',
        'orderby'          => 'post_title',
        'order'            => 'ASC',
      // 'include'          => '',
      // 'exclude'          => '',
      // 'meta_key'         => '',
      // 'meta_value'       => '',
      'post_type'        => 'xp_achievement',
      // 'post_mime_type'   => '',
      // 'post_parent'      => '',
      // 'author'	   => '',
      // 'author_name'	   => '',
      // 'post_status'      => 'publish',
      // 'suppress_filters' => true,
      // 'fields'           => '',
    );

    $posts = get_posts( $args );

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
        'repeat_on' => !empty($repeat_on) ? $repeat_on : ['sun','mon','tue','wed','thu','fri','sat'],
        'xp' => get_post_meta($p->ID,'_xp_achievement_xp',true),
        'gp' => get_post_meta($p->ID,'_xp_achievement_gp',true),
        'ap' => get_post_meta($p->ID,'_xp_achievement_ap',true),
      ];
    }

    $out = [
      'achievements' => $achievements 
    ];

    Xophz_Compass::output_json($out);
  }

  public function completeAchievement(){
    $args = Xophz_Compass::get_input_json();
    $meta_ = "_xp_total_";
    $userId = get_current_user_id();
    $time = time(); 
    $achievement_meta = str_replace("-","_","_xp_achievement_{$args->slug}");

    $xp =  (int) get_user_meta($userId, "{$meta_}xp", true) + (int) $args->xp;
    $ap =  (int) get_user_meta($userId, "{$meta_}ap", true) + (int) $args->ap;
    $gp =  (int) get_user_meta($userId, "{$meta_}gp", true) + (int) $args->gp;
    $achieved =  json_decode(get_user_meta($userId, $achievement_meta, true));

    $key = "{$args->id}.{$time}";

    $achieved->$key = [
      'xp' => $args->xp,
      'ap' => $args->ap,
      'gp' => $args->gp,
      'grade' => 0,
      'redone' => 0,
    ];

    update_user_meta($userId, "{$meta_}xp", $xp );
    update_user_meta($userId, "{$meta_}ap", $ap );
    update_user_meta($userId, "{$meta_}gp", $gp );
    update_user_meta($userId, $achievement_meta , json_encode($achieved));

    Xophz_Compass::output_json([
      'achievement_meta' => $achievement_meta,
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

  public function getUser($userId=0, $output_json=true){
    if(!$userId)
      $userId = get_current_user_id();

    $meta_ = "_xp_total_";

    $xp =  get_user_meta($userId, "{$meta_}xp", true);
    $ap =  get_user_meta($userId, "{$meta_}ap", true);
    $gp =  get_user_meta($userId, "{$meta_}gp", true);
    $level =  get_user_meta($userId, "{$meta_}level", true);
    $birthdate = get_user_meta($userId, "_xp_birthdate", true);

    // Declare and define two dates 
    $date1 = strtotime($birthdate);  
    $date2 = strtotime("NOW");  
      
    // Formulate the Difference between two dates 
    $diff = abs($date2 - $date1);  
      
    // To get the year divide the resultant date into 
    // total seconds in a year (365*60*60*24) 
    $years = floor($diff / (365*60*60*24));  
      
    $user = [
      'id' => (int) $id,
      'xp' => (int) $xp,
      'ap' => (int) $ap,
      'gp' => (int) $gp,
      'level' => (int) $level,
      'age' => $years, 
      'birthdate' => $birthdate
    ];

    if(!$output_json)
      return $user;

    Xophz_Compass::output_json($user);
  }

  public function loadLog(){
    global $wpdb; 

    $userId = get_current_user_id();

    $sql = "
      SELECT 
      * 
      FROM {$wpdb->prefix}xp_achievements
      WHERE
        user_id = {$userId}
      ORDER BY 
      time desc
    ";

    $logs = Xophz_Compass_Xp_Admin::parseLogs($wpdb->get_results($sql));

    $log = ['log'=> $logs ];

    Xophz_Compass::output_json($log);
  }

  public static function parseLogs($logs){
    $parsed = [];
    
    foreach($logs as $log){
      // $json = json_decode($log->meta_value);
      // foreach($json as $id_time => $achievement){
      //    $id = explode(".", (string) $id_time);
      //    $time = (int) $id[1];
      //    $id = $id[0];
      //    $parsed[$time] = $achievement;
      // }

      // $achievement->id = $id;
      $log->thumbnail = get_the_post_thumbnail_url($log->post_id);
      $log->title = get_the_title($log->post_id);
      // $achievement->time = $time;
    }

    return $logs;
  }
}
