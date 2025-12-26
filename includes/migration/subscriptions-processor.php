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
	private $batch_size = 10; // Set to 1 for testing progress bar updates.

	/**
	 * Process a batch of subscriptions.
	 *
	 * @param int $offset Offset.
	 * @return array Result.
	 */
	public function process_batch( $offset = 0 ) {

		$state         = new State();
		$current_state = $state->get_state();


		// Check if paused.
		if ( 'paused' === $current_state['status'] ) {
			return array(
				'success'  => false,
				'has_more' => false,
				'message'  => __( 'Migration is paused', 'wcs-sublium-migrator' ),
			);
		}

		// Get subscriptions batch.
		$subscriptions = $this->get_subscriptions_batch( $offset, $this->batch_size );

		$processed     = 0;
		$created       = 0;
		$failed        = 0;

		foreach ( $subscriptions as $wcs_subscription ) {
			try {
				$subscription_id = is_a( $wcs_subscription, 'WC_Subscription' ) ? $wcs_subscription->get_id() : 0;

				// Check gateway support before migration.
				$gateway = $wcs_subscription->get_payment_method();
				if ( ! empty( $gateway ) && ! $this->is_gateway_supported_by_sublium( $gateway ) ) {
					$gateway_title = $wcs_subscription->get_payment_method_title();
					$warning_message = sprintf(
						/* translators: 1: Subscription ID, 2: Gateway ID, 3: Gateway Title */
						__( 'Subscription #%1$d uses unsupported gateway "%2$s" (%3$s). This subscription will be migrated but may require manual payment method update.', 'wcs-sublium-migrator' ),
						$subscription_id,
						$gateway,
						! empty( $gateway_title ) ? $gateway_title : $gateway
					);
					$state->add_error(
						$warning_message,
						array(
							'subscription_id' => $subscription_id,
							'gateway'        => $gateway,
							'gateway_title'  => $gateway_title,
							'type'           => 'gateway_warning',
						)
					);
				}

				// Check if subscription was already migrated before calling migrate_subscription.
				$was_already_migrated = ! empty( $wcs_subscription->get_meta( '_sublium_wcs_subscription_id', true ) );

				$result = $this->migrate_subscription( $wcs_subscription );
				file_put_contents( __DIR__ . '/debug.log', print_r( $result, true ) . "\n", FILE_APPEND );
				if ( $result ) {
					// Result is a subscription ID (either newly created or already migrated).
					if ( $was_already_migrated ) {
						// Already migrated - count as processed but not created.
						++$processed;
					} else {
						// Newly migrated - count as both processed and created.
						++$created;
						++$processed;
					}
				} else {
					// Migration failed - count as processed and failed.
					++$failed;
					++$processed;
					$state->add_error(
						sprintf( 'Failed to migrate subscription %d', $subscription_id ),
						array( 'subscription_id' => $subscription_id )
					);
				}
			} catch ( \Exception $e ) {
				++$failed;
				$subscription_id = is_a( $wcs_subscription, 'WC_Subscription' ) ? $wcs_subscription->get_id() : 0;
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

		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return array();
		}

		// Only fetch subscriptions that haven't been migrated yet.
		// Exclude subscriptions that have _sublium_wcs_subscription_id meta key.
		$subscriptions = wcs_get_subscriptions(
			array(
				'status'     => 'any', // Migrate all subscriptions including on-hold, cancelled, expired, etc.
				'limit'      => $limit,
				'offset'     => $offset,
				'orderby'    => 'ID',
				'order'      => 'ASC',
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

		if ( empty( $subscriptions ) || ! is_array( $subscriptions ) ) {
			return array();
		}

		// Convert IDs to subscription objects.
		$subscription_objects = array();
		foreach ( $subscriptions as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( $subscription && is_a( $subscription, 'WC_Subscription' ) ) {
				// Double-check: verify subscription hasn't been migrated (in case meta was added between query and now).
				$existing_sublium_id = $subscription->get_meta( '_sublium_wcs_subscription_id', true );
				if ( empty( $existing_sublium_id ) ) {
					$subscription_objects[] = $subscription;
				} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'WCS Migrator: Subscription %d already has migration meta, skipping', $subscription_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}

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

		if ( ! is_a( $wcs_subscription, 'WC_Subscription' ) ) {
			return false;
		}

		if ( ! class_exists( '\Sublium_WCS\Includes\Controller\Subscriptions\Subscription' ) ) {
			return false;
		}

		// Check if subscription already migrated (meta check to prevent duplicates).
		$existing_sublium_id = $wcs_subscription->get_meta( '_sublium_wcs_subscription_id', true );
		if ( ! empty( $existing_sublium_id ) ) {
			// Verify the Sublium subscription still exists.
			if ( function_exists( 'sublium_get_subscription' ) ) {
				$sublium_subscription = sublium_get_subscription( $existing_sublium_id );
				if ( $sublium_subscription && $sublium_subscription->exists ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( sprintf( 'WCS Migrator: Subscription %d already migrated (Sublium ID: %d), skipping', $subscription_id, $existing_sublium_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
					return absint( $existing_sublium_id );
				}
			} else {
				// If function doesn't exist, assume it's migrated to avoid duplicates.
				return absint( $existing_sublium_id );
			}
		}

		// Extract subscription data from WCS subscription.
		$subscription_data = $this->extract_subscription_data( $wcs_subscription );

		if ( empty( $subscription_data ) ) {
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

		// Update created_at dates after creation (Subscription::save() overrides them during creation).
		if ( ! empty( $subscription_data['created_at'] ) && ! empty( $subscription_data['created_at_utc'] ) ) {
			// Update created_at dates directly via database to avoid save() override.
			global $wpdb;
			$table_name = $wpdb->prefix . 'sublium_wcs_subscriptions';
			$wpdb->update(
				$table_name,
				array(
					'created_at'     => $subscription_data['created_at'],
					'created_at_utc' => $subscription_data['created_at_utc'],
				),
				array( 'id' => $sublium_subscription_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			// Reload subscription to reflect changes.
			if ( function_exists( 'sublium_get_subscription' ) ) {
				$sublium_subscription = sublium_get_subscription( $sublium_subscription_id );
			} else {
				$sublium_subscription = new \Sublium_WCS\Includes\Controller\Subscriptions\Subscription( $sublium_subscription_id );
			}
		}

		// Add subscription items.
		$this->add_subscription_items( $sublium_subscription, $wcs_subscription );

		// Link WCS subscription to Sublium subscription.
		$wcs_subscription->update_meta_data( '_sublium_wcs_subscription_id', $sublium_subscription_id );
		// Mark subscription as migrated.
		$wcs_subscription->update_meta_data( '_sublium_subscription_migrated', 'yes' );
		$wcs_subscription->save();

		// Link parent order and renewal orders to Sublium subscription using direct database query.
		$this->link_orders_to_sublium_subscription( $wcs_subscription, $sublium_subscription_id );

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

		if ( ! is_a( $wcs_subscription, 'WC_Subscription' ) ) {
			return false;
		}

		// Get parent order.
		$parent_order = $wcs_subscription->get_parent();

		if ( ! $parent_order ) {
			return false;
		}

		// Create plan_data from subscription data.

		$plan_data = $this->create_plan_data_from_subscription( $wcs_subscription, $parent_order );

		if ( empty( $plan_data ) || ! is_array( $plan_data ) ) {
			return false;
		}

		$plan_type = isset( $plan_data['type'] ) ? absint( $plan_data['type'] ) : 2; // Default to Recurring.

		// Get dates.
		$created_date      = $wcs_subscription->get_date_created();
		$next_payment_date = $wcs_subscription->get_date( 'next_payment' );
		$end_date          = $wcs_subscription->get_date( 'end' );
		$trial_end_date    = $wcs_subscription->get_date( 'trial_end' );

		// Convert created date to system/UTC format.
		if ( $created_date && is_a( $created_date, 'WC_DateTime' ) ) {
			$created_date_utc    = $created_date->date( 'Y-m-d H:i:s' );
			$created_date_system = get_date_from_gmt( $created_date_utc, 'Y-m-d H:i:s' );
		} else {
			// Fallback to date_created meta if get_date_created() doesn't work.
			$created_date_string = $wcs_subscription->get_date( 'date_created' );
			if ( ! empty( $created_date_string ) && '0' !== $created_date_string ) {
				$created_date_utc    = $created_date_string;
				$created_date_system = get_date_from_gmt( $created_date_string, 'Y-m-d H:i:s' );
			} else {
				$created_date_utc    = gmdate( 'Y-m-d H:i:s' );
				$created_date_system = current_time( 'mysql' );
			}
		}

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

		// Get subscription details from product (subscription doesn't have get_length, get_trial_length, etc. methods).
		$billing_length_meta = 0;
		$trial_length_meta   = 0;
		$trial_period_meta   = '';
		$signup_fee_meta     = 0.0;

		if ( ! empty( $item_ids ) && class_exists( 'WC_Subscriptions_Product' ) ) {
			$first_product = wc_get_product( absint( $item_ids[0] ) );
			if ( $first_product ) {
				$billing_length_meta = absint( \WC_Subscriptions_Product::get_length( $first_product ) );
				$trial_length_meta   = absint( \WC_Subscriptions_Product::get_trial_length( $first_product ) );
				$trial_period_meta   = \WC_Subscriptions_Product::get_trial_period( $first_product );
				$signup_fee_meta     = (float) \WC_Subscriptions_Product::get_sign_up_fee( $first_product );
			}
		}

		// Prepare meta data.
		$meta_data = array(
			'billing_frequency' => absint( $billing_interval ),
			'billing_interval'  => $this->convert_period_to_interval( $billing_period ),
			'billing_length'    => $billing_length_meta,
			'trial_length'      => $trial_length_meta,
			'trial_period'      => $trial_period_meta,
			'signup_fee'        => $signup_fee_meta,
			'plan_data'         => $plan_data, // Store plan_data in meta for recurring payments.
			'wcs_subscription_id' => absint( $wcs_subscription->get_id() ), // Store WCS subscription ID for maintaining relationship.
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
			'parent_order_id'       => $parent_order->get_id(), // Use WCS subscription ID instead of parent order ID.
			'gateway'               => $gateway ? $gateway : 'manual',
			'gateway_mode'          => 1,
			'user_id'               => absint( $user_id ),
			'status'                => $status,
			'plan_id'               => array( '0' ), // Set to 0 when using plan_data.
			'plan_type'             => $plan_type,
			'currency'              => $currency,
			'totals'                => $totals,
			'base_totals'           => $base_totals,
			'created_at'            => $created_date_system,
			'created_at_utc'        => $created_date_utc,
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

		try {
			// Get product IDs from subscription items.
		$product_ids = array();
		foreach ( $wcs_subscription->get_items() as $item ) {
			$product_id = $item->get_product_id();
			if ( $product_id ) {
				$product_ids[] = absint( $product_id );
			}
		}


		if ( empty( $product_ids ) ) {
			return false;
		}

		// Get billing information from subscription.
		$billing_period   = $wcs_subscription->get_billing_period();
		$billing_interval = absint( $wcs_subscription->get_billing_interval() );

		// Get subscription details from product (subscription doesn't have get_length, get_trial_length, etc. methods).
		$billing_length = 0;
		$trial_length   = 0;
		$trial_period   = '';
		$signup_fee     = 0.0;

		if ( ! empty( $product_ids ) && class_exists( 'WC_Subscriptions_Product' ) ) {
			$first_product = wc_get_product( $product_ids[0] );
			if ( $first_product ) {
				$billing_length = absint( \WC_Subscriptions_Product::get_length( $first_product ) );
				$trial_length   = absint( \WC_Subscriptions_Product::get_trial_length( $first_product ) );
				$trial_period   = \WC_Subscriptions_Product::get_trial_period( $first_product );
				$signup_fee     = (float) \WC_Subscriptions_Product::get_sign_up_fee( $first_product );
			}
		}


		// Convert to Sublium format.
		$interval   = $this->convert_period_to_interval( $billing_period );
		$frequency  = $billing_interval;
		$trial_days = $this->convert_trial_to_days( $trial_length, $trial_period );


		// Determine plan type based on product (virtual = Recurring, physical = Subscribe & Save).
		$plan_type = 2; // Default to Recurring.
		$first_product = wc_get_product( $product_ids[0] );
		if ( $first_product && ! $first_product->is_virtual() ) {
			$plan_type = 1; // Subscribe & Save.
		}


		// Generate plan title from billing period.
		$plan_title = $this->generate_plan_title( $billing_period, $billing_interval );


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


			return $plan_data;
		} catch ( \Exception $e ) {
			return false;
		} catch ( \Error $e ) {
			return false;
		}
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

		if ( ! class_exists( '\Sublium_WCS\Includes\database\PlanRelations' ) || ! class_exists( '\Sublium_WCS\Includes\database\Plan' ) ) {
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


		if ( empty( $product_ids ) ) {
			return false;
		}

		// Get billing period and interval.
		$billing_period   = $wcs_subscription->get_billing_period();
		$billing_interval = absint( $wcs_subscription->get_billing_interval() );

		// Get trial details from product (subscription doesn't have get_trial_length, get_trial_period methods).
		$trial_length = 0;
		$trial_period = '';

		if ( ! empty( $product_ids ) && class_exists( 'WC_Subscriptions_Product' ) ) {
			$first_product = wc_get_product( $product_ids[0] );
			if ( $first_product ) {
				$trial_length = absint( \WC_Subscriptions_Product::get_trial_length( $first_product ) );
				$trial_period = \WC_Subscriptions_Product::get_trial_period( $first_product );
			}
		}


		// Convert to Sublium format.
		$interval   = $this->convert_period_to_interval( $billing_period );
		$frequency  = $billing_interval;
		$trial_days = $this->convert_trial_to_days( $trial_length, $trial_period );


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


		if ( empty( $plan_ids ) ) {
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


			// Check if billing settings match.
			if ( isset( $plan['billing_frequency'] ) && absint( $plan['billing_frequency'] ) === $frequency &&
				isset( $plan['billing_interval'] ) && absint( $plan['billing_interval'] ) === $interval &&
				isset( $plan['free_trial'] ) && absint( $plan['free_trial'] ) === $trial_days ) {
				return absint( $plan_id );
			}
		}

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

		$item_ids = array();

		foreach ( $wcs_subscription->get_items() as $wcs_item ) {
			$product = $wcs_item->get_product();
			if ( ! $product ) {
				continue;
			}

			$variation_id = $wcs_item->get_variation_id();
			$product_id   = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

			// Prepare item_data array (nested structure).
			$item_data_array = array(
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
				$prepared_data = \Sublium_WCS\Includes\Helpers\Utility::prepare_subscription_item( $item_data_array, $sublium_subscription );
				// Utility may return different structure, merge it back into item_data_array.
				if ( is_array( $prepared_data ) && isset( $prepared_data['item_data'] ) ) {
					$item_data_array = array_merge( $item_data_array, $prepared_data['item_data'] );
				}
			}

			// Prepare item data in correct format for add_item().
			$item_data = array(
				'item_type' => 1, // Product item.
				'item_data' => $item_data_array,
			);

			// Add item to subscription.
			$added = $sublium_subscription->add_item( $item_data );
			if ( $added ) {
				// Collect item IDs for update_items().
				$values = array( (string) $product_id );
				if ( ! empty( $variation_id ) ) {
					$values[] = (string) $variation_id;
				}
				$item_ids = array_merge( $item_ids, array_filter( $values ) );
			}
		}

		// Update subscription items field with collected item IDs.
		if ( ! empty( $item_ids ) ) {
			$sublium_subscription->update_items( $item_ids );
		}
	}

	/**
	 * Link parent order and renewal orders to Sublium subscription using direct database queries.
	 *
	 * @param \WC_Subscription $wcs_subscription WooCommerce Subscription object.
	 * @param int              $sublium_subscription_id Sublium subscription ID.
	 * @return void
	 */
	private function link_orders_to_sublium_subscription( $wcs_subscription, $sublium_subscription_id ) {
		global $wpdb;

		$wcs_subscription_id = $wcs_subscription->get_id();
		$parent_order_id     = $wcs_subscription->get_parent_id();

		// Check if HPOS is enabled.
		$is_hpos = function_exists( 'wcs_is_custom_order_tables_usage_enabled' ) && wcs_is_custom_order_tables_usage_enabled();

		$order_ids = array();

		// Get parent order ID.
		if ( ! empty( $parent_order_id ) ) {
			$order_ids[] = absint( $parent_order_id );
		}

		// Get renewal orders using direct database query.
		if ( $is_hpos ) {
			// HPOS mode: Query wc_orders_meta table.
			$renewal_order_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT order_id
					FROM {$wpdb->prefix}wc_orders_meta
					WHERE meta_key = %s
					AND meta_value = %s",
					'_subscription_renewal',
					$wcs_subscription_id
				)
			);
		} else {
			// CPT mode: Query postmeta table.
			$renewal_order_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id
					FROM {$wpdb->postmeta}
					WHERE meta_key = %s
					AND meta_value = %s",
					'_subscription_renewal',
					$wcs_subscription_id
				)
			);
		}

		if ( ! empty( $renewal_order_ids ) ) {
			$order_ids = array_merge( $order_ids, array_map( 'absint', $renewal_order_ids ) );
		}

		// Remove duplicates.
		$order_ids = array_unique( $order_ids );

		if ( empty( $order_ids ) ) {
			return;
		}

		// Update meta for all orders in bulk.
		foreach ( $order_ids as $order_id ) {
			$order_id = absint( $order_id );
			if ( empty( $order_id ) ) {
				continue;
			}

			// Check if this is a renewal order (explicitly exclude parent order).
			$is_renewal = ! empty( $parent_order_id ) && absint( $order_id ) !== absint( $parent_order_id ) && in_array( $order_id, array_map( 'absint', $renewal_order_ids ), true );

			if ( $is_hpos ) {
				// HPOS mode: Update wc_orders_meta table.
				// Check if meta already exists (HPOS uses 'id' as primary key, not 'meta_id').
				$existing_meta = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id
						FROM {$wpdb->prefix}wc_orders_meta
						WHERE order_id = %d
						AND meta_key = %s
						LIMIT 1",
						$order_id,
						'_sublium_wcs_subscription_id'
					)
				);

				if ( $existing_meta ) {
					// Update existing meta.
					$wpdb->update(
						$wpdb->prefix . 'wc_orders_meta',
						array( 'meta_value' => $sublium_subscription_id ),
						array(
							'order_id' => $order_id,
							'meta_key' => '_sublium_wcs_subscription_id',
						),
						array( '%d' ),
						array( '%d', '%s' )
					);
				} else {
					// Insert new meta.
					$wpdb->insert(
						$wpdb->prefix . 'wc_orders_meta',
						array(
							'order_id'   => $order_id,
							'meta_key'   => '_sublium_wcs_subscription_id',
							'meta_value' => $sublium_subscription_id,
						),
						array( '%d', '%s', '%d' )
					);
				}

				// Add renewal flag only if this is a renewal order (not the parent order).
				if ( $is_renewal ) {
					$existing_renewal_meta = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id
							FROM {$wpdb->prefix}wc_orders_meta
							WHERE order_id = %d
							AND meta_key = %s
							LIMIT 1",
							$order_id,
							'_sublium_wcs_subscription_renewal'
						)
					);

					if ( $existing_renewal_meta ) {
						$wpdb->update(
							$wpdb->prefix . 'wc_orders_meta',
							array( 'meta_value' => 'yes' ),
							array(
								'order_id' => $order_id,
								'meta_key' => '_sublium_wcs_subscription_renewal',
							),
							array( '%s' ),
							array( '%d', '%s' )
						);
					} else {
						$wpdb->insert(
							$wpdb->prefix . 'wc_orders_meta',
							array(
								'order_id'   => $order_id,
								'meta_key'   => '_sublium_wcs_subscription_renewal',
								'meta_value' => 'yes',
							),
							array( '%d', '%s', '%s' )
						);
					}
				}
			} else {
				// CPT mode: Update postmeta table.
				update_post_meta( $order_id, '_sublium_wcs_subscription_id', $sublium_subscription_id );

				// Add renewal flag only if this is a renewal order (not the parent order).
				if ( $is_renewal ) {
					update_post_meta( $order_id, '_sublium_wcs_subscription_renewal', 'yes' );
				}
			}
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
	 * Check if gateway is supported by Sublium.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool True if supported, false otherwise.
	 */
	private function is_gateway_supported_by_sublium( $gateway_id ) {
		if ( empty( $gateway_id ) ) {
			return true; // Empty gateway means manual renewal, which is supported.
		}

		// Manual payment gateways are always supported.
		$manual_gateways = array( 'bacs', 'cheque', 'cod', 'manual' );
		if ( in_array( $gateway_id, $manual_gateways, true ) ) {
			return true;
		}

		// Get supported gateways from Sublium using static method.
		if ( class_exists( '\Sublium_WCS\Includes\Abstracts\Payment_Gateway' ) ) {
			$supported_gateways = \Sublium_WCS\Includes\Abstracts\Payment_Gateway::get_supported_gateways();
			if ( is_array( $supported_gateways ) && array_key_exists( $gateway_id, $supported_gateways ) ) {
				return true;
			}
		}

		// Check if gateway instance exists (means it's registered and available).
		if ( class_exists( '\Sublium_WCS\Includes\Main\Gateways' ) ) {
			$gateways_instance = \Sublium_WCS\Includes\Main\Gateways::get_instance();
			if ( method_exists( $gateways_instance, 'get_gateway' ) ) {
				$gateway_instance = $gateways_instance->get_gateway( $gateway_id );
				return ! empty( $gateway_instance );
			}
		}

		return false;
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
