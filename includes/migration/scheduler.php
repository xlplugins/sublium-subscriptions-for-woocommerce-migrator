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
	 * Start products migration.
	 *
	 * @return array Result.
	 */
	public function start_products_migration() {
		$state = new State();
		$current_state = $state->get_state();


		// Check if products migration already in progress.
		if ( 'products_migrating' === $current_state['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'Products migration is already in progress', 'wcs-sublium-migrator' ),
			);
		}

		// Check if products migration already completed.
		$is_completed = false;
		if ( isset( $current_state['products_migration']['processed_products'] ) &&
			 isset( $current_state['products_migration']['total_products'] ) &&
			 absint( $current_state['products_migration']['total_products'] ) > 0 ) {
			$is_completed = absint( $current_state['products_migration']['processed_products'] ) >= absint( $current_state['products_migration']['total_products'] );
		}

		// If completed but no plans were created, allow restart (something went wrong).
		if ( $is_completed && isset( $current_state['products_migration']['created_plans'] ) && absint( $current_state['products_migration']['created_plans'] ) === 0 ) {
			// Reset to allow restart.
			$current_state['products_migration']['processed_products'] = 0;
			$current_state['products_migration']['created_plans'] = 0;
			$current_state['products_migration']['failed_products'] = 0;
			$current_state['errors'] = array();
			$is_completed = false;
		}

		if ( $is_completed ) {
			return array(
				'success' => false,
				'message' => __( 'Products migration is already completed', 'wcs-sublium-migrator' ),
			);
		}

		// Run discovery if not already done or if restarting.
		if ( empty( $current_state['products_migration']['total_products'] ) || absint( $current_state['products_migration']['processed_products'] ) === 0 ) {
			$discovery = new Discovery();
			$feasibility = $discovery->get_feasibility_data();

			// Check readiness.
			if ( 'blocked' === $feasibility['readiness']['status'] ) {
				return array(
					'success' => false,
					'message' => $feasibility['readiness']['message'],
				);
			}

			// Initialize state with counts.
			$current_state['products_migration']['total_products'] = $feasibility['products']['total'];
			$current_state['subscriptions_migration']['total_subscriptions'] = $feasibility['active_subscriptions'];
			if ( empty( $current_state['start_time'] ) ) {
				$current_state['start_time'] = current_time( 'mysql' );
			}
		}

		// Reset products migration progress if starting fresh.
		if ( empty( $current_state['products_migration']['processed_products'] ) || absint( $current_state['products_migration']['processed_products'] ) === 0 ) {
			$current_state['products_migration']['processed_products'] = 0;
			$current_state['products_migration']['created_plans'] = 0;
			$current_state['products_migration']['failed_products'] = 0;
			$current_state['errors'] = array();
		}

		$state->update_state( $current_state );
		$state->set_status( 'products_migrating' );

		// Process first batch immediately (synchronously) for testing, then schedule subsequent batches.
		$offset = absint( $current_state['products_migration']['processed_products'] ?? 0 );

		// Process first batch immediately.
		$this->process_products_batch( $offset );

		return array(
			'success' => true,
			'message' => __( 'Products migration started successfully', 'wcs-sublium-migrator' ),
		);
	}

	/**
	 * Start subscriptions migration.
	 *
	 * @return array Result.
	 */
	public function start_subscriptions_migration() {
		$state = new State();
		$current_state = $state->get_state();

		// Check if subscriptions migration already in progress.
		if ( 'subscriptions_migrating' === $current_state['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'Subscriptions migration is already in progress', 'wcs-sublium-migrator' ),
			);
		}

		// Check if subscriptions migration already completed.
		if ( isset( $current_state['subscriptions_migration']['processed_subscriptions'] ) &&
			 isset( $current_state['subscriptions_migration']['total_subscriptions'] ) &&
			 absint( $current_state['subscriptions_migration']['processed_subscriptions'] ) >= absint( $current_state['subscriptions_migration']['total_subscriptions'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Subscriptions migration is already completed', 'wcs-sublium-migrator' ),
			);
		}

		// Check if products migration is completed.
		$products_completed = false;
		if ( isset( $current_state['products_migration']['processed_products'] ) &&
			 isset( $current_state['products_migration']['total_products'] ) ) {
			$products_completed = absint( $current_state['products_migration']['processed_products'] ) >= absint( $current_state['products_migration']['total_products'] );
		}

		if ( ! $products_completed && absint( $current_state['products_migration']['total_products'] ?? 0 ) > 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Please complete products migration first', 'wcs-sublium-migrator' ),
			);
		}

		// Run discovery if not already done.
		if ( empty( $current_state['subscriptions_migration']['total_subscriptions'] ) ) {
			$discovery = new Discovery();
			$feasibility = $discovery->get_feasibility_data();

			// Check readiness.
			if ( 'blocked' === $feasibility['readiness']['status'] ) {
				return array(
					'success' => false,
					'message' => $feasibility['readiness']['message'],
				);
			}

			// Initialize state with counts.
			$current_state['subscriptions_migration']['total_subscriptions'] = $feasibility['active_subscriptions'];
			if ( empty( $current_state['start_time'] ) ) {
				$current_state['start_time'] = current_time( 'mysql' );
			}
		}

		// Reset subscriptions migration progress if starting fresh.
		if ( empty( $current_state['subscriptions_migration']['processed_subscriptions'] ) ) {
			$current_state['subscriptions_migration']['processed_subscriptions'] = 0;
			$current_state['subscriptions_migration']['created_subscriptions'] = 0;
			$current_state['subscriptions_migration']['failed_subscriptions'] = 0;
		}

		$state->update_state( $current_state );
		$state->set_status( 'subscriptions_migrating' );

		// Schedule first subscriptions batch.
		$offset = absint( $current_state['subscriptions_migration']['processed_subscriptions'] ?? 0 );
		$this->schedule_subscriptions_batch( $offset );

		return array(
			'success' => true,
			'message' => __( 'Subscriptions migration started successfully', 'wcs-sublium-migrator' ),
		);
	}

	/**
	 * Schedule products migration batch.
	 *
	 * @param int $offset Offset.
	 * @return void
	 */
	public function schedule_products_batch( $offset = 0 ) {
		// Check if already scheduled.
		$scheduled = wp_next_scheduled( 'wcs_sublium_migrate_products_batch', array( $offset ) );
		if ( $scheduled ) {
			return;
		}

		$result = wp_schedule_single_event( time(), 'wcs_sublium_migrate_products_batch', array( $offset ) );

		// Trigger cron immediately if possible (for testing).
		$disable_cron = defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant
		if ( ! $disable_cron ) {
			spawn_cron();
		}
	}

	/**
	 * Schedule subscriptions migration batch.
	 *
	 * @param int $offset Offset.
	 * @return void
	 */
	public function schedule_subscriptions_batch( $offset = 0 ) {
		// Check if already scheduled.
		$scheduled = wp_next_scheduled( 'wcs_sublium_migrate_subscriptions_batch', array( $offset ) );
		if ( $scheduled ) {
			return;
		}

		$result = wp_schedule_single_event( time(), 'wcs_sublium_migrate_subscriptions_batch', array( $offset ) );

		// Trigger cron immediately if possible (for testing).
		$disable_cron = defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant
		if ( ! $disable_cron ) {
			spawn_cron();
		}
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
			// Products migration complete.
			$state = new State();
			$state->set_status( 'idle' );
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

			// Trigger post-migration hook.
			do_action( 'wcs_sublium_migration_completed', $current_state );
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
