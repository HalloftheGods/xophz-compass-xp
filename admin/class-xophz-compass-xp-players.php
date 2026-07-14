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
        'stats'      => (object) $agnostic_stats, // Use object for JSON parsing
    ];
  }

  /**
   * Centralized method to add XP, AP, GP
   */
  public static function add_currency($user_id, $xp = 0, $ap = 0, $gp = 0) {
    if (!$user_id) return false;

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
  }

  public function rest_get_state(WP_REST_Request $request) {
      $user_id = get_current_user_id();
      $is_logged_in = is_user_logged_in();
      $state = self::get_user_stats($user_id);
      
      return rest_ensure_response([
          'is_logged_in' => $is_logged_in,
          'user_id' => $user_id,
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
}
