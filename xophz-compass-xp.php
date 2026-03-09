<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              http://example.com
 * @since             1.0.0
 * @package           Xophz_Compass_Xp
 *
 * @wordpress-plugin
 * Plugin Name:       Xophz For the XP
 * Plugin URI:        http://mycompassconsulting/xp
 * Description:       Assign tasks that reward experience points when completed. .
 * Version:           1.0.0
 * Author:            Xoph 
 * Author URI:        http://www.midknightknerd.com/xp
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       xophz-compass-xp
 * Domain Path:       /languages
 */
 // If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
  die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'XOPHZ_COMPASS_XP_VERSION', '0.0.2' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-xophz-compass-xp-activator.php
 */
function activate_xophz_compass_xp() {
  require_once plugin_dir_path( __FILE__ ) . 'includes/class-xophz-compass-xp-activator.php';
  Xophz_Compass_Xp_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-xophz-compass-xp-deactivator.php
 */
function deactivate_xophz_compass_xp() {
  require_once plugin_dir_path( __FILE__ ) . 'includes/class-xophz-compass-xp-deactivator.php';
  Xophz_Compass_Xp_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_xophz_compass_xp' );
register_deactivation_hook( __FILE__, 'deactivate_xophz_compass_xp' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-xophz-compass-xp.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_xophz_compass_xp() {
  if( !function_exists('is_plugin_active') ) {
    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
  }
  if ( !is_plugin_active( 'xophz-compass/xophz-compass.php' ) ) {
    add_action( 'admin_init', 'shutoff_xophz_compass_xp' );
    add_action( 'admin_notices', 'admin_notice_xophz_compass_xp' );

    function shutoff_xophz_compass_xp() {
      deactivate_plugins( plugin_basename( __FILE__ ) );
    }

    function admin_notice_xophz_compass_xp() {
      echo '<div class="updated"><p><strong>Xophz_Compass_Xp</strong> requires Compass to run. It has self <strong>deactivated</strong>.</p></div>';
      if ( isset( $_GET['activate'] ) )
        unset( $_GET['activate'] );
    }
  } else {
    $plugin = new Xophz_Compass_Xp();
    $plugin->run();
  }
  
}
run_xophz_compass_xp();
