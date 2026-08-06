<?php
/**
 * Class Xophz_Compass_Xp_Abilities
 *
 * Registers XP Abilities into WP Abilities API and COMPASS registry.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Xophz_Compass_Xp_Abilities_Registry {

	public static function init() {
		add_filter( 'compass_abilities_registry', array( __CLASS__, 'register_abilities' ) );
		add_action( 'wp_abilities_init', array( __CLASS__, 'register_wp_abilities' ) );
	}

	/**
	 * Filter callback for compass_abilities_registry.
	 */
	public static function register_abilities( $abilities ) {
		if ( ! is_array( $abilities ) ) {
			$abilities = array();
		}

		$abilities[] = array(
			'id'          => 'compass/award_xp',
			'name'        => 'Award Experience Points (XP)',
			'plugin'      => 'xophz-compass-xp',
			'category'    => 'Gamification',
			'description' => 'Grants XP points to a specific player/user for performing an action.',
			'parameters'  => array(
				'user_id' => array( 'type' => 'integer', 'required' => true, 'description' => 'User ID to receive XP' ),
				'amount'  => array( 'type' => 'integer', 'required' => true, 'description' => 'Amount of XP to award' ),
				'reason'  => array( 'type' => 'string', 'required' => false, 'description' => 'Reason or action name' ),
			),
		);

		$abilities[] = array(
			'id'          => 'compass/get_player_level',
			'name'        => 'Get Player Level & Stats',
			'plugin'      => 'xophz-compass-xp',
			'category'    => 'Gamification',
			'description' => 'Fetches the current level, total XP, and progress badges for a user.',
			'parameters'  => array(
				'user_id' => array( 'type' => 'integer', 'required' => true, 'description' => 'User ID to check' ),
			),
		);

		return $abilities;
	}

	/**
	 * Register native WP Abilities if API is available.
	 */
	public static function register_wp_abilities( $registry = null ) {
		if ( function_exists( 'wp_register_ability' ) ) {
			wp_register_ability( 'compass/award_xp', array(
				'label'       => __( 'Award Experience Points (XP)', 'xophz-compass-xp' ),
				'description' => __( 'Grants XP points to a user.', 'xophz-compass-xp' ),
				'category'    => 'gamification',
			) );

			wp_register_ability( 'compass/get_player_level', array(
				'label'       => __( 'Get Player Level', 'xophz-compass-xp' ),
				'description' => __( 'Fetches user level and XP stats.', 'xophz-compass-xp' ),
				'category'    => 'gamification',
			) );
		}
	}
}

Xophz_Compass_Xp_Abilities_Registry::init();
