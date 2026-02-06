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
class Xophz_Compass_Xp_Abilities {

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

  public  $action_hooks = [
    'init' => [
      'create_ability_taxonomy',
      'codex_ability_init',
    ],
    'add_meta_boxes'                  => 'meta_boxes',
    'manage_edit-xp_ability_columns'  => 'xp_ability_columns',
    'wp_ajax_list_abilities'          => 'listAbilities',
    'wp_ajax_tally_ability_points'    => 'tallyAbilityPoints',
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
  * Register an ability post type.
  *
  * @link http://codex.wordpress.org/Function_Reference/register_post_type
  */
  public function codex_ability_init() {
    $labels = array(
      'name'               => __( 'Abilities', 'post type general name', 'xophz-compass-xp-ability' ),
      'singular_name'      => __( 'Ability', 'post type singular name', 'xophz-compass-xp-ability' ),
      'menu_name'          => __( 'Abilities', 'admin menu', 'xophz-compass-xp-ability' ),
      'name_admin_bar'     => __( 'Ability', 'add new on admin bar', 'xophz-compass-xp-ability' ),
      'add_new'            => __( 'Add New', 'ability', 'xophz-compass-xp-ability' ),
      'add_new_item'       => __( 'Add New Ability', 'xophz-compass-xp-ability' ),
      'new_item'           => __( 'New Ability', 'xophz-compass-xp-ability' ),
      'edit_item'          => __( 'Edit Ability', 'xophz-compass-xp-ability' ),
      'view_item'          => __( 'View Ability', 'xophz-compass-xp-ability' ),
      'all_items'          => __( 'All Abilities', 'xophz-compass-xp-ability' ),
      'search_items'       => __( 'Search Abilities', 'xophz-compass-xp-ability' ),
      'parent_item_colon'  => __( 'Parent Abilities:', 'xophz-compass-xp-ability' ),
      'not_found'          => __( 'No abilities found.', 'xophz-compass-xp-ability' ),
      'not_found_in_trash' => __( 'No abilities found in Trash.', 'xophz-compass-xp-ability' )
    );

    $args = array(
      'labels'             => $labels,
      'description'        => __( 'Description.', 'xophz_compass_xp_ability' ),
      'public'             => true,
      'publicly_queryable' => true,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'show_in_rest'       => true,
      'query_var'          => true,
      'rewrite'            => array( 'slug' => 'ability','with_front' => false ),
      'capability_type'    => 'post',
      'has_archive'        => true,
      'hierarchical'        => true,
      'menu_icon'          => 'dashicons-awards',
      'menu_position'      => false,
      'supports'           => array( 
        'title', 
        'editor', 
        'author', 
        'thumbnail', 
        'custom-fields',
        'page-attributes' /* This will show the post parent field */,
      )
    );

    register_post_type( 'xp_ability', $args );
  }

  public function create_ability_taxonomy() {
    $labels = array(
      'name'                           => 'Ability Categories',
      'singular_name'                  => 'Ability Category',
      'search_items'                   => 'Search Abilities Categories',
      'all_items'                      => 'All Abilities Categories',
      'edit_item'                      => 'Edit Ability Category',
      'update_item'                    => 'Update Ability Category',
      'add_new_item'                   => 'Add New Ability Category',
      'new_item_name'                  => 'New Ability Category',
      'menu_name'                      => 'Ability Categories',
      'view_item'                      => 'View Ability Categories',
      'popular_items'                  => 'Popular Categories',
      'separate_items_with_commas'     => 'Separate categories with commas',
      'add_or_remove_items'            => 'Add or remove categories',
      'choose_from_most_used'          => 'Choose from the most used categories',
      'not_found'                      => 'No category found'
    );

    register_taxonomy(
      'xp_ability_category',
      'xp_ability',
      array(
        'label' => __( 'Ability Category' ),
        'hierarchical' => true,
        'labels' => $labels,
        'public' => true,
        'show_in_nav_menus' => true,
        'show_tagcloud' => false,
        'show_admin_column' => true,
        'rewrite' => array(
          'slug' => 'ability-categories'
        )
      )
    );
  }

  public function meta_boxes(){
    add_meta_box(
      'achievement_xp_box',
      __( 'Unlock Mechanism', 'xophz-compass-xp' ),
      [$this,'xp_ability_meta_box'],
      'xp_ability',
      'side',
      'high'
    );
  }

  public static function xp_ability_meta_box($post){
    require('partials/xp-ability-meta-box.php');
  }


  public function xp_achievement_columns($defaults){
    $defaults['achievement_repeat'] = __('Repeat Every');
    $defaults['achievement_xp'] = __('XP');
    $defaults['achievement_ap'] = __('AP');
    $defaults['achievement_redo_limit'] = __('Max Redo Limit');
    return $defaults;
  }

  public function xp_ability_columns($defaults){
    $defaults['ability_unlocked'] = __('Unlocked @');
    return $defaults;
  }

  public function listAbilities(){
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
      'post_type'        => 'xp_ability',
      // 'post_mime_type'   => '',
      // 'post_parent'      => '',
      // 'author'	   => '',
      // 'author_name'	   => '',
      // 'post_status'      => 'publish',
      // 'suppress_filters' => true,
      // 'fields'           => '',
    );
    $posts = get_posts( $args );

    $abilities = [];

    foreach ($posts as $p) {
      $abilities[] = [
        'title' => $p->post_title,
        'img' => get_the_post_thumbnail_url($p->ID),
        'repeat' => get_post_meta($p->ID,'_xp_ability_repeat',true),
        'ap_to_unlock' => get_post_meta($p->ID,'_xp_ability_ap_to_unlock',true),
      ];
    }


    $out = [
      'abilities' => $abilities 
    ];


    Xophz_Compass::output_json($out);
  }

  public function tallyAbilityPoints(){
    global $wpdb; 

    $sql = "
      SELECT 
      * 
      FROM {$wpdb->usermeta}
      WHERE
        meta_key
        LIKE '_xp_achievement%'
      AND meta_value != '' 
    ";

    $achievements = Xophz_Compass_Xp_Abilities::parseAchievements( 
      $wpdb->get_results($sql) 
    );

    // $log = Xophz_Compass_Xp_Admin::parseLogs($logs);

    $json = [
      'ability_points' => $achievements
    ];
    Xophz_Compass::output_json($json);
  }

  /**
   * undocumented function
   *
   * @return void
   */
  public function parseAchievements($achievements)
  {
    $parsed = [
      1=>0,
      7=>0,
      30=>0,
      60=>0,
      120=>0,
      180=>0,
      365=>0,
      366=>0,
    ];

    foreach(array_reverse($achievements, true) as $idTime => $log){
      $json = json_decode($log->meta_value);
      foreach($json as $id_time => $achievement){
         $id = explode(".", (string) $id_time);
         $time = $id[1];
         $id = $id[0];

         $now = time();
         $minsFromNow = ($now - $time) / 60; 
         $hoursFromNow = ceil($minsFromNow / 60); 
         $daysFromNow = ceil($hoursFromNow / 24); 

         $days= 366;
         $ap = $achievement->ap;

         $days = $daysFromNow <= 365 ? 365 : $days; 
         $days = $daysFromNow <= 180 ? 180 : $days; 
         $days = $daysFromNow <= 120 ? 120 : $days; 
         $days = $daysFromNow <=  60 ?  60 : $days; 
         $days = $daysFromNow <=  30 ?  30 : $days; 
         $days = $daysFromNow <=   7 ?   7 : $days; 
         $days = $daysFromNow <=   1 ?   1 : $days; 

         switch($days){
            case(1):
              $parsed[$days] = $parsed[$days] + $ap;
            break;
            case(7):
              $parsed[1] = $parsed[1] + $ap;
              $parsed[$days] = $parsed[$days] + $ap;
            break;
            case(30):
              $parsed[1] = $parsed[1] + $ap;
              $parsed[7] = $parsed[7] + $ap;
              $parsed[$days] = $parsed[$days] + $ap;
            break;
            case(60):
              $parsed[1] = $parsed[1] + $ap;
              $parsed[7] = $parsed[7] + $ap;
              $parsed[30] = $parsed[30] + $ap;
              $parsed[$days] = $parsed[$days] + $ap;
            break;
            case(120):
              $parsed[1] = $parsed[1] + $ap;
              $parsed[7] = $parsed[7] + $ap;
              $parsed[30] = $parsed[30] + $ap;
              $parsed[60] = $parsed[60] + $ap;
              $parsed[$days] = $parsed[$days] + $ap;
            break;
            case(180):
              $parsed[1] = $parsed[1] + $ap;
              $parsed[7] = $parsed[7] + $ap;
              $parsed[30] = $parsed[30] + $ap;
              $parsed[60] = $parsed[60] + $ap;
              $parsed[120] = $parsed[120] + $ap;
              $parsed[$days] = $parsed[$days] + $ap;
            break;
            case(365):
              $parsed[1] = $parsed[1] + $ap;
              $parsed[7] = $parsed[7] + $ap;
              $parsed[30] = $parsed[30] + $ap;
              $parsed[60] = $parsed[60] + $ap;
              $parsed[120] = $parsed[120] + $ap;
              $parsed[180] = $parsed[180] + $ap;
              $parsed[$days] = $parsed[$days] + $ap;
            break;
            default:
              $parsed[1] = $parsed[1] + $ap;
              $parsed[7] = $parsed[7] + $ap;
              $parsed[30] = $parsed[30] + $ap;
              $parsed[60] = $parsed[60] + $ap;
              $parsed[120] = $parsed[120] + $ap;
              $parsed[180] = $parsed[180] + $ap;
              $parsed[$days] = $parsed[$days] + $ap;
            break;
         }

      }
    }
    // krsort($parsed);
    // ksort($parsed);
    $parsed = array_reverse($parsed, true); 

    return $parsed;
  }
  
}
