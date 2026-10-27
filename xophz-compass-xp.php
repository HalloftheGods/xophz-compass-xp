<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://youmeos.com
 * @since             1.0.0
 * @package           Xophz_Compass_Xp
 *
 * @wordpress-plugin
 * Category:          True North
 * Group:             LXP
 * Plugin Name:       Xophz For the XP
 * Plugin URI:        https://forthexp.com
 * Description:       Reward your visitor's loyalty by gamifying their actions and milestones with points, badges, and levels.
 * Version:           26.10.26
 * Author:            Hall of the Gods, Inc. 
 * Author URI:        http://www.midknightknerd.com/xp
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       xophz-compass-xp
 * Domain Path:       /languages
 * Update URI:        https://github.com/HalloftheGods/xophz-compass-xp
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
define( 'XOPHZ_COMPASS_XP_VERSION', '26.10.26' );

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
	Xophz_Compass_Xp::deactivate();
}

register_activation_hook( __FILE__, 'activate_xophz_compass_xp' );
register_deactivation_hook( __FILE__, 'deactivate_xophz_compass_xp' );

/**
 * A plugin folder can be incomplete for a moment (a deploy still copying) or for good (a failed
 * upload). A hard require then takes every site that activates the plugin down, which is what
 * happened on 2026-10-26. When a bootstrap include is missing: log it, show an admin notice, define
 * XOPHZ_COMPASS_XP_INCOMPLETE and sit this request out; the next request loads normally once the
 * files are there.
 *
 * @return bool True when both bootstrap includes can be read.
 */
function xophz_compass_xp_includes_ready() {
  $dir = plugin_dir_path( __FILE__ ) . 'includes/';
  foreach ( array( 'class-xophz-compass-xp.php', 'class-xophz-compass-xp-abilities.php' ) as $file ) {
    if ( is_readable( $dir . $file ) ) {
      continue;
    }
    define( 'XOPHZ_COMPASS_XP_INCOMPLETE', $dir . $file );
    error_log( 'xophz-compass-xp: ' . $dir . $file . ' is missing, the plugin folder is incomplete (a deploy still copying, or a failed upload); the plugin is not loaded for this request.' );
    $notice = function () use ( $file ) {
      echo '<div class="notice notice-error"><p><strong>Xophz For the XP</strong> is not loaded: <code>includes/' . esc_html( $file ) . '</code> is missing from its plugin folder. Wait for the deploy to finish, or reinstall the plugin.</p></div>';
    };
    add_action( 'admin_notices', $notice );
    add_action( 'network_admin_notices', $notice );
    return false;
  }
  return true;
}

if ( ! xophz_compass_xp_includes_ready() ) {
  return;
}

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-xophz-compass-xp.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-xophz-compass-xp-abilities.php';

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
  if ( ! class_exists( 'Xophz_Compass' ) ) {
    add_action( 'admin_init', 'shutoff_xophz_compass_xp' );
    add_action( 'admin_notices', 'admin_notice_xophz_compass_xp' );

    function shutoff_xophz_compass_xp() {
      if ( ! function_exists( 'deactivate_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
      }
      deactivate_plugins( plugin_basename( __FILE__ ) );
    }

    function admin_notice_xophz_compass_xp() {
      echo '<div class="error"><h2><strong>Xophz For the XP</strong> requires Compass to run. It has self <strong>deactivated</strong>.</h2></div>';
      if ( isset( $_GET['activate'] ) )
        unset( $_GET['activate'] );
    }
  } else {
    $plugin = new Xophz_Compass_Xp();
    $plugin->run();
  }
}
add_action( 'plugins_loaded', 'run_xophz_compass_xp' );
