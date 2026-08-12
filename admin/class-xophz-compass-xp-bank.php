<?php

/**
 * Centralized XP Bank & Transaction Ledger System
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */
class Xophz_Compass_Xp_Bank {

  private static $option_ledger = 'xp_bank_ledger';
  private static $option_balances = 'xp_bank_balances';

  /**
   * Get default currencies defined in the system
   */
  public static function get_default_currencies() {
    return [
      'xp' => [
        'id' => 'xp',
        'name' => 'Experience Points',
        'symbol' => 'XP',
        'icon' => 'fad fa-sparkles',
        'color' => '#00e5ff',
        'is_transferable' => false,
      ],
      'gp' => [
        'id' => 'gp',
        'name' => 'Gold Pieces',
        'symbol' => 'GP',
        'icon' => 'fad fa-coins',
        'color' => '#ffd700',
        'is_transferable' => true,
      ],
      'ap' => [
        'id' => 'ap',
        'name' => 'Ability Points',
        'symbol' => 'AP',
        'icon' => 'fad fa-bolt',
        'color' => '#ff4081',
        'is_transferable' => false,
      ],
      'ai_tokens' => [
        'id' => 'ai_tokens',
        'name' => 'AI Usage Tokens',
        'symbol' => 'TOKENS',
        'icon' => 'fad fa-robot',
        'color' => '#7c4dff',
        'is_transferable' => true,
      ],
      'memecoin' => [
        'id' => 'memecoin',
        'name' => 'Crypto / Memecoin',
        'symbol' => 'COIN',
        'icon' => 'fad fa-coins-directional',
        'color' => '#00e676',
        'is_transferable' => true,
      ]
    ];
  }

  /**
   * Record a transaction in the immutable ledger
   */
  public static function record_transaction($user_id, $currency, $amount, $type = 'reward', $reference = '') {
    $user_id = intval($user_id);
    if (!$user_id || empty($currency) || $amount == 0) {
      return false;
    }

    $ledger = get_option(self::$option_ledger, []);
    $balances = get_option(self::$option_balances, []);

    if (!isset($balances[$user_id])) {
      $balances[$user_id] = [
        'xp' => 0,
        'gp' => 0,
        'ap' => 0,
        'ai_tokens' => 0,
        'memecoin' => 0,
      ];
    }

    $current_bal = isset($balances[$user_id][$currency]) ? floatval($balances[$user_id][$currency]) : 0;
    $new_bal = $current_bal + floatval($amount);
    if ($new_bal < 0 && in_array($type, ['spend', 'burn', 'exchange'])) {
      // Prevent negative balances unless debt is allowed
      $new_bal = 0;
    }

    $balances[$user_id][$currency] = $new_bal;

    $tx = [
      'id' => uniqid('tx_'),
      'timestamp' => current_time('mysql'),
      'user_id' => $user_id,
      'currency' => sanitize_text_field($currency),
      'amount' => floatval($amount),
      'balance_after' => $new_bal,
      'type' => sanitize_text_field($type), // mint, reward, spend, burn, exchange
      'reference' => sanitize_text_field($reference),
    ];

    // Prepend to ledger (keep max 1000 recent transactions)
    array_unshift($ledger, $tx);
    if (count($ledger) > 1000) {
      $ledger = array_slice($ledger, 0, 1000);
    }

    update_option(self::$option_ledger, $ledger);
    update_option(self::$option_balances, $balances);

    // Sync legacy user meta for backwards compatibility
    if (in_array($currency, ['xp', 'gp', 'ap'])) {
      update_user_meta($user_id, 'xp_' . $currency, $new_bal);
    }

    return $tx;
  }

  /**
   * Get user balance for all currencies or a specific currency
   */
  public static function get_balances($user_id) {
    $user_id = intval($user_id);
    $balances = get_option(self::$option_balances, []);

    $defaults = [
      'xp' => floatval(get_user_meta($user_id, 'xp_xp', true) ?: 0),
      'gp' => floatval(get_user_meta($user_id, 'xp_gp', true) ?: 0),
      'ap' => floatval(get_user_meta($user_id, 'xp_ap', true) ?: 0),
      'ai_tokens' => 0,
      'memecoin' => 0,
    ];

    if (isset($balances[$user_id])) {
      return wp_parse_args($balances[$user_id], $defaults);
    }

    return $defaults;
  }

  /**
   * Convert currency (e.g. GP to AI Tokens or Memecoins)
   */
  public static function convert_currency($user_id, $from_curr, $to_curr, $from_amount, $rate = 1.0) {
    $user_id = intval($user_id);
    $from_amount = floatval($from_amount);
    $rate = floatval($rate);

    if ($from_amount <= 0 || $rate <= 0) {
      return new WP_Error('invalid_amount', 'Amount and conversion rate must be greater than zero.');
    }

    $balances = self::get_balances($user_id);
    if (!isset($balances[$from_curr]) || $balances[$from_curr] < $from_amount) {
      return new WP_Error('insufficient_funds', 'Insufficient balance for exchange.');
    }

    $to_amount = $from_amount * $rate;

    // Record burn/spend on source currency
    self::record_transaction($user_id, $from_curr, -$from_amount, 'exchange', "Converted to {$to_curr}");
    // Record mint/receive on target currency
    self::record_transaction($user_id, $to_curr, $to_amount, 'exchange', "Converted from {$from_curr}");

    return [
      'user_id' => $user_id,
      'from_currency' => $from_curr,
      'from_amount' => $from_amount,
      'to_currency' => $to_curr,
      'to_amount' => $to_amount,
      'new_balances' => self::get_balances($user_id),
    ];
  }

  /**
   * Get filtered transaction ledger
   */
  public static function get_ledger($user_id = 0, $limit = 50) {
    $ledger = get_option(self::$option_ledger, []);
    if ($user_id > 0) {
      $ledger = array_values(array_filter($ledger, function($tx) use ($user_id) {
        return intval($tx['user_id']) === intval($user_id);
      }));
    }
    return array_slice($ledger, 0, $limit);
  }
}
