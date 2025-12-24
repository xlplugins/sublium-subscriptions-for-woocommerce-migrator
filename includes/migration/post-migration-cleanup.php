<?php
/**
 * Post-Migration Cleanup
 *
 * @package WCS_Sublium_Migrator\Migration
 */

namespace WCS_Sublium_Migrator\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Post_Migration_Cleanup
 *
 * Handles post-migration tasks to disable WCS renewals.
 */
class Post_Migration_Cleanup {

	/**
	 * Instance.
	 *
	 * @var Post_Migration_Cleanup
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Post_Migration_Cleanup
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
		// Constructor intentionally empty.
	}

	/**
	 * Disable WCS renewals for migrated subscriptions.
	 *
	 * @return array Result array with counts and status.
	 */
	public function disable_wcs_renewals() {
		$result = array(
			'success'                    => true,
			'actions_cancelled'          => 0,
			'subscriptions_set_manual'   => 0,
			'errors'                    => array(),
		);

		// Cancel scheduled renewal actions.
		$result['actions_cancelled'] = $this->cancel_scheduled_renewal_actions();

		// Set subscriptions to manual renewal.
		$manual_result = $this->set_subscriptions_to_manual_renewal();
		$result['subscriptions_set_manual'] = $manual_result['count'];
		if ( ! empty( $manual_result['errors'] ) ) {
			$result['errors'] = array_merge( $result['errors'], $manual_result['errors'] );
		}

		// Update migration state.
		$state = new State();
		$current_state = $state->get_state();
		$current_state['post_migration_cleanup'] = array(
			'completed'                 => true,
			'completed_at'              => current_time( 'mysql' ),
			'actions_cancelled'         => $result['actions_cancelled'],
			'subscriptions_set_manual' => $result['subscriptions_set_manual'],
		);
		$state->update_state( $current_state );

		return $result;
	}

	/**
	 * Cancel all WCS scheduled renewal actions for migrated subscriptions.
	 *
	 * @return int Number of actions cancelled.
	 */
	private function cancel_scheduled_renewal_actions() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return 0;
		}

		// Get all migrated WCS subscription IDs from Sublium meta.
		$migrated_subscription_ids = $this->get_migrated_wcs_subscription_ids();

		if ( empty( $migrated_subscription_ids ) ) {
			return 0;
		}

		$actions_cancelled = 0;
		$hooks = array(
			'woocommerce_scheduled_subscription_payment',
			'woocommerce_scheduled_subscription_expiration',
			'woocommerce_scheduled_subscription_trial_end',
			'woocommerce_scheduled_subscription_end_of_prepaid_term',
		);

		foreach ( $migrated_subscription_ids as $subscription_id ) {
			foreach ( $hooks as $hook ) {
				$cancelled = as_unschedule_all_actions( $hook, array( 'subscription_id' => absint( $subscription_id ) ) );
				$actions_cancelled += $cancelled;
			}
		}

		return $actions_cancelled;
	}

	/**
	 * Set WCS subscriptions to manual renewal.
	 *
	 * @return array Result with count and errors.
	 */
	private function set_subscriptions_to_manual_renewal() {
		$result = array(
			'count'  => 0,
			'errors' => array(),
		);

		// Get all migrated WCS subscription IDs.
		$migrated_subscription_ids = $this->get_migrated_wcs_subscription_ids();

		if ( empty( $migrated_subscription_ids ) ) {
			return $result;
		}

		foreach ( $migrated_subscription_ids as $subscription_id ) {
			try {
				if ( ! function_exists( 'wcs_get_subscription' ) ) {
					continue;
				}

				$wcs_subscription = wcs_get_subscription( absint( $subscription_id ) );
				if ( ! $wcs_subscription || ! is_a( $wcs_subscription, 'WC_Subscription' ) ) {
					continue;
				}

				// Set to manual renewal.
				$wcs_subscription->update_meta_data( '_requires_manual_renewal', 'true' );
				$wcs_subscription->save();

				++$result['count'];
			} catch ( \Exception $e ) {
				$result['errors'][] = sprintf(
					/* translators: %1$d: Subscription ID, %2$s: Error message */
					__( 'Failed to set subscription #%1$d to manual renewal: %2$s', 'wcs-sublium-migrator' ),
					$subscription_id,
					$e->getMessage()
				);
			}
		}

		return $result;
	}

	/**
	 * Get all migrated WCS subscription IDs from Sublium meta.
	 *
	 * @return array Array of WCS subscription IDs.
	 */
	private function get_migrated_wcs_subscription_ids() {
		global $wpdb;

		// Query Sublium subscription meta for wcs_subscription_id.
		$meta_table = $wpdb->prefix . 'sublium_wcs_subscription_meta';
		$subscriptions_table = $wpdb->prefix . 'sublium_wcs_subscriptions';

		// Check if tables exist.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) !== $meta_table ) {
			return array();
		}

		// Get all wcs_subscription_id values from meta.
		$query = $wpdb->prepare(
			"SELECT DISTINCT meta_value
			FROM {$meta_table}
			WHERE meta_key = %s
			AND meta_value != ''
			AND meta_value IS NOT NULL",
			'wcs_subscription_id'
		);

		$subscription_ids = $wpdb->get_col( $query );

		return array_map( 'absint', array_filter( $subscription_ids ) );
	}
}


