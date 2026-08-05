<?php

/**
 * XP Players Class 
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 * @author     Your Name <email@example.com>
 */
class Xophz_Compass_Xp_Players {
  private $plugin_name;
  private $version;

  public  $action_hooks = [
    'wp_ajax_xp_list_players' => 'listPlayers',
    'wp_ajax_xp_start_player' => 'startPlayer',
    'rest_api_init' => 'register_player_rest_routes',
    'xophz_compass_record_action' => ['handle_action_xp_reward', 20, 3],
    'xophz_nook_phone_state_saved' => ['handle_nook_phone_state_saved', 10, 3],
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
   * The Mathematical Level-Scaling Engine
   */
  public static function get_required_xp_for_level($level) {
    if ($level <= 1) return 0;
    $base_multiplier = apply_filters('xp_base_multiplier', 150);
    $offset_xp       = apply_filters('xp_offset', 100);
    return round($base_multiplier * pow($level - 1, 1.5) + $offset_xp);
  }

  /**
   * Calculate User Level Based on Total XP
   */
  public static function calculate_level($total_xp) {
    if (!$total_xp) $total_xp = 0;
    $level = 1;
    while ($total_xp >= self::get_required_xp_for_level($level + 1)) {
        $level++;
    }
    return $level;
  }

  /**
   * Helper to get current blog / site ID in WP Multisite context
   */
  public static function get_current_site_id() {
    return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
  }

  /**
   * Helper to get site currency name (defaults to GP)
   */
  public static function get_site_currency($blog_id = null) {
    if (!$blog_id) {
        $blog_id = self::get_current_site_id();
    }
    $default_currency = 'GP';
    return apply_filters('xophz_compass_site_currency', $default_currency, $blog_id);
  }

  /**
   * Database / User Metadata Controller (Site Isolated)
   */
  public static function get_user_stats($user_id, $blog_id = null) {
    if (!$blog_id) {
        $blog_id = self::get_current_site_id();
    }

    if (!$user_id) {
        return [
            'site_id'       => $blog_id,
            'currency_name' => self::get_site_currency($blog_id),
            'level'         => 1,
            'current_xp'    => 0,
            'target_xp'     => self::get_required_xp_for_level(2),
            'total_xp'      => 0,
            'total_ap'      => 0,
            'total_gp'      => 0,
            'title'         => 'Guest Scripter',
            'is_pro'        => false,
            'stats'         => (object) []
        ];
    }

    $site_key_prefix = "_xp_s{$blog_id}_";

    $raw_xp = get_user_meta($user_id, "{$site_key_prefix}total_xp", true);
    $raw_ap = get_user_meta($user_id, "{$site_key_prefix}total_ap", true);
    $raw_gp = get_user_meta($user_id, "{$site_key_prefix}total_gp", true);

    // Fallback to legacy keys for main site (site 1) if per-site key hasn't been set yet
    if (($raw_xp === '' || $raw_xp === false) && ($blog_id === 1 || !is_multisite())) {
        $total_xp = (int) get_user_meta($user_id, '_xp_total_xp', true) ?: 0;
        $total_ap = (int) get_user_meta($user_id, '_xp_total_ap', true) ?: 0;
        $total_gp = (int) get_user_meta($user_id, '_xp_total_gp', true) ?: 0;
    } else {
        $total_xp = (int) $raw_xp ?: 0;
        $total_ap = (int) $raw_ap ?: 0;
        $total_gp = (int) $raw_gp ?: 0;
    }

    $level = self::calculate_level($total_xp);
    update_user_meta($user_id, "{$site_key_prefix}total_level", $level);

    $xp_floor = self::get_required_xp_for_level($level);
    $xp_ceil = self::get_required_xp_for_level($level + 1);
    $current_xp_in_level = $total_xp - $xp_floor;
    $target_xp_for_level = $xp_ceil - $xp_floor;

    // Get site-specific agnostic stats
    $all_meta = get_user_meta($user_id);
    $agnostic_stats = [];
    $stat_prefix = "{$site_key_prefix}stat_";
    foreach ($all_meta as $key => $values) {
        if (strpos($key, $stat_prefix) === 0) {
            $stat_slug = str_replace($stat_prefix, '', $key);
            $agnostic_stats[$stat_slug] = (int) $values[0];
        } elseif (strpos($key, '_xp_stat_') === 0 && ($blog_id === 1 || !is_multisite())) {
            $stat_slug = str_replace('_xp_stat_', '', $key);
            if (!isset($agnostic_stats[$stat_slug])) {
                $agnostic_stats[$stat_slug] = (int) $values[0];
            }
        }
    }

    $default_titles = [
        1 => 'Novice Scripter', 2 => 'Script Apprentice', 3 => 'Syntax Warrior',
        4 => 'Fullstack Alchemist', 5 => 'Master Architect'
    ];
    $titles = apply_filters('xophz_compass_site_rank_titles', $default_titles, $blog_id);
    $title = isset($titles[$level]) ? $titles[$level] : 'Gamification Deity';

    return [
        'site_id'       => $blog_id,
        'currency_name' => self::get_site_currency($blog_id),
        'level'         => $level,
        'current_xp'    => $current_xp_in_level,
        'target_xp'     => $target_xp_for_level,
        'total_xp'      => $total_xp,
        'total_ap'      => $total_ap,
        'total_gp'      => $total_gp,
        'title'         => $title,
        'is_pro'        => self::is_pro_user($user_id),
        'stats'         => (object) $agnostic_stats,
    ];
  }

  /**
   * Get Network Combined Stats (aggregated across all sites)
   */
  public static function get_user_network_stats($user_id) {
    if (!$user_id) {
        return [
            'combined_xp' => 0,
            'combined_ap' => 0,
            'combined_gp' => 0,
            'sites'       => []
        ];
    }

    $sites_list = function_exists('get_sites') ? get_sites(['number' => 100]) : [];
    $site_ids = [];
    if (!empty($sites_list)) {
        foreach ($sites_list as $site_obj) {
            $site_ids[] = (int) $site_obj->blog_id;
        }
    } else {
        $site_ids[] = 1;
    }

    $combined_xp = 0;
    $combined_ap = 0;
    $combined_gp = 0;
    $site_breakdown = [];

    foreach ($site_ids as $sid) {
        $site_stats = self::get_user_stats($user_id, $sid);
        $combined_xp += $site_stats['total_xp'];
        $combined_ap += $site_stats['total_ap'];
        $combined_gp += $site_stats['total_gp'];
        $site_breakdown[$sid] = $site_stats;
    }

    return [
        'combined_xp' => $combined_xp,
        'combined_ap' => $combined_ap,
        'combined_gp' => $combined_gp,
        'sites'       => $site_breakdown,
    ];
  }

  /**
   * Centralized method to add XP, AP, GP and dynamic stats (Site-scoped)
   */
  public static function add_currency($user_id, $xp_or_payload = 0, $ap = 0, $gp = 0, $blog_id = null) {
    if (!$user_id) return false;
    if (!$blog_id) {
        $blog_id = self::get_current_site_id();
    }

    $payload = is_array($xp_or_payload) ? $xp_or_payload : [
        'xp' => $xp_or_payload,
        'ap' => $ap,
        'gp' => $gp
    ];

    if ( ! self::is_pro_user($user_id) ) {
        if (isset($payload['xp'])) $payload['xp'] = 0;
        if (isset($payload['ap'])) $payload['ap'] = 0;
    }

    $stats = self::get_user_stats($user_id, $blog_id);
    $old_level = $stats['level'];
    $site_key_prefix = "_xp_s{$blog_id}_";

    foreach ($payload as $stat_slug => $amount) {
        $amount = (int) $amount;
        if ($amount === 0) continue;

        if (in_array($stat_slug, ['xp', 'ap', 'gp'])) {
            $current_val = $stats["total_{$stat_slug}"];
            $new_val = $current_val + $amount;
            update_user_meta($user_id, "{$site_key_prefix}total_{$stat_slug}", $new_val);
            if ($blog_id === 1 || !is_multisite()) {
                update_user_meta($user_id, "_xp_total_{$stat_slug}", $new_val);
            }
        } else {
            self::modify_stat($user_id, $stat_slug, $amount, $blog_id);
        }
    }

    $new_stats = self::get_user_stats($user_id, $blog_id);
    if ($new_stats['level'] > $old_level) {
        do_action('xophz_compass_user_leveled_up', $user_id, $new_stats['level'], $old_level, $blog_id);
        if (class_exists('Xophz_Compass_Xp_Logs')) {
             do_action('xophz_compass_record_action', 'level_up', $user_id, ['new_level' => $new_stats['level'], 'site_id' => $blog_id]);
        }
    }

    return $new_stats;
  }
  
  /**
   * Add / Update Agnostic Stat (Site-scoped)
   */
  public static function modify_stat($user_id, $stat_slug, $amount, $blog_id = null) {
      if (!$user_id) return false;
      if (!$blog_id) {
          $blog_id = self::get_current_site_id();
      }
      $site_key_prefix = "_xp_s{$blog_id}_";
      $current = (int) get_user_meta($user_id, "{$site_key_prefix}stat_{$stat_slug}", true) ?: 0;
      $new_val = $current + $amount;
      update_user_meta($user_id, "{$site_key_prefix}stat_{$stat_slug}", $new_val);
      if ($blog_id === 1 || !is_multisite()) {
          update_user_meta($user_id, "_xp_stat_{$stat_slug}", $new_val);
      }
      return $new_val;
  }

  public function handle_action_xp_reward($action_slug, $user_id, $payload) {
      // Look up action to see if it grants base XP, or let Goals handle it?
      // In many systems, actions trigger goals. If actions directly give XP, we do it here.
      // Usually, action triggers -> metric updates -> goals trigger -> rewards.
      // For the React Sandbox simulation, if payload contains 'xp', 'ap', 'gp', let's grant them directly.
      $xp = isset($payload['xp_reward']) ? (int)$payload['xp_reward'] : (isset($payload['xp']) ? (int)$payload['xp'] : 0);
      $ap = isset($payload['ap_reward']) ? (int)$payload['ap_reward'] : (isset($payload['ap']) ? (int)$payload['ap'] : 0);
      $gp = isset($payload['gp_reward']) ? (int)$payload['gp_reward'] : (isset($payload['gp']) ? (int)$payload['gp'] : 0);
      
      // Reward baseline defaults for DIY, resident, best friend, critter, and milestone actions
      if ($xp === 0 && $gp === 0 && $ap === 0) {
          $default_rewards = apply_filters('xophz_compass_default_xp_rewards', [
              'unlock_diy_recipe'    => ['xp' => 10, 'gp' => 50],
              'add_resident'         => ['xp' => 50, 'gp' => 200],
              'add_best_friend'      => ['xp' => 100, 'gp' => 500],
              'catch_critter'        => ['xp' => 15, 'gp' => 100],
              'donate_critter'       => ['xp' => 25, 'gp' => 150],
              'talk_villager'        => ['xp' => 5, 'gp' => 20],
              'gift_villager'        => ['xp' => 10, 'gp' => 50],
              'earn_villager_poster' => ['xp' => 30, 'gp' => 150],
              'earn_villager_photo'  => ['xp' => 50, 'gp' => 250],
          ]);
          
          if ( isset( $default_rewards[$action_slug] ) ) {
              $xp = isset( $default_rewards[$action_slug]['xp'] ) ? (int)$default_rewards[$action_slug]['xp'] : 0;
              $gp = isset( $default_rewards[$action_slug]['gp'] ) ? (int)$default_rewards[$action_slug]['gp'] : 0;
              $ap = isset( $default_rewards[$action_slug]['ap'] ) ? (int)$default_rewards[$action_slug]['ap'] : 0;
          }
      }

      if ($xp > 0 || $ap > 0 || $gp > 0) {
          self::add_currency($user_id, $xp, $ap, $gp);
      }
      
      // Update custom stats if provided in payload (e.g. payload[stats][str] = 1)
      if (isset($payload['stats']) && is_array($payload['stats'])) {
          foreach ($payload['stats'] as $stat_slug => $amount) {
              self::modify_stat($user_id, sanitize_title($stat_slug), (int)$amount);
          }
      }
  }

  public function register_player_rest_routes() {
    register_rest_route( 'xp/v1', '/state', array(
      'methods'  => 'GET',
      'callback' => array( $this, 'rest_get_state' ),
      'permission_callback' => '__return_true',
    ));
    
    register_rest_route( 'xp/v1', '/simulate-decay', array(
      'methods'  => 'POST',
      'callback' => array( $this, 'rest_simulate_decay' ),
      'permission_callback' => function() { return is_user_logged_in(); },
    ));

    register_rest_route( 'xp/v1', '/spend-gp', array(
      'methods'  => 'POST',
      'callback' => array( $this, 'rest_spend_gp' ),
      'permission_callback' => function() { return is_user_logged_in(); },
    ));

    register_rest_route( 'xp/v1', '/transaction', array(
      'methods'  => 'POST',
      'callback' => array( $this, 'rest_transaction' ),
      'permission_callback' => function() { return is_user_logged_in(); },
    ));

    register_rest_route( 'xp/v1', '/pro', array(
      'methods'  => 'POST',
      'callback' => array( $this, 'rest_set_pro_status' ),
      'permission_callback' => function() { return is_user_logged_in(); },
    ));
  }

  public function rest_get_state(WP_REST_Request $request) {
      $user_id = get_current_user_id();
      $is_logged_in = is_user_logged_in();
      $site_id_param = $request->get_param('site_id');
      $blog_id = $site_id_param ? (int) $site_id_param : self::get_current_site_id();

      $state = self::get_user_stats($user_id, $blog_id);
      $network_summary = self::get_user_network_stats($user_id);
      
      return rest_ensure_response([
          'is_logged_in'    => $is_logged_in,
          'user_id'         => $user_id,
          'is_pro'          => self::is_pro_user($user_id),
          'state'           => $state,
          'network_summary' => $network_summary
      ]);
  }

  public function rest_simulate_decay(WP_REST_Request $request) {
      $user_id = get_current_user_id();
      $params = $request->get_json_params();
      $ap_decay = isset($params['ap']) ? (int) $params['ap'] : 0;
      $blog_id = self::get_current_site_id();
      
      $stats = self::get_user_stats($user_id, $blog_id);
      $actual_decay = min($stats['total_ap'], $ap_decay);
      
      if ($actual_decay > 0) {
          self::add_currency($user_id, 0, -$actual_decay, 0, $blog_id);
      }
      
      if (class_exists('Xophz_Compass_Xp_Logs')) {
          do_action('xophz_compass_record_action', 'Simulate Weekly AP Decay', $user_id, ['ap' => -$ap_decay, 'site_id' => $blog_id]);
      }
      
      return rest_ensure_response([
          'success'         => true,
          'state'           => self::get_user_stats($user_id, $blog_id),
          'network_summary' => self::get_user_network_stats($user_id)
      ]);
  }

  public function rest_spend_gp(WP_REST_Request $request) {
      $user_id = get_current_user_id();
      $params = $request->get_json_params();
      $gp_cost = isset($params['gp']) ? (int) $params['gp'] : 0;
      $item_name = isset($params['item']) ? sanitize_text_field($params['item']) : 'Unknown Item';
      $blog_id = self::get_current_site_id();
      
      $stats = self::get_user_stats($user_id, $blog_id);
      $currency_name = $stats['currency_name'];
      
      if ($stats['total_gp'] < $gp_cost) {
          return new WP_Error('insufficient_funds', "Not enough {$currency_name}.", ['status' => 400]);
      }
      
      $updated_stats = self::add_currency($user_id, 0, 0, -$gp_cost, $blog_id);
      
      if (class_exists('Xophz_Compass_Xp_Logs')) {
          do_action('xophz_compass_record_action', "Purchased: $item_name", $user_id, ['gp' => -$gp_cost, 'site_id' => $blog_id]);
      }
      
      return rest_ensure_response([
          'success'         => true,
          'state'           => $updated_stats,
          'network_summary' => self::get_user_network_stats($user_id)
      ]);
  }

  public function rest_transaction(WP_REST_Request $request) {
      $user_id = get_current_user_id();
      $params = $request->get_json_params();
      $amount = isset($params['amount']) ? (int) $params['amount'] : 0;
      $reason = isset($params['reason']) ? sanitize_text_field($params['reason']) : 'Transaction';
      $blog_id = self::get_current_site_id();

      $stats = self::get_user_stats($user_id, $blog_id);
      $currency_name = $stats['currency_name'];

      if ($amount === 0) {
          return rest_ensure_response([
              'success'         => true,
              'balance'         => $stats['total_gp'],
              'state'           => $stats,
              'network_summary' => self::get_user_network_stats($user_id)
          ]);
      }
      
      if ($amount < 0 && $stats['total_gp'] < abs($amount)) {
          return new WP_Error('insufficient_funds', "Not enough {$currency_name}.", ['status' => 400]);
      }
      
      $updated_stats = self::add_currency($user_id, 0, 0, $amount, $blog_id);
      
      if (class_exists('Xophz_Compass_Xp_Logs')) {
          do_action('xophz_compass_record_action', "Transaction: $reason", $user_id, ['gp' => $amount, 'site_id' => $blog_id]);
      }
      
      return rest_ensure_response([
          'success'         => true,
          'balance'         => $updated_stats['total_gp'],
          'state'           => $updated_stats,
          'network_summary' => self::get_user_network_stats($user_id)
      ]);
  }
  
  ###################################################
  ### AJAX ##########################################
  ###################################################
  
  public function listPlayers()
  {
    $out = [];
    $args = ['role' => 'achiever'];
    $users = get_users($args);
    $players = [];

    foreach ($users as $User) {
      $id = $User->ID;
      $player = Xophz_Compass_Xp_Admin::getUser($id,false);
      $player['user_login'] = $User->data->user_login;
      $player['display_name'] = $User->data->display_name;
      $player['avatar'] = get_avatar_url($User->ID);
      // Attach new stats
      $player['xp_stats'] = self::get_user_stats($id);
      $players[] = $player;
    }

    $out['players'] = $players;
    Xophz_Compass::output_json($out);
  }

  public function startPlayer()
  {
    $args = Xophz_Compass::get_input_json();
    $role = 'achiever';
    $userId = get_current_user_id();
    $theUser = new WP_User($userId);
    $theUser->add_role( $role );
    update_user_meta($userId, "_xp_birthdate", $args->birthdate);
    
    $out = ['success' => true];
    Xophz_Compass::output_json($out);
  }

  /**
   * Check if user is a PRO user
   */
  public static function is_pro_user( $user_id ) {
      if ( ! $user_id ) {
          return false;
      }
      
      // 1. Check for specific pro meta flag
      $is_pro = get_user_meta( $user_id, '_xp_is_pro', true );
      if ( $is_pro === 'yes' || $is_pro === '1' || $is_pro === 1 || $is_pro === true ) {
          return true;
      }
      
      // 2. Fallback to roles (administrator, editor, shop_manager, pro, achiever)
      $user = get_user_by( 'id', $user_id );
      if ( $user ) {
          $pro_roles = array( 'administrator', 'editor', 'shop_manager', 'pro', 'achiever' );
          foreach ( $pro_roles as $role ) {
              if ( in_array( $role, $user->roles ) ) {
                  return true;
              }
          }
      }
      
      // 3. Fallback filter for custom integrations
      return apply_filters( 'xophz_compass_is_pro_user', false, $user_id );
  }

  public function rest_set_pro_status(WP_REST_Request $request) {
      $user_id = get_current_user_id();
      $params = $request->get_json_params();
      $is_pro = isset($params['is_pro']) ? (bool) $params['is_pro'] : true;
      
      update_user_meta( $user_id, '_xp_is_pro', $is_pro ? 'yes' : 'no' );
      
      return rest_ensure_response([
          'success' => true,
          'user_id' => $user_id,
          'is_pro' => self::is_pro_user($user_id),
          'state' => self::get_user_stats($user_id)
      ]);
  }

  public function handle_nook_phone_state_saved( $user_id, $new_state, $old_state ) {
      if ( ! self::is_pro_user( $user_id ) ) {
          return;
      }
      
      // If old state is empty, establish baseline (do not award initial batch)
      if ( empty( $old_state ) ) {
          return;
      }
      
      // 1. DIY Recipes Delta Check
      $new_recipes = isset( $new_state['diy']['unlockedRecipes'] ) ? (array) $new_state['diy']['unlockedRecipes'] : [];
      $old_recipes = isset( $old_state['diy']['unlockedRecipes'] ) ? (array) $old_state['diy']['unlockedRecipes'] : [];
      $added_recipes = array_diff( $new_recipes, $old_recipes );
      foreach ( $added_recipes as $recipe_id ) {
          do_action( 'xophz_compass_record_action', 'unlock_diy_recipe', $user_id, [ 'recipe_id' => $recipe_id ] );
      }
      
      // 2. Residents Delta Check
      $new_residents = isset( $new_state['residents'] ) ? (array) $new_state['residents'] : [];
      $old_residents = isset( $old_state['residents'] ) ? (array) $old_state['residents'] : [];
      $added_residents = array_diff( $new_residents, $old_residents );
      foreach ( $added_residents as $villager_id ) {
          do_action( 'xophz_compass_record_action', 'add_resident', $user_id, [ 'villager_id' => $villager_id ] );
      }
      
      // 3. Best Friends Delta Check
      $new_bf = isset( $new_state['bestFriends'] ) ? (array) $new_state['bestFriends'] : [];
      $old_bf = isset( $old_state['bestFriends'] ) ? (array) $old_state['bestFriends'] : [];
      $added_bf = array_diff( $new_bf, $old_bf );
      foreach ( $added_bf as $villager_id ) {
          do_action( 'xophz_compass_record_action', 'add_best_friend', $user_id, [ 'villager_id' => $villager_id ] );
      }
      
      // 4. Critters Caught Delta Check
      $new_caught = isset( $new_state['critters']['caught'] ) ? (array) $new_state['critters']['caught'] : [];
      $old_caught = isset( $old_state['critters']['caught'] ) ? (array) $old_state['critters']['caught'] : [];
      $added_caught = array_diff( $new_caught, $old_caught );
      foreach ( $added_caught as $critter_id ) {
          do_action( 'xophz_compass_record_action', 'catch_critter', $user_id, [ 'critter_id' => $critter_id ] );
      }
      
      // 5. Critters Donated Delta Check
      $new_donated = isset( $new_state['critters']['donated'] ) ? (array) $new_state['critters']['donated'] : [];
      $old_donated = isset( $old_state['critters']['donated'] ) ? (array) $old_state['critters']['donated'] : [];
      $added_donated = array_diff( $new_donated, $old_donated );
      foreach ( $added_donated as $critter_id ) {
          do_action( 'xophz_compass_record_action', 'donate_critter', $user_id, [ 'critter_id' => $critter_id ] );
      }
      
      // 6. Villager Milestones Delta Check
      $new_milestones = isset( $new_state['villagerMilestones'] ) ? (array) $new_state['villagerMilestones'] : [];
      $old_milestones = isset( $old_state['villagerMilestones'] ) ? (array) $old_state['villagerMilestones'] : [];
      
      foreach ( $new_milestones as $villager_id => $new_m ) {
          $old_m = isset( $old_milestones[$villager_id] ) ? $old_milestones[$villager_id] : [];
          
          if ( !empty($new_m['talkedToday']) && empty($old_m['talkedToday']) ) {
              do_action( 'xophz_compass_record_action', 'talk_villager', $user_id, [ 'villager_id' => $villager_id ] );
          }
          if ( !empty($new_m['giftedToday']) && empty($old_m['giftedToday']) ) {
              do_action( 'xophz_compass_record_action', 'gift_villager', $user_id, [ 'villager_id' => $villager_id ] );
          }
          if ( !empty($new_m['hasPoster']) && empty($old_m['hasPoster']) ) {
              do_action( 'xophz_compass_record_action', 'earn_villager_poster', $user_id, [ 'villager_id' => $villager_id ] );
          }
          if ( !empty($new_m['hasPhoto']) && empty($old_m['hasPhoto']) ) {
              do_action( 'xophz_compass_record_action', 'earn_villager_photo', $user_id, [ 'villager_id' => $villager_id ] );
          }
      }
  }
}
