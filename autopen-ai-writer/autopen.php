<?php
/**
 * Plugin Name:       AutoPen – AI Content Writer
 * Description:       Automates the generation and publishing of high-quality, SEO-focused blog posts using the OpenAI API, with WP Cron automation and deep integration with popular SEO plugins.
 * Version:           1.0.0
 * Author:            Md Latiful Khabir Khan Imran
 * Author URI:        https://www.linkedin.com/in/lkimran007/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autopen-ai-writer
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants for easy access and modification.
define( 'AUTOPEN_VERSION', '1.0.0' );
define( 'AUTOPEN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUTOPEN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AUTOPEN_SLUG', 'autopen-ai-writer' );
define( 'AUTOPEN_OPTIONS_KEY', 'autopen_options' );
define( 'AUTOPEN_CRON_HOOK', 'autopen_cron_event' );

/**
 * The core plugin class.
 *
 * This class is responsible for loading dependencies, defining hooks,
 * and setting up the plugin's core functionality. It follows a singleton
 * pattern to ensure only one instance of the class is loaded.
 *
 * @since 1.0.0
 */
final class AutoPen_Core {

	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var   AutoPen_Core|null
	 */
	private static ?AutoPen_Core $instance = null;

	/**
	 * Gets the single instance of the class.
	 *
	 * Ensures that only one instance of the plugin's core class exists.
	 *
	 * @since  1.0.0
	 * @return AutoPen_Core The single instance of the class.
	 */
	public static function get_instance(): AutoPen_Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Loads all the required plugin dependencies.
	 *
	 * Includes all necessary class files from the 'includes' and 'admin' directories.
	 *
	 * @since 1.0.0
	 */
	private function load_dependencies(): void {
		require_once AUTOPEN_PLUGIN_DIR . 'includes/class-autopen-utils.php';
		require_once AUTOPEN_PLUGIN_DIR . 'includes/class-autopen-logger.php';
		require_once AUTOPEN_PLUGIN_DIR . 'includes/class-autopen-api.php';
		require_once AUTOPEN_PLUGIN_DIR . 'includes/class-autopen-cron.php';
		require_once AUTOPEN_PLUGIN_DIR . 'includes/class-autopen-media.php';
		require_once AUTOPEN_PLUGIN_DIR . 'includes/class-autopen-seo.php';
		require_once AUTOPEN_PLUGIN_DIR . 'includes/class-autopen-generator.php';
		require_once AUTOPEN_PLUGIN_DIR . 'includes/class-autopen-activator.php';
		require_once AUTOPEN_PLUGIN_DIR . 'includes/class-autopen-deactivator.php';
		require_once AUTOPEN_PLUGIN_DIR . 'admin/class-autopen-admin.php';
	}

	/**
	 * Initializes all WordPress hooks.
	 *
	 * Registers activation, deactivation, and other core hooks.
	 * Initializes the admin and cron handling classes.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks(): void {
		register_activation_hook( __FILE__, [ 'AutoPen_Activator', 'activate' ] );
		register_deactivation_hook( __FILE__, [ 'AutoPen_Deactivator', 'deactivate' ] );

		// Initialize admin and cron functionality.
		$admin = new AutoPen_Admin();
		$admin->init();
		
		$cron = new AutoPen_Cron();
		$cron->init();
	}
}

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * kicking off the plugin from this point is as simple as calling
 * the get_instance() method of the core class.
 *
 * @since 1.0.0
 */
function autopen_run_plugin(): void {
	AutoPen_Core::get_instance();
}

// Run the plugin.
autopen_run_plugin();