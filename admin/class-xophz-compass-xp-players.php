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
   * Database / User Metadata Controller
   */
  public static function get_user_stats($user_id) {
    if (!$user_id) {
        return [
            'level' => 1, 'current_xp' => 0, 'target_xp' => self::get_required_xp_for_level(2),
            'total_xp' => 0, 'total_ap' => 0, 'total_gp' => 0, 'title' => 'Guest Scripter',
            'stats' => []
        ];
    }

    $total_xp = (int) get_user_meta($user_id, '_xp_total_xp', true) ?: 0;
    $total_ap = (int) get_user_meta($user_id, '_xp_total_ap', true) ?: 0;
    $total_gp = (int) get_user_meta($user_id, '_xp_total_gp', true) ?: 0;
    $level = self::calculate_level($total_xp);
    
    // Ensure meta level matches calculated level
    update_user_meta($user_id, '_xp_total_level', $level);

    $xp_floor = self::get_required_xp_for_level($level);
    $xp_ceil = self::get_required_xp_for_level($level + 1);
    $current_xp_in_level = $total_xp - $xp_floor;
    $target_xp_for_level = $xp_ceil - $xp_floor;

    // Get agnostic stats
    $all_meta = get_user_meta($user_id);
    $agnostic_stats = [];
    foreach ($all_meta as $key => $values) {
        if (strpos($key, '_xp_stat_') === 0) {
            $stat_slug = str_replace('_xp_stat_', '', $key);
            $agnostic_stats[$stat_slug] = (int) $values[0];
        }
    }

    $titles = [
        1 => 'Novice Scripter', 2 => 'Script Apprentice', 3 => 'Syntax Warrior',
        4 => 'Fullstack Alchemist', 5 => 'Master Architect'
    ];
    $title = isset($titles[$level]) ? $titles[$level] : 'Gamification Deity';

    return [
        'level'      => $level,
        'current_xp' => $current_xp_in_level,
        'target_xp'  => $target_xp_for_level,
        'total_xp'   => $total_xp,
        'total_ap'   => $total_ap,
        'total_gp'   => $total_gp,
        'title'      => $title,
        'is_pro'     => self::is_pro_user($user_id),
        'stats'      => (object) $agnostic_stats, // Use object for JSON parsing
    ];
  }

  /**
   * Centralized method to add XP, AP, GP
   */
  public static function add_currency($user_id, $xp = 0, $ap = 0, $gp = 0) {
    if (!$user_id) return false;

    // XP and AP are exclusive to PRO accounts
    if ( ! self::is_pro_user($user_id) ) {
        $xp = 0;
        $ap = 0;
    }

    $stats = self::get_user_stats($user_id);
    $old_level = $stats['level'];

    if ($xp > 0) update_user_meta($user_id, '_xp_total_xp', $stats['total_xp'] + $xp);
    if ($ap > 0) update_user_meta($user_id, '_xp_total_ap', $stats['total_ap'] + $ap);
    if ($gp > 0) update_user_meta($user_id, '_xp_total_gp', $stats['total_gp'] + $gp);

    // Re-evaluate level
    $new_stats = self::get_user_stats($user_id);
    if ($new_stats['level'] > $old_level) {
        do_action('xophz_compass_user_leveled_up', $user_id, $new_stats['level'], $old_level);
        
        // Let's log the level up!
        if (class_exists('Xophz_Compass_Xp_Logs')) {
             // We can fire an action that logs it, or assume Goals will handle it.
             do_action('xophz_compass_record_action', 'level_up', $user_id, ['new_level' => $new_stats['level']]);
        }
    }

    return $new_stats;
  }
  
  /**
   * Add / Update Agnostic Stat
   */
  public static function modify_stat($user_id, $stat_slug, $amount) {
      if (!$user_id) return false;
      $current = (int) get_user_meta($user_id, "_xp_stat_{$stat_slug}", true) ?: 0;
      $new_val = $current + $amount;
      update_user_meta($user_id, "_xp_stat_{$stat_slug}", $new_val);
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
      $state = self::get_user_stats($user_id);
      
      return rest_ensure_response([
          'is_logged_in' => $is_logged_in,
          'user_id' => $user_id,
          'is_pro' => self::is_pro_user($user_id),
          'state' => $state
      ]);
  }

  public function rest_simulate_decay(WP_REST_Request $request) {
      $user_id = get_current_user_id();
      $params = $request->get_json_params();
      $ap_decay = isset($params['ap']) ? (int) $params['ap'] : 0;
      
      $stats = self::get_user_stats($user_id);
      $new_ap = max(0, $stats['total_ap'] - $ap_decay);
      
      update_user_meta($user_id, '_xp_total_ap', $new_ap);
      
      if (class_exists('Xophz_Compass_Xp_Logs')) {
          do_action('xophz_compass_record_action', 'Simulate Weekly AP Decay', $user_id, ['ap' => -$ap_decay]);
      }
      
      return rest_ensure_response([
          'success' => true,
          'state' => self::get_user_stats($user_id)
      ]);
  }

  public function rest_spend_gp(WP_REST_Request $request) {
      $user_id = get_current_user_id();
      $params = $request->get_json_params();
      $gp_cost = isset($params['gp']) ? (int) $params['gp'] : 0;
      $item_name = isset($params['item']) ? sanitize_text_field($params['item']) : 'Unknown Item';
      
      $stats = self::get_user_stats($user_id);
      
      if ($stats['total_gp'] < $gp_cost) {
          return new WP_Error('insufficient_funds', 'Not enough Gold Points.', ['status' => 400]);
      }
      
      $new_gp = $stats['total_gp'] - $gp_cost;
      update_user_meta($user_id, '_xp_total_gp', $new_gp);
      
      if (class_exists('Xophz_Compass_Xp_Logs')) {
          do_action('xophz_compass_record_action', "Purchased: $item_name", $user_id, ['gp' => -$gp_cost]);
      }
      
      return rest_ensure_response([
          'success' => true,
          'state' => self::get_user_stats($user_id)
      ]);
  }

  public function rest_transaction(WP_REST_Request $request) {
      $user_id = get_current_user_id();
      $params = $request->get_json_params();
      $amount = isset($params['amount']) ? (int) $params['amount'] : 0;
      $reason = isset($params['reason']) ? sanitize_text_field($params['reason']) : 'Transaction';
      
      if ($amount === 0) {
          return rest_ensure_response(['success' => true, 'balance' => (int)get_user_meta($user_id, '_xp_total_gp', true)]);
      }

      $stats = self::get_user_stats($user_id);
      
      // If spending (negative amount), check funds
      if ($amount < 0 && $stats['total_gp'] < abs($amount)) {
          return new WP_Error('insufficient_funds', 'Not enough Bells.', ['status' => 400]);
      }
      
      $new_gp = $stats['total_gp'] + $amount;
      update_user_meta($user_id, '_xp_total_gp', $new_gp);
      
      if (class_exists('Xophz_Compass_Xp_Logs')) {
          do_action('xophz_compass_record_action', "Transaction: $reason", $user_id, ['gp' => $amount]);
      }
      
      return rest_ensure_response([
          'success' => true,
          'balance' => $new_gp
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
