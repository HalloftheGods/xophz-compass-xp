<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes
 * @author     Your Name <email@example.com>
 */
class Xophz_Compass_Xp {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Xophz_Compass_Xp_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
    if ( defined( 'XOPHZ_COMPASS_XP_VERSION' ) ) {
      $this->version = XOPHZ_COMPASS_XP_VERSION;
    } else {
      $this->version = '1.0.0';
    }
    $this->plugin_name = 'xophz-compass-xp';
    $this->load_dependencies();
    $this->set_locale();
    $this->define_admin_hooks();
    $this->define_compass_hooks();
    $this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Xophz_Compass_Xp_Loader. Orchestrates the hooks of the plugin.
	 * - Xophz_Compass_Xp_i18n. Defines internationalization functionality.
	 * - Xophz_Compass_Xp_Admin. Defines all hooks for the admin area.
	 * - Xophz_Compass_Xp_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
    /**
    * The class responsible for orchestrating the actions and filters of the
    * core plugin.
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-xophz-compass-xp-loader.php';

    /**
    * The class responsible for defining internationalization functionality
    * of the plugin.
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-xophz-compass-xp-i18n.php';

    /**
    * The class responsible for defining all actions that occur in the admin area.
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-xp-admin.php';
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-xp-achievements.php';
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-xp-abilities.php';
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-xp-accessories.php';
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-xophz-compass-xp-players.php';

    /**
    * The class responsible for defining all actions that occur in the public-facing
    * side of the site.
    */
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-xophz-compass-xp-public.php';

    $this->loader = new Xophz_Compass_Xp_Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Xophz_Compass_Xp_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Xophz_Compass_Xp_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

    $plugin_admin = new Xophz_Compass_Xp_Admin( $this->get_xophz_compass_xp(), $this->get_version() );
    $this->define_class_hooks( $plugin_admin );
	}

  private function define_compass_hooks(){
    $this->define_class_hooks( 'Xophz_Compass_Xp_Achievements' );
    $this->define_class_hooks( 'Xophz_Compass_Xp_Abilities' );
    $this->define_class_hooks( 'Xophz_Compass_Xp_Accessories' );
    $this->define_class_hooks( 'Xophz_Compass_Xp_Players' );
  } 

  public function define_class_hooks($class){
    if(is_string($class))
      $class = new $class($this->plugin_name, $this->version);

    $hook_props = [
        'action_hooks' => 'add_action',
        'filter_hooks' => 'add_filter'
    ];

    foreach ($hook_props as $prop => $loader_method) {
        if (isset($class->$prop) && is_array($class->$prop)) {
            foreach ($class->$prop as $hook_name => $hooks_data) {
                $registrations = [];
                
                if (is_string($hooks_data)) {
                    $registrations[] = [$hooks_data, 10, 1];
                } elseif (is_array($hooks_data)) {
                    // Check if it's a single registration with config: ['method', priority, args]
                    if (isset($hooks_data[0]) && is_string($hooks_data[0]) && isset($hooks_data[1]) && is_int($hooks_data[1])) {
                        $registrations[] = [
                            $hooks_data[0], 
                            $hooks_data[1], 
                            isset($hooks_data[2]) ? $hooks_data[2] : 1
                        ];
                    } else {
                        // Treat as a list of registrations (can be strings or [method, p, a] arrays)
                        foreach ($hooks_data as $h) {
                            if (is_string($h)) {
                                $registrations[] = [$h, 10, 1];
                            } elseif (is_array($h) && isset($h[0]) && is_string($h[0])) {
                                $registrations[] = [
                                    $h[0],
                                    isset($h[1]) ? $h[1] : 10,
                                    isset($h[2]) ? $h[2] : 1
                                ];
                            }
                        }
                    }
                }

                foreach ($registrations as $reg) {
                    $this->loader->$loader_method($hook_name, $class, $reg[0], $reg[1], $reg[2]);
                }
            }
        }
    }
  }

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Xophz_Compass_Xp_Public( $this->get_xophz_compass_xp(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_xophz_compass_xp() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Xophz_Compass_Xp_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
