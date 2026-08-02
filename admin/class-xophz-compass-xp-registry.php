<?php

/**
 * The admin-specific functionality for XP Registry.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin
 */

class Xophz_Compass_Xp_Registry {

  private $plugin_name;
  private $version;

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
  }

  /**
   * Get all registered stats via filter.
   *
   * @return array Array of registered stats.
   */
  public static function get_registered_stats() {
    $default_stats = [
      'xp' => [
        'slug'     => 'xp',
        'label'    => 'Experience Points (XP)',
        'behavior' => 'cumulative',
        'default'  => 0,
      ],
      'gp' => [
        'slug'     => 'gp',
        'label'    => 'Gold Points (GP)',
        'behavior' => 'currency',
        'default'  => 0,
      ],
      'ap' => [
        'slug'     => 'ap',
        'label'    => 'Ability Points (AP)',
        'behavior' => 'cumulative', // AP is tracked globally
        'default'  => 0,
      ],
    ];

    /**
     * Filter to allow external plugins to register custom stats.
     * 
     * @param array $stats The array of registered stats.
     */
    return apply_filters( 'xophz_compass_register_stats', $default_stats );
  }
}
