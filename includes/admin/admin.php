<?php
/**
 * Admin Interface
 *
 * @package WCS_Sublium_Migrator\Admin
 */

namespace WCS_Sublium_Migrator\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 *
 * Handles admin interface.
 */
class Admin {

	/**
	 * Instance.
	 *
	 * @var Admin
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {

		// Use priority 25 to ensure WooCommerce menu exists (WooCommerce uses priority 9).
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 25 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );


	}



	/**
	 * Add admin menu.
	 *
	 * @return void
	 */
	public function add_admin_menu() {

		// Check if user has permission.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Prevent duplicate menu items.
		global $submenu;
		if ( isset( $submenu['woocommerce'] ) ) {
			foreach ( $submenu['woocommerce'] as $item ) {
				if ( isset( $item[2] ) && 'wcs-sublium-migrator' === $item[2] ) {
					return; // Menu already added.
				}
			}
		}


		// Add submenu to WooCommerce menu.
		$hook = add_submenu_page(
			'woocommerce',
			__( 'WCS to Sublium Migration', 'wcs-sublium-migrator' ),
			__( 'WCS Migration', 'wcs-sublium-migrator' ),
			'manage_woocommerce',
			'wcs-sublium-migrator',
			array( $this, 'render_admin_page' )
		);

		// If hook is false, WooCommerce menu might not exist - try adding to tools menu as fallback.
		if ( false === $hook ) {
			add_management_page(
				__( 'WCS to Sublium Migration', 'wcs-sublium-migrator' ),
				__( 'WCS Migration', 'wcs-sublium-migrator' ),
				'manage_woocommerce',
				'wcs-sublium-migrator',
				array( $this, 'render_admin_page' )
			);
		}
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wcs-sublium-migrator' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wcs-sublium-migrator-admin',
			WCS_SUBLIUM_MIGRATOR_URL . 'assets/admin.css',
			array(),
			WCS_SUBLIUM_MIGRATOR_VERSION
		);

		wp_enqueue_script(
			'wcs-sublium-migrator-admin',
			WCS_SUBLIUM_MIGRATOR_URL . 'assets/admin.js',
			array( 'jquery' ),
			WCS_SUBLIUM_MIGRATOR_VERSION,
			true
		);

		wp_localize_script(
			'wcs-sublium-migrator-admin',
			'wcsSubliumMigrator',
			array(
				'apiUrl'   => rest_url( 'wcs-sublium-migrator/v1/' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'strings' => array(
					'startMigration'   => __( 'Start Migration', 'wcs-sublium-migrator' ),
					'pauseMigration'   => __( 'Pause Migration', 'wcs-sublium-migrator' ),
					'resumeMigration'  => __( 'Resume Migration', 'wcs-sublium-migrator' ),
					'cancelMigration'  => __( 'Cancel Migration', 'wcs-sublium-migrator' ),
					'loading'          => __( 'Loading...', 'wcs-sublium-migrator' ),
					'error'           => __( 'An error occurred', 'wcs-sublium-migrator' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		?>
		<div class="wrap wcs-sublium-migrator-wrap">
			<h1><?php esc_html_e( 'WooCommerce Subscriptions to Sublium Migration', 'wcs-sublium-migrator' ); ?></h1>

			<div id="wcs-sublium-migrator-app">
				<div class="wcs-migrator-loading">
					<p><?php esc_html_e( 'Loading migration interface...', 'wcs-sublium-migrator' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
