<?php
/**
 * Subscriptions Migration Processor
 *
 * @package WCS_Sublium_Migrator\Migration
 */

// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- File name follows project convention.

namespace WCS_Sublium_Migrator\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Subscriptions_Processor
 *
 * Processes subscriptions migration in batches.
 */
class Subscriptions_Processor {

	/**
	 * Batch size.
	 *
	 * @var int
	 */
	private $batch_size = 50;

	/**
	 * Process a batch of subscriptions.
	 *
	 * @param int $offset Offset.
	 * @return array Result.
	 */
	public function process_batch( $offset = 0 ) {
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscriptions_processor_process_batch_start' => array( 'offset' => $offset, 'batch_size' => $this->batch_size, 'time' => current_time( 'mysql' ) ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		$state         = new State();
		$current_state = $state->get_state();

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscriptions_processor_current_state' => $current_state ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		// Check if paused.
		if ( 'paused' === $current_state['status'] ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscriptions_processor_paused' => true ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return array(
				'success'  => false,
				'has_more' => false,
				'message'  => __( 'Migration is paused', 'wcs-sublium-migrator' ),
			);
		}

		// Get subscriptions batch.
		$subscriptions = $this->get_subscriptions_batch( $offset, $this->batch_size );
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscriptions_processor_fetched_subscriptions' => array( 'count' => count( $subscriptions ), 'subscription_ids' => array_map( function( $sub ) { return is_a( $sub, 'WC_Subscription' ) ? $sub->get_id() : 'not_wc_subscription'; }, $subscriptions ) ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		$processed     = 0;
		$created       = 0;
		$failed        = 0;

		foreach ( $subscriptions as $wcs_subscription ) {
			try {
				$subscription_id = is_a( $wcs_subscription, 'WC_Subscription' ) ? $wcs_subscription->get_id() : 0;
				file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscriptions_processor_migrating' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

				$result = $this->migrate_subscription( $wcs_subscription );
				if ( $result ) {
					++$created;
					file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscriptions_processor_migrated_success' => array( 'subscription_id' => $subscription_id, 'sublium_id' => $result ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
				} else {
					++$failed;
					file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscriptions_processor_migrated_failed' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
					$state->add_error(
						sprintf( 'Failed to migrate subscription %d', $subscription_id ),
						array( 'subscription_id' => $subscription_id )
					);
				}
				++$processed;
			} catch ( \Exception $e ) {
				++$failed;
				$subscription_id = is_a( $wcs_subscription, 'WC_Subscription' ) ? $wcs_subscription->get_id() : 0;
				file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscriptions_processor_migrated_exception' => array( 'subscription_id' => $subscription_id, 'error' => $e->getMessage() ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
				$state->add_error(
					sprintf( 'Error migrating subscription %d: %s', $subscription_id, $e->getMessage() ),
					array( 'subscription_id' => $subscription_id )
				);
			}
		}

		// Update progress.
		$current_state = $state->get_state();
		$new_processed = $current_state['subscriptions_migration']['processed_subscriptions'] + $processed;
		$new_created   = $current_state['subscriptions_migration']['created_subscriptions'] + $created;
		$new_failed    = $current_state['subscriptions_migration']['failed_subscriptions'] + $failed;
		$last_sub_id   = 0;
		if ( ! empty( $subscriptions ) ) {
			$last_sub = end( $subscriptions );
			if ( is_a( $last_sub, 'WC_Subscription' ) ) {
				$last_sub_id = $last_sub->get_id();
			}
		}

		$state->update_subscriptions_progress(
			array(
				'processed_subscriptions' => $new_processed,
				'created_subscriptions'   => $new_created,
				'failed_subscriptions'    => $new_failed,
				'last_subscription_id'    => $last_sub_id,
				'current_batch'           => floor( $new_processed / $this->batch_size ),
			)
		);

		// Check if more subscriptions exist.
		$has_more    = count( $subscriptions ) === $this->batch_size;
		$next_offset = $has_more ? $offset + $this->batch_size : 0;

		return array(
			'success'     => true,
			'has_more'    => $has_more,
			'next_offset' => $next_offset,
			'processed'   => $processed,
			'created'     => $created,
			'failed'      => $failed,
		);
	}

	/**
	 * Get subscriptions batch using native WCS function.
	 *
	 * @param int $offset Offset.
	 * @param int $limit Limit.
	 * @return array Array of WC_Subscription objects.
	 */
	private function get_subscriptions_batch( $offset, $limit ) {
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'get_subscriptions_batch_start' => array( 'offset' => $offset, 'limit' => $limit, 'wcs_function_exists' => function_exists( 'wcs_get_subscriptions' ) ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'wcs_get_subscriptions_not_available' => true ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return array();
		}

		$subscriptions = wcs_get_subscriptions(
			array(
				'status'  => 'any',
				'limit'   => $limit,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'wcs_get_subscriptions_result' => array( 'count' => is_array( $subscriptions ) ? count( $subscriptions ) : 0, 'ids' => is_array( $subscriptions ) ? $subscriptions : 'not_array' ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( empty( $subscriptions ) || ! is_array( $subscriptions ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'no_subscriptions_found' => true ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return array();
		}

		// Convert IDs to subscription objects.
		$subscription_objects = array();
		foreach ( $subscriptions as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( $subscription && is_a( $subscription, 'WC_Subscription' ) ) {
				$subscription_objects[] = $subscription;
			} else {
				file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscription_not_found_or_invalid' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			}
		}

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscription_objects_count' => count( $subscription_objects ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		return $subscription_objects;
	}

	/**
	 * Migrate a single subscription.
	 *
	 * @param \WC_Subscription $wcs_subscription WooCommerce Subscription object.
	 * @return int|false Subscription ID or false.
	 */
	private function migrate_subscription( $wcs_subscription ) {
		$subscription_id = is_a( $wcs_subscription, 'WC_Subscription' ) ? $wcs_subscription->get_id() : 0;
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'migrate_subscription_start' => array( 'subscription_id' => $subscription_id, 'is_wc_subscription' => is_a( $wcs_subscription, 'WC_Subscription' ) ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( ! is_a( $wcs_subscription, 'WC_Subscription' ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'not_wc_subscription' => true ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return false;
		}

		if ( ! class_exists( '\Sublium_WCS\Includes\Controller\Subscriptions\Subscription' ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'sublium_subscription_class_not_found' => true ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return false;
		}

		// Check if subscription already migrated.
		$existing_sublium_id = $wcs_subscription->get_meta( '_sublium_wcs_subscription_id', true );
		if ( ! empty( $existing_sublium_id ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'subscription_already_migrated' => array( 'wcs_id' => $subscription_id, 'sublium_id' => $existing_sublium_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return absint( $existing_sublium_id );
		}

		// Extract subscription data from WCS subscription.
		$subscription_data = $this->extract_subscription_data( $wcs_subscription );
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'extract_subscription_data_result' => array( 'subscription_id' => $subscription_id, 'has_data' => ! empty( $subscription_data ), 'data_keys' => ! empty( $subscription_data ) ? array_keys( $subscription_data ) : array() ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( empty( $subscription_data ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'extract_subscription_data_empty' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return false;
		}

		// Create subscription in Sublium.
		$sublium_subscription = \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::create( $subscription_data );
		if ( ! $sublium_subscription || ! is_a( $sublium_subscription, '\Sublium_WCS\Includes\Controller\Subscriptions\Subscription' ) ) {
			return false;
		}

		$sublium_subscription_id = $sublium_subscription->get_id();
		if ( empty( $sublium_subscription_id ) ) {
			return false;
		}

		// Add subscription items.
		$this->add_subscription_items( $sublium_subscription, $wcs_subscription );

		// Link WCS subscription to Sublium subscription.
		$wcs_subscription->update_meta_data( '_sublium_wcs_subscription_id', $sublium_subscription_id );
		$wcs_subscription->save();

		// Create activity log.
		if ( class_exists( '\Sublium_WCS\Includes\Helpers\Subscription' ) ) {
			\Sublium_WCS\Includes\Helpers\Subscription::create_activity(
				$sublium_subscription_id,
				array(
					'object_type' => \Sublium_WCS\Includes\Helpers\Subscription::ACTIVITY_OBJECT_TYPE['SUBSCRIPTION'],
					'action'      => \Sublium_WCS\Includes\Helpers\Subscription::ACTIVITY_ACTION['CREATED'],
					'new_value'   => 0,
					'old_value'   => 0,
					'user_type'   => \Sublium_WCS\Includes\Helpers\Subscription::ACTIVITY_USER_TYPES['SYSTEM'],
				),
				/* translators: %d: WooCommerce Subscription ID */
				sprintf( __( 'Migrated from WooCommerce Subscriptions - Subscription #%d', 'wcs-sublium-migrator' ), $wcs_subscription->get_id() )
			);
		}

		return $sublium_subscription_id;
	}

	/**
	 * Extract subscription data from WCS subscription.
	 *
	 * @param \WC_Subscription $wcs_subscription WooCommerce Subscription object.
	 * @return array|false Subscription data array or false.
	 */
	private function extract_subscription_data( $wcs_subscription ) {
		$subscription_id = is_a( $wcs_subscription, 'WC_Subscription' ) ? $wcs_subscription->get_id() : 0;
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'extract_subscription_data_start' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( ! is_a( $wcs_subscription, 'WC_Subscription' ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'extract_not_wc_subscription' => true ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return false;
		}

		// Get parent order.
		$parent_order = $wcs_subscription->get_parent();
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'extract_parent_order_check' => array( 'subscription_id' => $subscription_id, 'has_parent' => ! empty( $parent_order ), 'parent_id' => $parent_order ? $parent_order->get_id() : 0 ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( ! $parent_order ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'extract_no_parent_order' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return false;
		}

		// Create plan_data from subscription data.
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'extract_creating_plan_data_start' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		$plan_data = $this->create_plan_data_from_subscription( $wcs_subscription, $parent_order );
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'extract_create_plan_data_result' => array( 'subscription_id' => $subscription_id, 'has_plan_data' => ! empty( $plan_data ), 'plan_data_keys' => ! empty( $plan_data ) && is_array( $plan_data ) ? array_keys( $plan_data ) : array() ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( empty( $plan_data ) || ! is_array( $plan_data ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'extract_plan_data_creation_failed' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return false;
		}

		$plan_type = isset( $plan_data['type'] ) ? absint( $plan_data['type'] ) : 2; // Default to Recurring.

		// Get dates.
		$next_payment_date = $wcs_subscription->get_date( 'next_payment' );
		$end_date          = $wcs_subscription->get_date( 'end' );
		$trial_end_date    = $wcs_subscription->get_date( 'trial_end' );

		// Convert dates to system/UTC format.
		if ( ! empty( $next_payment_date ) && '0' !== $next_payment_date ) {
			$next_payment_date_system = get_date_from_gmt( $next_payment_date, 'Y-m-d H:i:s' );
			$next_payment_date_utc    = $next_payment_date;
		} else {
			$next_payment_date_system = null;
			$next_payment_date_utc    = null;
		}

		if ( ! empty( $end_date ) && '0' !== $end_date ) {
			$end_date_system = get_date_from_gmt( $end_date, 'Y-m-d H:i:s' );
			$end_date_utc    = $end_date;
		} else {
			$end_date_system = null;
			$end_date_utc    = null;
		}

		// Get subscription status.
		$status = $this->convert_wcs_status_to_sublium( $wcs_subscription->get_status() );

		// Get billing period and interval.
		$billing_period   = $wcs_subscription->get_billing_period();
		$billing_interval = $wcs_subscription->get_billing_interval();

		// Get totals.
		$totals      = (float) $wcs_subscription->get_total();
		$base_totals = $totals; // Base totals same as totals for now.

		// Get currency.
		$currency = $wcs_subscription->get_currency();

		// Get user ID.
		$user_id = $wcs_subscription->get_user_id();

		// Get payment gateway.
		$gateway = $wcs_subscription->get_payment_method();

		// Build search string.
		$search_str = $this->build_search_string( $wcs_subscription );

		// Get item IDs (product IDs from subscription items).
		$item_ids = array();
		foreach ( $wcs_subscription->get_items() as $item ) {
			$product_id = $item->get_product_id();
			if ( $product_id ) {
				$item_ids[] = (string) $product_id;
			}
		}

		// Prepare meta data.
		$meta_data = array(
			'billing_frequency' => absint( $billing_interval ),
			'billing_interval'  => $this->convert_period_to_interval( $billing_period ),
			'billing_length'    => absint( $wcs_subscription->get_length() ),
			'trial_length'      => absint( $wcs_subscription->get_trial_length() ),
			'trial_period'      => $wcs_subscription->get_trial_period(),
			'signup_fee'        => (float) $wcs_subscription->get_sign_up_fee(),
			'plan_data'         => $plan_data, // Store plan_data in meta for recurring payments.
		);

		// Add billing and shipping details.
		$billing_details  = $this->extract_address_data( $wcs_subscription, 'billing' );
		$shipping_details = $this->extract_address_data( $wcs_subscription, 'shipping' );
		if ( ! empty( $billing_details ) ) {
			$meta_data['billing_details'] = $billing_details;
		}
		if ( ! empty( $shipping_details ) ) {
			$meta_data['shipping_details'] = $shipping_details;
		}

		// Add payment method details.
		$payment_method_title = $wcs_subscription->get_payment_method_title();
		if ( ! empty( $payment_method_title ) ) {
			$meta_data['payment_method_title'] = $payment_method_title;
		}

		// Add trial end date if exists.
		if ( ! empty( $trial_end_date ) && '0' !== $trial_end_date ) {
			$meta_data['trial_end_date']     = get_date_from_gmt( $trial_end_date, 'Y-m-d H:i:s' );
			$meta_data['trial_end_date_utc'] = $trial_end_date;
		}

		return array(
			'parent_order_id'       => $parent_order->get_id(),
			'gateway'               => $gateway ? $gateway : 'manual',
			'gateway_mode'          => 1,
			'user_id'               => absint( $user_id ),
			'status'                => $status,
			'plan_id'               => array( '0' ), // Set to 0 when using plan_data.
			'plan_type'             => $plan_type,
			'currency'              => $currency,
			'totals'                => $totals,
			'base_totals'           => $base_totals,
			'next_payment_date'     => $next_payment_date_system,
			'next_payment_date_utc' => $next_payment_date_utc,
			'last_payment_date'     => $wcs_subscription->get_date( 'last_order_date_created' ) ? get_date_from_gmt( $wcs_subscription->get_date( 'last_order_date_created' ), 'Y-m-d H:i:s' ) : null,
			'items'                 => $item_ids,
			'search_str'            => $search_str,
			'end_date'              => $end_date_system,
			'end_date_utc'          => $end_date_utc,
			'meta_data'             => $meta_data,
		);
	}

	/**
	 * Create plan_data from WCS subscription data (no actual plan creation).
	 *
	 * @param \WC_Subscription $wcs_subscription WooCommerce Subscription object.
	 * @param \WC_Order        $parent_order Parent order object.
	 * @return array|false Plan data array or false.
	 */
	private function create_plan_data_from_subscription( $wcs_subscription, $parent_order ) {
		$subscription_id = is_a( $wcs_subscription, 'WC_Subscription' ) ? $wcs_subscription->get_id() : 0;
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'create_plan_data_from_subscription_start' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		// Get product IDs from subscription items.
		$product_ids = array();
		foreach ( $wcs_subscription->get_items() as $item ) {
			$product_id = $item->get_product_id();
			if ( $product_id ) {
				$product_ids[] = absint( $product_id );
			}
		}

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'create_plan_data_product_ids' => array( 'subscription_id' => $subscription_id, 'product_ids' => $product_ids, 'count' => count( $product_ids ) ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( empty( $product_ids ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'create_plan_data_no_product_ids' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return false;
		}

		// Get billing information from subscription.
		$billing_period   = $wcs_subscription->get_billing_period();
		$billing_interval = absint( $wcs_subscription->get_billing_interval() );
		$billing_length   = absint( $wcs_subscription->get_length() );
		$trial_length     = absint( $wcs_subscription->get_trial_length() );
		$trial_period     = $wcs_subscription->get_trial_period();
		$signup_fee       = (float) $wcs_subscription->get_sign_up_fee();

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'create_plan_data_billing_info' => array( 'subscription_id' => $subscription_id, 'billing_period' => $billing_period, 'billing_interval' => $billing_interval, 'billing_length' => $billing_length, 'trial_length' => $trial_length, 'trial_period' => $trial_period, 'signup_fee' => $signup_fee ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		// Convert to Sublium format.
		$interval   = $this->convert_period_to_interval( $billing_period );
		$frequency  = $billing_interval;
		$trial_days = $this->convert_trial_to_days( $trial_length, $trial_period );

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'create_plan_data_converted' => array( 'subscription_id' => $subscription_id, 'interval' => $interval, 'frequency' => $frequency, 'trial_days' => $trial_days ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		// Determine plan type based on product (virtual = Recurring, physical = Subscribe & Save).
		$plan_type = 2; // Default to Recurring.
		$first_product = wc_get_product( $product_ids[0] );
		if ( $first_product && ! $first_product->is_virtual() ) {
			$plan_type = 1; // Subscribe & Save.
		}

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'create_plan_data_plan_type' => array( 'subscription_id' => $subscription_id, 'plan_type' => $plan_type, 'product_is_virtual' => $first_product ? $first_product->is_virtual() : 'no_product' ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		// Generate plan title from billing period.
		$plan_title = $this->generate_plan_title( $billing_period, $billing_interval );

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'create_plan_data_title' => array( 'subscription_id' => $subscription_id, 'plan_title' => $plan_title ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		// Prepare signup fee data.
		$signup_fee_data = array(
			'signup_fee_type' => 'fixed',
			'signup_amount'   => $signup_fee,
		);

		// Prepare offer data (default pricing).
		$offer_data = array(
			'price_type'    => 'default',
			'discount_type' => 'percentage',
			'discount_value' => '0',
		);

		// Prepare plan data JSON.
		$plan_data_json = array(
			'subscription_ends'              => $billing_length > 0 ? 'after_payments' : 'never',
			'subscription_ends_payment_count' => $billing_length,
			'recommended_text'               => '',
			'additional_description'         => __( 'Enjoy automatic renewals on your schedule. No commitment—modify or cancel anytime.', 'wcs-sublium-migrator' ),
			'display_summary'                => $this->generate_display_summary( $signup_fee, $trial_days ),
		);

		// Get product prices for relation data.
		$relation_data = array();
		foreach ( $wcs_subscription->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$product_id   = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$variation_id = $item->get_variation_id();

			$regular_price = (float) $product->get_regular_price();
			$sale_price    = (float) $product->get_sale_price();
			if ( empty( $sale_price ) ) {
				$sale_price = $regular_price;
			}

			$relation_data[] = array(
				'regular_price' => (string) $regular_price,
				'sale_price'    => (string) $sale_price,
			);
		}

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'create_plan_data_relation_data' => array( 'subscription_id' => $subscription_id, 'relation_data' => $relation_data ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		// Build plan_data structure matching Sublium's plan format.
		$plan_data = array(
			'id'                => 0, // Set to 0 for plan_data.
			'plan_group_id'     => 0, // Not needed for plan_data.
			'title'             => $plan_title,
			'type'              => $plan_type,
			'billing_frequency' => $frequency,
			'billing_interval'  => $interval,
			'billing_length'    => $billing_length,
			'signup_fee'        => wp_json_encode( $signup_fee_data ),
			'offer'             => wp_json_encode( $offer_data ),
			'free_trial'        => $trial_days,
			'data'              => wp_json_encode( $plan_data_json ),
			'status'            => 1, // Active.
			'object_type'       => '1', // Specific product.
			'relation_data'     => ! empty( $relation_data ) ? $relation_data[0] : array(), // Use first product's relation data.
			'subscription_id'    => $subscription_id, // Store original subscription ID for reference.
		);

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'create_plan_data_success' => array( 'subscription_id' => $subscription_id, 'plan_data' => $plan_data ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		return $plan_data;
	}

	/**
	 * Find matching Sublium plan for WCS subscription (deprecated - kept for reference).
	 *
	 * @param \WC_Subscription $wcs_subscription WooCommerce Subscription object.
	 * @return int|false Plan ID or false.
	 * @deprecated Use create_plan_from_subscription() instead.
	 */
	private function find_matching_plan( $wcs_subscription ) {
		$subscription_id = is_a( $wcs_subscription, 'WC_Subscription' ) ? $wcs_subscription->get_id() : 0;
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_matching_plan_start' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( ! class_exists( '\Sublium_WCS\Includes\database\PlanRelations' ) || ! class_exists( '\Sublium_WCS\Includes\database\Plan' ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_classes_not_found' => true ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return false;
		}

		// Get product IDs from subscription items.
		$product_ids   = array();
		$variation_ids = array();
		foreach ( $wcs_subscription->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			if ( $product_id ) {
				$product_ids[] = absint( $product_id );
			}
			if ( $variation_id ) {
				$variation_ids[] = absint( $variation_id );
			}
		}

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_product_ids' => array( 'subscription_id' => $subscription_id, 'product_ids' => $product_ids, 'variation_ids' => $variation_ids ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( empty( $product_ids ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_no_product_ids' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return false;
		}

		// Get billing period and interval.
		$billing_period   = $wcs_subscription->get_billing_period();
		$billing_interval = absint( $wcs_subscription->get_billing_interval() );
		$trial_length     = absint( $wcs_subscription->get_trial_length() );
		$trial_period     = $wcs_subscription->get_trial_period();

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_billing_info' => array( 'subscription_id' => $subscription_id, 'billing_period' => $billing_period, 'billing_interval' => $billing_interval, 'trial_length' => $trial_length, 'trial_period' => $trial_period ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		// Convert to Sublium format.
		$interval   = $this->convert_period_to_interval( $billing_period );
		$frequency  = $billing_interval;
		$trial_days = $this->convert_trial_to_days( $trial_length, $trial_period );

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_converted' => array( 'subscription_id' => $subscription_id, 'interval' => $interval, 'frequency' => $frequency, 'trial_days' => $trial_days ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		// Find plan relations for products/variations.
		$plan_relations_db = new \Sublium_WCS\Includes\database\PlanRelations();
		$plan_ids          = array();

		// Check product-level relations first.
		foreach ( $product_ids as $product_id ) {
			$relations = $plan_relations_db->read(
				array(
					'oid'    => absint( $product_id ),
					'vid'    => 0,
					'type'   => 1,
					'status' => 1,
				)
			);

			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_product_relations' => array( 'subscription_id' => $subscription_id, 'product_id' => $product_id, 'relations_count' => is_array( $relations ) ? count( $relations ) : 0, 'relations' => $relations ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

			if ( ! empty( $relations ) && is_array( $relations ) ) {
				foreach ( $relations as $relation ) {
					if ( isset( $relation['plan_id'] ) ) {
						$plan_ids[] = absint( $relation['plan_id'] );
					}
				}
			}
		}

		// Check variation-level relations if variations exist.
		if ( ! empty( $variation_ids ) ) {
			foreach ( $variation_ids as $variation_id ) {
				$product_id = wc_get_product( $variation_id ) ? wc_get_product( $variation_id )->get_parent_id() : 0;
				if ( $product_id ) {
					$relations = $plan_relations_db->read(
						array(
							'oid'    => absint( $product_id ),
							'vid'    => absint( $variation_id ),
							'type'   => 1,
							'status' => 1,
						)
					);

					file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_variation_relations' => array( 'subscription_id' => $subscription_id, 'product_id' => $product_id, 'variation_id' => $variation_id, 'relations_count' => is_array( $relations ) ? count( $relations ) : 0, 'relations' => $relations ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

					if ( ! empty( $relations ) && is_array( $relations ) ) {
						foreach ( $relations as $relation ) {
							if ( isset( $relation['plan_id'] ) ) {
								$plan_ids[] = absint( $relation['plan_id'] );
							}
						}
					}
				}
			}
		}

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_collected_plan_ids' => array( 'subscription_id' => $subscription_id, 'plan_ids' => array_unique( $plan_ids ) ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( empty( $plan_ids ) ) {
			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_no_plan_ids_found' => array( 'subscription_id' => $subscription_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return false;
		}

		// Find plan that matches billing settings.
		$plan_db = new \Sublium_WCS\Includes\database\Plan();
		foreach ( array_unique( $plan_ids ) as $plan_id ) {
			$plan_data = $plan_db->read( array( 'id' => $plan_id ) );
			if ( empty( $plan_data ) || ! is_array( $plan_data ) || ! isset( $plan_data[0] ) ) {
				continue;
			}

			$plan = $plan_data[0];

			file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_checking_plan' => array( 'subscription_id' => $subscription_id, 'plan_id' => $plan_id, 'plan_billing_frequency' => isset( $plan['billing_frequency'] ) ? $plan['billing_frequency'] : 'not_set', 'plan_billing_interval' => isset( $plan['billing_interval'] ) ? $plan['billing_interval'] : 'not_set', 'plan_free_trial' => isset( $plan['free_trial'] ) ? $plan['free_trial'] : 'not_set', 'expected_frequency' => $frequency, 'expected_interval' => $interval, 'expected_trial' => $trial_days ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

			// Check if billing settings match.
			if ( isset( $plan['billing_frequency'] ) && absint( $plan['billing_frequency'] ) === $frequency &&
				isset( $plan['billing_interval'] ) && absint( $plan['billing_interval'] ) === $interval &&
				isset( $plan['free_trial'] ) && absint( $plan['free_trial'] ) === $trial_days ) {
				file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_match_found' => array( 'subscription_id' => $subscription_id, 'plan_id' => $plan_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
				return absint( $plan_id );
			}
		}

		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'find_plan_no_match' => array( 'subscription_id' => $subscription_id, 'checked_plan_ids' => array_unique( $plan_ids ) ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		return false;
	}

	/**
	 * Add subscription items to Sublium subscription.
	 *
	 * @param \Sublium_WCS\Includes\Controller\Subscriptions\Subscription $sublium_subscription Sublium subscription object.
	 * @param \WC_Subscription                                            $wcs_subscription WooCommerce Subscription object.
	 * @return void
	 */
	private function add_subscription_items( $sublium_subscription, $wcs_subscription ) {
		if ( ! class_exists( '\Sublium_WCS\Includes\Helpers\Utility' ) ) {
			return;
		}

		foreach ( $wcs_subscription->get_items() as $wcs_item ) {
			$product = $wcs_item->get_product();
			if ( ! $product ) {
				continue;
			}

			$variation_id = $wcs_item->get_variation_id();
			$product_id   = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

			// Prepare item data.
			$item_data = array(
				'item_type'    => 1, // Product item.
				'product_id'   => absint( $product_id ),
				'variation_id' => $variation_id ? absint( $variation_id ) : 0,
				'quantity'     => absint( $wcs_item->get_quantity() ),
				'name'         => $wcs_item->get_name(),
				'subtotal'     => (float) $wcs_item->get_subtotal(),
				'total'        => (float) $wcs_item->get_total(),
				'subtotal_tax' => (float) $wcs_item->get_subtotal_tax(),
				'total_tax'    => (float) $wcs_item->get_total_tax(),
				'tax_class'    => $wcs_item->get_tax_class(),
			);

			// Use Utility to prepare item data if available.
			if ( method_exists( '\Sublium_WCS\Includes\Helpers\Utility', 'prepare_subscription_item' ) ) {
				$item_data = \Sublium_WCS\Includes\Helpers\Utility::prepare_subscription_item( $item_data, $sublium_subscription );
			}

			$sublium_subscription->add_item( $item_data );
		}
	}

	/**
	 * Convert WCS status to Sublium status.
	 *
	 * @param string $wcs_status WooCommerce Subscriptions status.
	 * @return int Sublium status code.
	 */
	private function convert_wcs_status_to_sublium( $wcs_status ) {
		if ( ! class_exists( '\Sublium_WCS\Includes\Controller\Subscriptions\Subscription' ) ) {
			return 1; // Default to PENDING.
		}

		$status_map = array(
			'pending'        => \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::STATUSES['PENDING'],
			'active'         => \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::STATUSES['ACTIVE'],
			'on-hold'        => \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::STATUSES['ONHOLD'],
			'cancelled'      => \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::STATUSES['CANCELLED'],
			'switched'       => \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::STATUSES['COMPLETED'],
			'expired'        => \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::STATUSES['COMPLETED'],
			'pending-cancel' => \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::STATUSES['PENDING_CANCEL'],
			'trial'          => \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::STATUSES['TRIALING'],
		);

		return isset( $status_map[ $wcs_status ] ) ? $status_map[ $wcs_status ] : \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::STATUSES['PENDING'];
	}

	/**
	 * Get plan type from plan ID.
	 *
	 * @param int $plan_id Plan ID.
	 * @return int Plan type (1=Subscribe & Save, 2=Recurring, 3=Installment).
	 */
	private function get_plan_type_from_plan( $plan_id ) {
		if ( ! class_exists( '\Sublium_WCS\Includes\database\Plan' ) ) {
			return 1; // Default to Subscribe & Save.
		}

		$plan_db   = new \Sublium_WCS\Includes\database\Plan();
		$plan_data = $plan_db->read( array( 'id' => absint( $plan_id ) ) );

		if ( empty( $plan_data ) || ! is_array( $plan_data ) || ! isset( $plan_data[0] ) ) {
			return 1;
		}

		$plan = $plan_data[0];
		return isset( $plan['type'] ) ? absint( $plan['type'] ) : 1;
	}

	/**
	 * Build search string for subscription.
	 *
	 * @param \WC_Subscription $wcs_subscription WooCommerce Subscription object.
	 * @return string Search string.
	 */
	private function build_search_string( $wcs_subscription ) {
		$search_parts = array();

		// Add customer name.
		$customer = $wcs_subscription->get_user();
		if ( $customer ) {
			$search_parts[] = $customer->display_name;
			$search_parts[] = $customer->user_email;
		}

		// Add order number.
		$parent_order = $wcs_subscription->get_parent();
		if ( $parent_order ) {
			$search_parts[] = '#' . $parent_order->get_order_number();
		}

		// Add subscription ID.
		$search_parts[] = '#' . $wcs_subscription->get_id();

		return implode( ' ', array_filter( $search_parts ) );
	}

	/**
	 * Extract address data from subscription.
	 *
	 * @param \WC_Subscription $wcs_subscription WooCommerce Subscription object.
	 * @param string           $type Address type (billing or shipping).
	 * @return array Address data.
	 */
	private function extract_address_data( $wcs_subscription, $type = 'billing' ) {
		$address = array();

		if ( 'billing' === $type ) {
			$address['first_name'] = $wcs_subscription->get_billing_first_name();
			$address['last_name']  = $wcs_subscription->get_billing_last_name();
			$address['company']    = $wcs_subscription->get_billing_company();
			$address['address_1']  = $wcs_subscription->get_billing_address_1();
			$address['address_2']  = $wcs_subscription->get_billing_address_2();
			$address['city']       = $wcs_subscription->get_billing_city();
			$address['state']      = $wcs_subscription->get_billing_state();
			$address['postcode']   = $wcs_subscription->get_billing_postcode();
			$address['country']    = $wcs_subscription->get_billing_country();
			$address['email']      = $wcs_subscription->get_billing_email();
			$address['phone']      = $wcs_subscription->get_billing_phone();
		} else {
			$address['first_name'] = $wcs_subscription->get_shipping_first_name();
			$address['last_name']  = $wcs_subscription->get_shipping_last_name();
			$address['company']    = $wcs_subscription->get_shipping_company();
			$address['address_1']  = $wcs_subscription->get_shipping_address_1();
			$address['address_2']  = $wcs_subscription->get_shipping_address_2();
			$address['city']       = $wcs_subscription->get_shipping_city();
			$address['state']      = $wcs_subscription->get_shipping_state();
			$address['postcode']   = $wcs_subscription->get_shipping_postcode();
			$address['country']    = $wcs_subscription->get_shipping_country();
		}

		return array_filter( $address );
	}

	/**
	 * Convert WCS period to Sublium interval.
	 *
	 * @param string $period Period (day, week, month, year).
	 * @return int Interval (1=day, 2=week, 3=month, 4=year).
	 */
	private function convert_period_to_interval( $period ) {
		$period = strtolower( $period );
		switch ( $period ) {
			case 'day':
				return 1;
			case 'week':
				return 2;
			case 'month':
				return 3;
			case 'year':
				return 4;
			default:
				return 3; // Default to month.
		}
	}

	/**
	 * Convert trial period to days.
	 *
	 * @param int    $trial_length Trial length.
	 * @param string $trial_period Trial period.
	 * @return int Number of days.
	 */
	private function convert_trial_to_days( $trial_length, $trial_period ) {
		if ( empty( $trial_length ) || $trial_length <= 0 ) {
			return 0;
		}

		$trial_period = strtolower( $trial_period );
		switch ( $trial_period ) {
			case 'day':
				return absint( $trial_length );
			case 'week':
				return absint( $trial_length * 7 );
			case 'month':
				return absint( $trial_length * 30 ); // Approximate.
			case 'year':
				return absint( $trial_length * 365 ); // Approximate.
			default:
				return 0;
		}
	}

	/**
	 * Create or get plan group for subscription.
	 *
	 * @param int $plan_type Plan type (1=Subscribe & Save, 2=Recurring, 3=Installment).
	 * @param int $product_id Product ID.
	 * @return int|false Plan group ID or false.
	 */
	private function create_or_get_plan_group( $plan_type, $product_id ) {
		if ( ! class_exists( '\Sublium_WCS\Includes\database\GroupPlans' ) ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		// Generate group title from product name.
		$group_title = sprintf( '%s - %s', $product->get_name(), __( 'Subscription Plans', 'wcs-sublium-migrator' ) );

		// Try to find existing plan group for this product.
		$group_plans_db = new \Sublium_WCS\Includes\database\GroupPlans();
		$existing_groups = $group_plans_db->read(
			array(
				'type' => absint( $plan_type ),
			)
		);

		// Check if we can reuse an existing group (simple approach - use first matching type).
		if ( ! empty( $existing_groups ) && is_array( $existing_groups ) ) {
			// For now, create a new group for each product to avoid conflicts.
			// In the future, we could implement smarter matching.
		}

		// Create new plan group.
		$group_data = array(
			'type'         => absint( $plan_type ),
			'title'        => $group_title,
			'product_type' => 1, // Specific products.
			'created_at'   => current_time( 'mysql' ),
			'created_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'   => current_time( 'mysql' ),
			'updated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			'created_by'   => get_current_user_id() ? get_current_user_id() : 1,
			'data'         => wp_json_encode( array( 'plan_order' => array() ) ),
		);

		$plan_group_id = $group_plans_db->create( $group_data );
		file_put_contents( __DIR__ . '/debug.log', print_r( array( 'create_plan_group' => array( 'plan_group_id' => $plan_group_id, 'plan_type' => $plan_type, 'product_id' => $product_id ) ), true ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		return $plan_group_id ? absint( $plan_group_id ) : false;
	}

	/**
	 * Generate plan title from billing period and interval.
	 *
	 * @param string $billing_period Billing period (day, week, month, year).
	 * @param int    $billing_interval Billing interval.
	 * @return string Plan title.
	 */
	private function generate_plan_title( $billing_period, $billing_interval ) {
		$period_labels = array(
			'day'   => __( 'Daily', 'wcs-sublium-migrator' ),
			'week'  => __( 'Weekly', 'wcs-sublium-migrator' ),
			'month' => __( 'Monthly', 'wcs-sublium-migrator' ),
			'year'  => __( 'Yearly', 'wcs-sublium-migrator' ),
		);

		$period_label = isset( $period_labels[ $billing_period ] ) ? $period_labels[ $billing_period ] : ucfirst( $billing_period );

		if ( 1 === $billing_interval ) {
			return $period_label;
		}

		// For intervals > 1, create labels like "Every 3 Months", "Every 2 Weeks", etc.
		switch ( $billing_period ) {
			case 'day':
				/* translators: %d: number of days */
				return sprintf( _n( 'Every %d Day', 'Every %d Days', $billing_interval, 'wcs-sublium-migrator' ), $billing_interval );
			case 'week':
				/* translators: %d: number of weeks */
				return sprintf( _n( 'Every %d Week', 'Every %d Weeks', $billing_interval, 'wcs-sublium-migrator' ), $billing_interval );
			case 'month':
				/* translators: %d: number of months */
				return sprintf( _n( 'Every %d Month', 'Every %d Months', $billing_interval, 'wcs-sublium-migrator' ), $billing_interval );
			case 'year':
				/* translators: %d: number of years */
				return sprintf( _n( 'Every %d Year', 'Every %d Years', $billing_interval, 'wcs-sublium-migrator' ), $billing_interval );
			default:
				return $period_label;
		}
	}

	/**
	 * Generate display summary text for plan.
	 *
	 * @param float $signup_fee Signup fee amount.
	 * @param int   $trial_days Trial days.
	 * @return string Display summary.
	 */
	private function generate_display_summary( $signup_fee, $trial_days ) {
		if ( $trial_days > 0 && $signup_fee > 0 ) {
			/* translators: %1$d: trial days, %2$s: signup fee */
			return sprintf( __( 'Billed {{subscription_price}} after %1$d days free trial and a one-time %2$s signup fee.', 'wcs-sublium-migrator' ), $trial_days, '{{signup_fee}}' );
		} elseif ( $trial_days > 0 ) {
			/* translators: %d: trial days */
			return sprintf( __( 'Billed {{subscription_price}} after %d days free trial.', 'wcs-sublium-migrator' ), $trial_days );
		} elseif ( $signup_fee > 0 ) {
			return __( 'Billed {{subscription_price}} with a one-time {{signup_fee}} signup fee.', 'wcs-sublium-migrator' );
		} else {
			return __( 'Billed {{subscription_price}}.', 'wcs-sublium-migrator' );
		}
	}
}
