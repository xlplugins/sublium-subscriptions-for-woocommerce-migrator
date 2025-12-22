<?php
/**
 * Migration API Endpoints
 *
 * @package WCS_Sublium_Migrator\API
 */

namespace WCS_Sublium_Migrator\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Migration_API
 *
 * REST API endpoints for migration.
 */
class Migration_API {

	/**
	 * Instance.
	 *
	 * @var Migration_API
	 */
	private static $instance = null;

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	private $namespace = 'wcs-sublium-migrator/v1';

	/**
	 * Get instance.
	 *
	 * @return Migration_API
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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Discovery endpoint.
		register_rest_route(
			$this->namespace,
			'/discovery',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_discovery' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Start migration endpoint.
		register_rest_route(
			$this->namespace,
			'/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_migration' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Status endpoint.
		register_rest_route(
			$this->namespace,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Pause endpoint.
		register_rest_route(
			$this->namespace,
			'/pause',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pause_migration' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Resume endpoint.
		register_rest_route(
			$this->namespace,
			'/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resume_migration' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Cancel endpoint.
		register_rest_route(
			$this->namespace,
			'/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_migration' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Check permissions.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get discovery data.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_discovery( $request ) {
		$discovery = new \WCS_Sublium_Migrator\Migration\Discovery();
		$data = $discovery->get_feasibility_data();

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Start migration.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function start_migration( $request ) {
		$scheduler = \WCS_Sublium_Migrator\Migration\Scheduler::get_instance();
		$result = $scheduler->start_migration();

		if ( $result['success'] ) {
			return new \WP_REST_Response( $result, 200 );
		} else {
			return new \WP_REST_Response( $result, 400 );
		}
	}

	/**
	 * Get migration status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_status( $request ) {
		$state = new \WCS_Sublium_Migrator\Migration\State();
		$status = $state->get_state();

		// Calculate progress percentages.
		$products_progress = 0;
		if ( isset( $status['products_migration'] ) && is_array( $status['products_migration'] ) && isset( $status['products_migration']['total_products'] ) && absint( $status['products_migration']['total_products'] ) > 0 ) {
			$products_progress = ( absint( $status['products_migration']['processed_products'] ?? 0 ) / absint( $status['products_migration']['total_products'] ) ) * 100;
		}

		$subscriptions_progress = 0;
		if ( isset( $status['subscriptions_migration'] ) && is_array( $status['subscriptions_migration'] ) && isset( $status['subscriptions_migration']['total_subscriptions'] ) && absint( $status['subscriptions_migration']['total_subscriptions'] ) > 0 ) {
			$subscriptions_progress = ( absint( $status['subscriptions_migration']['processed_subscriptions'] ?? 0 ) / absint( $status['subscriptions_migration']['total_subscriptions'] ) ) * 100;
		}

		$status['progress'] = array(
			'products'      => round( $products_progress, 2 ),
			'subscriptions' => round( $subscriptions_progress, 2 ),
		);

		return new \WP_REST_Response( $status, 200 );
	}

	/**
	 * Pause migration.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function pause_migration( $request ) {
		$scheduler = \WCS_Sublium_Migrator\Migration\Scheduler::get_instance();
		$scheduler->pause_migration();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Migration paused', 'wcs-sublium-migrator' ),
			),
			200
		);
	}

	/**
	 * Resume migration.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function resume_migration( $request ) {
		$scheduler = \WCS_Sublium_Migrator\Migration\Scheduler::get_instance();
		$scheduler->resume_migration();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Migration resumed', 'wcs-sublium-migrator' ),
			),
			200
		);
	}

	/**
	 * Cancel migration.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function cancel_migration( $request ) {
		$scheduler = \WCS_Sublium_Migrator\Migration\Scheduler::get_instance();
		$scheduler->cancel_migration();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Migration cancelled', 'wcs-sublium-migrator' ),
			),
			200
		);
	}
}
