<?php
/**
 * Plugin Name: WCS to Sublium Migrator
 * Plugin URI: https://sublium.com
 * Description: Migrate WooCommerce Subscriptions data to Sublium with background processing and feasibility analysis.
 * Version: 1.0.0
 * Author: Sublium
 * Author URI: https://sublium.com
 * Text Domain: wcs-sublium-migrator
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package WCS_Sublium_Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Define plugin constants.
define( 'WCS_SUBLIUM_MIGRATOR_VERSION', '1.0.0' );
define( 'WCS_SUBLIUM_MIGRATOR_FILE', __FILE__ );
define( 'WCS_SUBLIUM_MIGRATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCS_SUBLIUM_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'WCS_SUBLIUM_MIGRATOR_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class.
 */
class WCS_Sublium_Migrator {

	/**
	 * Plugin instance.
	 *
	 * @var WCS_Sublium_Migrator
	 */
	private static $instance = null;

	/**
	 * Get plugin instance.
	 *
	 * @return WCS_Sublium_Migrator
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	private function __construct() {
		// Declare HPOS compatibility early - must be on before_woocommerce_init hook.
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		$this->check_dependencies();

		$this->init();

	}

	/**
	 * Check plugin dependencies.
	 *
	 * @return void
	 */
	private function check_dependencies() {
		// Check if WooCommerce is active.
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		// Check if WooCommerce Subscriptions is active.
		if ( ! $this->is_wcs_active() ) {
			add_action( 'admin_notices', array( $this, 'wcs_missing_notice' ) );
			return;
		}

		// Check if Sublium is active.
		if ( ! $this->is_sublium_active() ) {
			add_action( 'admin_notices', array( $this, 'sublium_missing_notice' ) );
			return;
		}

	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		// Check if WooCommerce class exists (loaded).
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		// Check if WooCommerce plugin is active.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'woocommerce/woocommerce.php' );
	}

	/**
	 * Check if WooCommerce Subscriptions is active.
	 *
	 * @return bool
	 */
	private function is_wcs_active() {
		// Check if WC_Subscriptions class exists (loaded).
		if ( class_exists( '\WC_Subscriptions' ) || class_exists( 'WC_Subscriptions' ) ) {
			return true;
		}

		// Check if WooCommerce Subscriptions plugin is active.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check common WCS plugin paths.
		$wcs_paths = array(
			'woocommerce-subscriptions/woocommerce-subscriptions.php',
			'woocommerce-subscriptions/woocommerce-subscriptions.php',
		);

		// Also check all active plugins for WCS.
		$active_plugins = get_option( 'active_plugins', array() );
		foreach ( $active_plugins as $plugin ) {
			if ( strpos( $plugin, 'woocommerce-subscriptions' ) !== false ) {
				return true;
			}
		}

		// Check network active plugins if multisite.
		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			foreach ( $network_plugins as $plugin => $timestamp ) {
				if ( strpos( $plugin, 'woocommerce-subscriptions' ) !== false ) {
					return true;
				}
			}
		}

		foreach ( $wcs_paths as $path ) {
			if ( is_plugin_active( $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if Sublium is active.
	 *
	 * @return bool
	 */
	private function is_sublium_active() {
		// Check if Sublium class exists (loaded).
		if ( class_exists( '\Sublium_WCS\Plugin' ) ) {
			return true;
		}

		// Check if Sublium plugin is active.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check common Sublium plugin paths.
		$sublium_paths = array(
			'sublium-subscriptions-for-woocommerce/plugin.php',
			'sublium-subscriptions-for-woocommerce/sublium-subscriptions-for-woocommerce.php',
		);

		foreach ( $sublium_paths as $path ) {
			if ( is_plugin_active( $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Initialize plugin.
	 *
	 * @return void
	 */
	private function init() {
		// Load autoloader first so classes can be loaded.
		spl_autoload_register( array( $this, 'autoload' ) );

		// Initialize admin - use admin_init hook to ensure WordPress is fully loaded.
		add_action( 'init', array( $this, 'init_admin' ) );

		// Only initialize migration features if dependencies are met.
		if ( ! $this->is_woocommerce_active() || ! $this->is_wcs_active() || ! $this->is_sublium_active() ) {
			return;
		}

		// Initialize migration scheduler.
		WCS_Sublium_Migrator\Migration\Scheduler::get_instance();

		// Initialize API.
		WCS_Sublium_Migrator\API\Migration_API::get_instance();

		// Initialize WCS renewal blocker (always active to prevent renewals for migrated subscriptions).
		WCS_Sublium_Migrator\Migration\WCS_Renewal_Blocker::get_instance();

		// Register activation/deactivation hooks.
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Initialize admin interface.
	 *
	 * @return void
	 */
	public function init_admin() {

		WCS_Sublium_Migrator\Admin\Admin::get_instance();

	}

	/**
	 * Autoloader for plugin classes.
	 *
	 * @param string $class Class name.
	 * @return void
	 */
	public function autoload( $class ) {
		if ( strpos( $class, 'WCS_Sublium_Migrator\\' ) !== 0 ) {
			return;
		}

		$class_name = str_replace( 'WCS_Sublium_Migrator\\', '', $class );

		// Split namespace and class name.
		$parts = explode( '\\', $class_name );
		$class_file = array_pop( $parts );

		// Convert namespace parts to lowercase directories.
		$namespace_path = '';
		if ( ! empty( $parts ) ) {
			$namespace_path = strtolower( implode( '/', $parts ) ) . '/';
		}

		// Convert class name from CamelCase to kebab-case.
		$class_file = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_file ) );
		$class_file = str_replace( '_', '-', $class_file );

		$file = WCS_SUBLIUM_MIGRATOR_DIR . 'includes/' . $namespace_path . $class_file . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public function activate() {
		// Create database tables if needed.
		WCS_Sublium_Migrator\Migration\State::create_tables();
	}

	/**
	 * Declare HPOS (High-Performance Order Storage) compatibility.
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCS_SUBLIUM_MIGRATOR_FILE, true );
		}
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate() {
		// Clear scheduled events.
		wp_clear_scheduled_hook( 'wcs_sublium_migrate_products_batch' );
		wp_clear_scheduled_hook( 'wcs_sublium_migrate_subscriptions_batch' );
	}

	/**
	 * WooCommerce missing notice.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'WCS to Sublium Migrator requires WooCommerce to be installed and active.', 'wcs-sublium-migrator' ); ?></p>
		</div>
		<?php
	}

	/**
	 * WCS missing notice.
	 *
	 * @return void
	 */
	public function wcs_missing_notice() {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'WCS to Sublium Migrator requires WooCommerce Subscriptions to be installed and active.', 'wcs-sublium-migrator' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Sublium missing notice.
	 *
	 * @return void
	 */
	public function sublium_missing_notice() {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'WCS to Sublium Migrator requires Sublium Subscriptions for WooCommerce to be installed and active.', 'wcs-sublium-migrator' ); ?></p>
		</div>
		<?php
	}
}

// Initialize plugin.
WCS_Sublium_Migrator::get_instance();
