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
		// Only check if total_products > 0 (migration was actually started before).
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

		// Ensure status is persisted before scheduling batch (clear any caches).
		wp_cache_delete( \WCS_Sublium_Migrator\Migration\State::STATE_OPTION_NAME, 'options' );

		// Schedule first batch to run asynchronously (not synchronously to avoid race condition with frontend status check).
		$offset = absint( $current_state['products_migration']['processed_products'] ?? 0 );

		// Schedule first batch instead of processing immediately to ensure status is set before processing starts.
		$this->schedule_products_batch( $offset );

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

		// Ensure status is set.
		if ( ! isset( $current_state['status'] ) ) {
			$current_state['status'] = 'idle';
		}

		// Check if subscriptions migration already in progress.
		if ( 'subscriptions_migrating' === $current_state['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'Subscriptions migration is already in progress', 'wcs-sublium-migrator' ),
			);
		}

		// Check if subscriptions migration already completed.
		// Only check if total_subscriptions > 0 (migration was actually started before).
		if ( isset( $current_state['subscriptions_migration']['processed_subscriptions'] ) &&
			 isset( $current_state['subscriptions_migration']['total_subscriptions'] ) &&
			 absint( $current_state['subscriptions_migration']['total_subscriptions'] ) > 0 &&
			 absint( $current_state['subscriptions_migration']['processed_subscriptions'] ) >= absint( $current_state['subscriptions_migration']['total_subscriptions'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Subscriptions migration is already completed', 'wcs-sublium-migrator' ),
			);
		}

		// Note: Products migration check removed - subscriptions migration can run independently.

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

			// Count only unmigrated subscriptions (exclude those with _sublium_wcs_subscription_id meta).
			// This ensures total_subscriptions matches what get_subscriptions_batch() will actually process.
			$unmigrated_count = 0;
			if ( function_exists( 'wcs_get_subscriptions' ) ) {
				$unmigrated_subscriptions = wcs_get_subscriptions(
					array(
						'status'     => 'any',
						'limit'      => -1,
						'return'     => 'ids',
						'meta_query' => array(
							'relation' => 'OR',
							array(
								'key'     => '_sublium_wcs_subscription_id',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => '_sublium_wcs_subscription_id',
								'value'   => '',
								'compare' => '=',
							),
						),
					)
				);
				$unmigrated_count = is_array( $unmigrated_subscriptions ) ? count( $unmigrated_subscriptions ) : 0;
			}

			// Initialize state with counts (only unmigrated subscriptions).
			$current_state['subscriptions_migration']['total_subscriptions'] = $unmigrated_count;
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
		// Get total products BEFORE processing to check bounds.
		$state = new State();
		$current_state = $state->get_state();
		$all_product_ids = isset( $current_state['products_migration']['all_product_ids'] ) && is_array( $current_state['products_migration']['all_product_ids'] )
			? $current_state['products_migration']['all_product_ids']
			: array();
		$total_products_from_cache = count( $all_product_ids );
		$total_products = absint( $current_state['products_migration']['total_products'] ?? 0 );
		$actual_total = max( $total_products, $total_products_from_cache );

		// If offset already exceeds total, don't process - migration is complete.
		if ( $actual_total > 0 && $offset >= $actual_total ) {
			$state->set_status( 'idle' );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'WCS Migrator: Skipping batch at offset=%d (exceeds total=%d), setting status to idle', $offset, $actual_total ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}

		$processor = new Products_Processor();
		$result = $processor->process_batch( $offset );

		// Get updated state after processing.
		$current_state = $state->get_state();
		$processed_products = absint( $current_state['products_migration']['processed_products'] ?? 0 );

		if ( $result['has_more'] ) {
			// Schedule next batch.
			$this->schedule_products_batch( $result['next_offset'] );
			// Ensure status remains 'products_migrating' while processing.
			$state->set_status( 'products_migrating' );
		} else {
			// Batch returned no more items - check if migration is complete.
			if ( $actual_total > 0 && $processed_products >= $actual_total ) {
				// Processed count equals or exceeds total - migration complete.
				$state->set_status( 'idle' );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'WCS Migrator: Products migration complete - processed=%d, total=%d', $processed_products, $actual_total ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			} elseif ( $actual_total > 0 && $processed_products < $actual_total ) {
				// Not complete yet - check if next offset would exceed total.
				$reflection = new \ReflectionClass( $processor );
				$batch_size_property = $reflection->getProperty( 'batch_size' );
				$batch_size_property->setAccessible( true );
				$batch_size = $batch_size_property->getValue( $processor );
				$next_offset = $offset + $batch_size;

				if ( $next_offset < $actual_total ) {
					// Schedule next batch if we haven't exceeded total.
					$state->set_status( 'products_migrating' );
					$this->schedule_products_batch( $next_offset );
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( sprintf( 'WCS Migrator: Scheduling next products batch at offset=%d (processed=%d, total=%d)', $next_offset, $processed_products, $actual_total ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				} else {
					// Next offset would exceed total - migration complete.
					$state->set_status( 'idle' );
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( sprintf( 'WCS Migrator: Next offset=%d would exceed total=%d, setting status to idle', $next_offset, $actual_total ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}
			} else {
				// No products to process or already complete.
				$state->set_status( 'idle' );
			}
		}
	}

	/**
	 * Process subscriptions batch.
	 *
	 * @param int $offset Offset.
	 * @return void
	 */
	public function process_subscriptions_batch( $offset = 0 ) {
		$state = new State();
		$current_state = $state->get_state();

		$processor = new Subscriptions_Processor();
		$result = $processor->process_batch( $offset );

		// Get updated state after processing.
		$current_state = $state->get_state();
		$processed = absint( $current_state['subscriptions_migration']['processed_subscriptions'] ?? 0 );
		$total = absint( $current_state['subscriptions_migration']['total_subscriptions'] ?? 0 );

		if ( $result['has_more'] ) {
			// Schedule next batch.
			$this->schedule_subscriptions_batch( $result['next_offset'] );
			// Ensure status remains 'subscriptions_migrating' while processing.
			$state->set_status( 'subscriptions_migrating' );
		} else {
			// No more items in this batch - check if migration is actually complete.
			if ( $total > 0 && $processed >= $total ) {
				// All subscriptions processed - migration complete.
				$state->set_status( 'completed' );
				$current_state = $state->get_state();
				$current_state['end_time'] = current_time( 'mysql' );
				$state->update_state( $current_state );

				// Trigger post-migration hook.
				do_action( 'wcs_sublium_migration_completed', $current_state );
			} elseif ( $total > 0 && $processed < $total ) {
				// Not complete yet - try to schedule next batch if offset hasn't exceeded total.
				$reflection = new \ReflectionClass( $processor );
				$batch_size_property = $reflection->getProperty( 'batch_size' );
				$batch_size_property->setAccessible( true );
				$batch_size = $batch_size_property->getValue( $processor );
				$next_offset = $offset + $batch_size;

				if ( $next_offset < $total ) {
					// Schedule next batch if we haven't exceeded total.
					$state->set_status( 'subscriptions_migrating' );
					$this->schedule_subscriptions_batch( $next_offset );
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( sprintf( 'WCS Migrator: Scheduling next subscriptions batch at offset=%d (processed=%d, total=%d)', $next_offset, $processed, $total ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				} else {
					// Next offset would exceed total - migration complete.
					$state->set_status( 'completed' );
					$current_state = $state->get_state();
					$current_state['end_time'] = current_time( 'mysql' );
					$state->update_state( $current_state );

					// Trigger post-migration hook.
					do_action( 'wcs_sublium_migration_completed', $current_state );
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( sprintf( 'WCS Migrator: Subscriptions migration complete - processed=%d, total=%d', $processed, $total ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}
			} else {
				// No subscriptions to process or already complete.
				$state->set_status( 'completed' );
				$current_state = $state->get_state();
				$current_state['end_time'] = current_time( 'mysql' );
				$state->update_state( $current_state );

				// Trigger post-migration hook.
				do_action( 'wcs_sublium_migration_completed', $current_state );
			}
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

		// Ensure status is set.
		if ( ! isset( $current_state['status'] ) ) {
			$current_state['status'] = 'idle';
		}

		if ( 'paused' !== $current_state['status'] ) {
			return;
		}

		// Determine which stage to resume.
		$products_progress = isset( $current_state['products_migration'] ) ? $current_state['products_migration'] : array();
		$subscriptions_progress = isset( $current_state['subscriptions_migration'] ) ? $current_state['subscriptions_migration'] : array();

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

		// Reset state (but don't clear scheduled hooks again - already done above).
		$state = new State();
		// Just delete the option, don't call reset_state() to avoid double clearing hooks.
		delete_option( 'wcs_sublium_migration_state' );
		wp_cache_delete( 'wcs_sublium_migration_state', 'options' );
	}
}
