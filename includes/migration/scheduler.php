<?php
/**
 * Migration Scheduler
 *
 * @package WCS_Sublium_Migrator\Migration
 */

namespace WCS_Sublium_Migrator\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Scheduler
 *
 * Manages scheduled migration events.
 */
class Scheduler {

	/**
	 * Instance.
	 *
	 * @var Scheduler
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Scheduler
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
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'wcs_sublium_migrate_products_batch', array( $this, 'process_products_batch' ) );
		add_action( 'wcs_sublium_migrate_subscriptions_batch', array( $this, 'process_subscriptions_batch' ) );
	}

	/**
	 * Start migration.
	 *
	 * @return array Result.
	 */
	public function start_migration() {
		$state = new State();
		$current_state = $state->get_state();

		// Check if migration already in progress.
		if ( in_array( $current_state['status'], array( 'products_migrating', 'subscriptions_migrating' ), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'Migration is already in progress', 'wcs-sublium-migrator' ),
			);
		}

		// Reset state.
		$state->reset_state();
		$state->set_status( 'discovering' );

		// Run discovery.
		$discovery = new Discovery();
		$feasibility = $discovery->get_feasibility_data();

		// Check readiness.
		if ( 'blocked' === $feasibility['readiness']['status'] ) {
			$state->set_status( 'error' );
			return array(
				'success' => false,
				'message' => $feasibility['readiness']['message'],
			);
		}

		// Initialize state with counts.
		$new_state = $state->get_state();
		$new_state['products_migration']['total_products'] = $feasibility['products']['total'];
		$new_state['subscriptions_migration']['total_subscriptions'] = $feasibility['active_subscriptions'];
		$new_state['start_time'] = current_time( 'mysql' );
		$state->update_state( $new_state );

		// Schedule first products batch.
		$this->schedule_products_batch( 0 );

		return array(
			'success' => true,
			'message' => __( 'Migration started successfully', 'wcs-sublium-migrator' ),
		);
	}

	/**
	 * Schedule products migration batch.
	 *
	 * @param int $offset Offset.
	 * @return void
	 */
	public function schedule_products_batch( $offset = 0 ) {
		wp_schedule_single_event( time(), 'wcs_sublium_migrate_products_batch', array( $offset ) );
	}

	/**
	 * Schedule subscriptions migration batch.
	 *
	 * @param int $offset Offset.
	 * @return void
	 */
	public function schedule_subscriptions_batch( $offset = 0 ) {
		wp_schedule_single_event( time(), 'wcs_sublium_migrate_subscriptions_batch', array( $offset ) );
	}

	/**
	 * Process products batch.
	 *
	 * @param int $offset Offset.
	 * @return void
	 */
	public function process_products_batch( $offset = 0 ) {
		$processor = new Products_Processor();
		$result = $processor->process_batch( $offset );

		if ( $result['has_more'] ) {
			// Schedule next batch.
			$this->schedule_products_batch( $result['next_offset'] );
		} else {
			// Products migration complete, start subscriptions.
			$state = new State();
			$state->set_status( 'subscriptions_migrating' );
			$this->schedule_subscriptions_batch( 0 );
		}
	}

	/**
	 * Process subscriptions batch.
	 *
	 * @param int $offset Offset.
	 * @return void
	 */
	public function process_subscriptions_batch( $offset = 0 ) {
		$processor = new Subscriptions_Processor();
		$result = $processor->process_batch( $offset );

		if ( $result['has_more'] ) {
			// Schedule next batch.
			$this->schedule_subscriptions_batch( $result['next_offset'] );
		} else {
			// Migration complete.
			$state = new State();
			$state->set_status( 'completed' );
			$current_state = $state->get_state();
			$current_state['end_time'] = current_time( 'mysql' );
			$state->update_state( $current_state );
		}
	}

	/**
	 * Pause migration.
	 *
	 * @return void
	 */
	public function pause_migration() {
		// Clear scheduled events.
		wp_clear_scheduled_hook( 'wcs_sublium_migrate_products_batch' );
		wp_clear_scheduled_hook( 'wcs_sublium_migrate_subscriptions_batch' );

		$state = new State();
		$state->set_status( 'paused' );
	}

	/**
	 * Resume migration.
	 *
	 * @return void
	 */
	public function resume_migration() {
		$state = new State();
		$current_state = $state->get_state();

		if ( 'paused' !== $current_state['status'] ) {
			return;
		}

		// Determine which stage to resume.
		$products_progress = $current_state['products_migration'];
		$subscriptions_progress = $current_state['subscriptions_migration'];

		if ( $products_progress['processed_products'] < $products_progress['total_products'] ) {
			// Resume products migration.
			$state->set_status( 'products_migrating' );
			$this->schedule_products_batch( $products_progress['processed_products'] );
		} elseif ( $subscriptions_progress['processed_subscriptions'] < $subscriptions_progress['total_subscriptions'] ) {
			// Resume subscriptions migration.
			$state->set_status( 'subscriptions_migrating' );
			$this->schedule_subscriptions_batch( $subscriptions_progress['processed_subscriptions'] );
		}
	}

	/**
	 * Cancel migration.
	 *
	 * @return void
	 */
	public function cancel_migration() {
		// Clear scheduled events.
		wp_clear_scheduled_hook( 'wcs_sublium_migrate_products_batch' );
		wp_clear_scheduled_hook( 'wcs_sublium_migrate_subscriptions_batch' );

		$state = new State();
		$state->reset_state();
	}
}
