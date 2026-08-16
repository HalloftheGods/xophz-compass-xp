<?php

/**
 * System Settings Manager for XP Plugin
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */
class Xophz_Compass_Xp_Settings {

  private static $option_key = 'xp_system_settings';

  /**
   * Get default XP system settings
   */
  public static function get_default_settings() {
    return [
      'profile_defaults' => [
        'initial_xp' => 0,
        'initial_gp' => 100,
        'initial_ap' => 5,
        'initial_debt' => 0,
        'require_birthdate' => true,
        'default_role' => 'achiever',
      ],
      'leveling_math' => [
        'formula' => 'exponential', // exponential, linear, tiered
        'base_xp' => 600,
        'exponent' => 1.5,
        'linear_step' => 500,
        'level_up_gp_reward' => 50,
        'level_up_ap_reward' => 2,
      ],
      'currencies' => [
        'gp_to_ai_tokens' => [
          'enabled' => true,
          'rate' => 100, // 1 GP = 100 AI Tokens
          'monthly_cap' => 100000,
        ],
        'gp_to_memecoin' => [
          'enabled' => true,
          'rate' => 0.1, // 1 GP = 0.1 Memecoin
          'token_symbol' => 'COMPASS',
        ]
      ],
      'ability_hooks' => [
        [
          'id' => 1,
          'ability_title' => 'Admin Delete Control',
          'ap_cost' => 10,
          'time_window_value' => 7,
          'time_window_unit' => 'days',
          'hook_key' => 'allow_delete',
          'description' => 'Unlocks access to permanent delete actions across sub-apps.',
        ],
        [
          'id' => 2,
          'ability_title' => 'Expanded Grid Pagination',
          'ap_cost' => 5,
          'time_window_value' => 24,
          'time_window_unit' => 'hours',
          'hook_key' => 'max_page_size_50',
          'description' => 'Increases maximum pagination grid size from 10 to 50 rows.',
        ],
        [
          'id' => 3,
          'ability_title' => 'AI Unlimited Prompt Mode',
          'ap_cost' => 25,
          'time_window_value' => 2,
          'time_window_unit' => 'weeks',
          'hook_key' => 'ai_unlimited_mode',
          'description' => 'Bypasses standard rate limits for AI generation models.',
        ]
      ]
    ];
  }

  /**
   * Fetch system settings merged with defaults
   */
  public static function get_settings() {
    $saved = get_option(self::$option_key, []);
    $defaults = self::get_default_settings();
    return wp_parse_args($saved, $defaults);
  }

  /**
   * Save system settings
   */
  public static function save_settings($settings) {
    if (!is_array($settings)) {
      return false;
    }
    $current = self::get_settings();
    $updated = array_merge($current, $settings);
    update_option(self::$option_key, $updated);
    return $updated;
  }

  /**
   * Calculate required XP threshold for a given level using configured math curve
   */
  public static function get_xp_for_level($level) {
    $settings = self::get_settings();
    $math = $settings['leveling_math'];
    $level = max(1, intval($level));

    if ($level === 1) {
      return floatval($math['base_xp']);
    }

    if ($math['formula'] === 'linear') {
      return floatval($math['base_xp']) + (($level - 1) * floatval($math['linear_step']));
    }

    // Default: Exponential (Base * Level^Exponent)
    return round(floatval($math['base_xp']) * pow($level, floatval($math['exponent'])));
  }

  /**
   * Calculate AP gained by user within a given timeframe (starting now, looking back)
   *
   * @param int $user_id
   * @param float $time_value
   * @param string $time_unit 'hours', 'days', 'weeks', 'months', 'years'
   * @return float
   */
  public static function get_user_ap_in_timeframe($user_id, $time_value = 7, $time_unit = 'days') {
    global $wpdb;
    $user_id = intval($user_id);
    if ($user_id <= 0) {
      return 0.0;
    }

    $time_value = floatval($time_value) > 0 ? floatval($time_value) : 7.0;
    $time_unit = strtolower(trim((string)$time_unit));

    switch ($time_unit) {
      case 'hours':
      case 'hour':
        $seconds = $time_value * 3600;
        break;
      case 'weeks':
      case 'week':
        $seconds = $time_value * 7 * 86400;
        break;
      case 'months':
      case 'month':
        $seconds = $time_value * 30 * 86400;
        break;
      case 'years':
      case 'year':
        $seconds = $time_value * 365 * 86400;
        break;
      case 'days':
      case 'day':
      default:
        $seconds = $time_value * 86400;
        break;
    }

    $cutoff_timestamp = time() - $seconds;
    $cutoff_datetime = date('Y-m-d H:i:s', $cutoff_timestamp);

    $total_ap = 0.0;

    // 1. Query achievements table if present
    $table_name = $wpdb->prefix . 'xp_achievements';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
      $sql = $wpdb->prepare(
        "SELECT SUM(ap) FROM {$table_name} WHERE user_id = %d AND time >= %s",
        $user_id,
        $cutoff_datetime
      );
      $db_ap = $wpdb->get_var($sql);
      if ($db_ap !== null) {
        $total_ap += floatval($db_ap);
      }
    }

    // 2. Also check usermeta achievement logs
    $sql_meta = $wpdb->prepare(
      "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE '_xp_achievement%%' AND meta_value != ''",
      $user_id
    );
    $meta_rows = $wpdb->get_results($sql_meta);
    if (!empty($meta_rows)) {
      foreach ($meta_rows as $row) {
        $data = json_decode($row->meta_value, true);
        if (is_array($data)) {
          foreach ($data as $key => $ach) {
            $parts = explode('.', (string)$key);
            $item_time = isset($parts[1]) ? intval($parts[1]) : 0;
            if ($item_time >= $cutoff_timestamp) {
              if (is_array($ach) && isset($ach['ap'])) {
                $total_ap += floatval($ach['ap']);
              } else if (is_object($ach) && isset($ach->ap)) {
                $total_ap += floatval($ach->ap);
              }
            }
          }
        }
      }
    }

    return $total_ap;
  }

  /**
   * Get active system hooks granted to a user based on AP gained within configured timeframe
   */
  public static function get_user_hooks($user_id) {
    $user_id = intval($user_id);
    $settings = self::get_settings();
    $hooks = [];

    if (empty($settings['ability_hooks']) || !is_array($settings['ability_hooks'])) {
      return $hooks;
    }

    foreach ($settings['ability_hooks'] as $ability) {
      $hook_key = $ability['hook_key'] ?? '';
      if (empty($hook_key)) {
        continue;
      }

      $ap_cost = floatval($ability['ap_cost'] ?? 0);
      $time_value = floatval($ability['time_window_value'] ?? 7);
      $time_unit = $ability['time_window_unit'] ?? 'days';

      $user_window_ap = self::get_user_ap_in_timeframe($user_id, $time_value, $time_unit);

      $hooks[$hook_key] = ($user_window_ap >= $ap_cost);
    }

    return $hooks;
  }
}
