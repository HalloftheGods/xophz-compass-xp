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
          'hook_key' => 'allow_delete',
          'description' => 'Unlocks access to permanent delete actions across sub-apps.',
        ],
        [
          'id' => 2,
          'ability_title' => 'Expanded Grid Pagination',
          'ap_cost' => 5,
          'hook_key' => 'max_page_size_50',
          'description' => 'Increases maximum pagination grid size from 10 to 50 rows.',
        ],
        [
          'id' => 3,
          'ability_title' => 'AI Unlimited Prompt Mode',
          'ap_cost' => 25,
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
   * Get active system hooks granted to a user based on AP/Level/Unlocked abilities
   */
  public static function get_user_hooks($user_id) {
    $user_id = intval($user_id);
    $user_ap = floatval(get_user_meta($user_id, 'xp_ap', true) ?: 0);
    $settings = self::get_settings();
    $hooks = [];

    foreach ($settings['ability_hooks'] as $ability) {
      if ($user_ap >= floatval($ability['ap_cost'])) {
        $hooks[$ability['hook_key']] = true;
      } else {
        $hooks[$ability['hook_key']] = false;
      }
    }

    return $hooks;
  }
}
