<?php
/**
 * Products Migration Processor
 *
 * @package WCS_Sublium_Migrator\Migration
 */

namespace WCS_Sublium_Migrator\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Products_Processor
 *
 * Processes products migration in batches.
 */
class Products_Processor {

	/**
	 * Batch size.
	 *
	 * @var int
	 */
	private $batch_size = 50;

	/**
	 * Process a batch of products.
	 *
	 * @param int $offset Offset.
	 * @return array Result.
	 */
	public function process_batch( $offset = 0 ) {
		$state = new State();
		$current_state = $state->get_state();

		// Check if paused.
		if ( 'paused' === $current_state['status'] ) {
			return array(
				'success'  => false,
				'has_more' => false,
				'message'  => __( 'Migration is paused', 'wcs-sublium-migrator' ),
			);
		}

		// Get products to process.
		$products = $this->get_products_batch( $offset, $this->batch_size );

		$processed = 0;
		$created = 0;
		$failed = 0;

		foreach ( $products as $product_id ) {
			try {
				$result = $this->migrate_product( $product_id );
				if ( $result && is_numeric( $result ) && $result > 0 ) {
					// Result is now the number of plans created.
					$created += absint( $result );
				} else {
					++$failed;
					$product = wc_get_product( $product_id );
					$is_wcsatt = $this->is_wcsatt_product( $product_id );
					$product_type = $product ? $product->get_type() : 'unknown';
					$error_message = sprintf( 'Failed to migrate product %d (Type: %s, WCS_ATT: %s)', $product_id, $product_type, $is_wcsatt ? 'Yes' : 'No' );
					$state->add_error(
						$error_message,
						array( 'product_id' => $product_id, 'product_type' => $product_type, 'is_wcsatt' => $is_wcsatt )
					);
					// Log to WordPress debug log for debugging.
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'WCS Migrator: ' . $error_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}
				++$processed;
			} catch ( \Exception $e ) {
				++$failed;
				$error_message = sprintf( 'Error migrating product %d: %s', $product_id, $e->getMessage() );
				$state->add_error(
					$error_message,
					array( 'product_id' => $product_id, 'exception' => $e->getTraceAsString() )
				);
				// Log to WordPress debug log for debugging.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'WCS Migrator: ' . $error_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}

		// Update progress.
		$current_state = $state->get_state();
		$new_processed = $current_state['products_migration']['processed_products'] + $processed;
		$new_created = $current_state['products_migration']['created_plans'] + $created;
		$new_failed = $current_state['products_migration']['failed_products'] + $failed;

		$state->update_products_progress(
			array(
				'processed_products' => $new_processed,
				'created_plans'      => $new_created,
				'failed_products'    => $new_failed,
				'last_product_id'    => ! empty( $products ) ? end( $products ) : 0,
				'current_batch'      => floor( $new_processed / $this->batch_size ),
			)
		);

		// Check if more products exist.
		$has_more = count( $products ) === $this->batch_size;
		$next_offset = $has_more ? $offset + $this->batch_size : 0;

		return array(
			'success'    => true,
			'has_more'   => $has_more,
			'next_offset' => $next_offset,
			'processed'  => $processed,
			'created'    => $created,
			'failed'     => $failed,
		);
	}

	/**
	 * Get products batch.
	 *
	 * @param int $offset Offset.
	 * @param int $limit Limit.
	 * @return array Product IDs.
	 */
	private function get_products_batch( $offset, $limit ) {
		$product_ids = array();

		// Get native subscription products using WooCommerce native function.
		$native_products = wc_get_products(
			array(
				'type'   => array( 'subscription', 'variable-subscription' ),
				'status' => 'publish',
				'limit'  => $limit,
				'offset' => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);

		if ( is_array( $native_products ) ) {
			$product_ids = array_merge( $product_ids, array_map( 'absint', $native_products ) );
		}

		// If we have WCS_ATT products and haven't processed them yet, include them.
		if ( class_exists( '\WCS_ATT' ) && $offset === 0 ) {
			// Get all published products to check for WCS_ATT schemes.
			$all_products = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => -1, // Get all products to check for WCS_ATT meta.
					'return' => 'ids',
				)
			);

			$wcsatt_ids = array();
			if ( is_array( $all_products ) ) {
				foreach ( $all_products as $product_id ) {
					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}

					// Check if product has WCS_ATT schemes meta.
					$schemes = $product->get_meta( '_wcsatt_schemes', true );
					if ( ! empty( $schemes ) ) {
						// Handle serialized data.
						if ( is_string( $schemes ) ) {
							$schemes = maybe_unserialize( $schemes );
						}

						if ( is_array( $schemes ) && ! empty( $schemes ) ) {
							$wcsatt_ids[] = absint( $product_id );
						}
					}
				}
			}

			$product_ids = array_merge( $product_ids, $wcsatt_ids );
		}

		// Remove duplicates and ensure unique IDs.
		$final_ids = array_unique( array_map( 'absint', $product_ids ) );

		return $final_ids;
	}

	/**
	 * Migrate a single product.
	 *
	 * @param int $product_id Product ID.
	 * @return int|false Plan ID or false.
	 */
	private function migrate_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		// Check if product is WCS_ATT product.
		$is_wcsatt = $this->is_wcsatt_product( $product_id );

		// Extract subscription settings.
		if ( $is_wcsatt ) {
			$subscription_settings_list = $this->extract_wcsatt_settings( $product_id );
		} else {
			$subscription_settings = $this->extract_native_subscription_settings( $product_id );
			// Convert single settings to array format for consistent processing.
			$subscription_settings_list = $subscription_settings ? array( $subscription_settings ) : array();
		}

		if ( empty( $subscription_settings_list ) || ! is_array( $subscription_settings_list ) ) {
			return false;
		}

		// Determine plan type based on virtual status.
		$plan_type = $this->determine_plan_type( $product );

		// Create plan group (one group per product).
		$plan_group_id = $this->create_plan_group( $product, $plan_type );
		if ( ! $plan_group_id ) {
			return false;
		}

		// Create a plan for each scheme.
		$created_plan_ids = array();
		foreach ( $subscription_settings_list as $scheme_index => $subscription_settings ) {
			// Check if plan already exists for this scheme.
			$existing_plan_id = $this->check_plan_exists( $product_id, 0, $subscription_settings );
			if ( $existing_plan_id ) {
				$created_plan_ids[] = $existing_plan_id;
				continue;
			}

			// Create plan for this scheme.
			$plan_id = $this->create_plan( $plan_group_id, $product, $subscription_settings, $plan_type );
			if ( ! $plan_id ) {
				continue; // Continue with next scheme.
			}

			// Create plan relation for this plan.
			$this->create_plan_relation( $plan_id, $product_id, 0, $subscription_settings, $plan_type );

			$created_plan_ids[] = $plan_id;
		}

		if ( empty( $created_plan_ids ) ) {
			return false;
		}

		$plans_count = count( $created_plan_ids );
		// Return number of plans created (or first plan ID if only one plan).
		// This allows the caller to track how many plans were actually created.
		return $plans_count > 0 ? $plans_count : false;
	}

	/**
	 * Check if product is WCS_ATT product.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private function is_wcsatt_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$schemes = $product->get_meta( '_wcsatt_schemes', true );
		return ! empty( $schemes ) && is_array( $schemes ) && ! empty( $schemes );
	}

	/**
	 * Extract WCS_ATT subscription settings for all schemes.
	 *
	 * @param int $product_id Product ID.
	 * @return array|false Array of subscription settings (one per scheme) or false.
	 */
	private function extract_wcsatt_settings( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$schemes = $product->get_meta( '_wcsatt_schemes', true );
		if ( empty( $schemes ) ) {
			return false;
		}

		// Handle serialized data.
		if ( is_string( $schemes ) ) {
			$schemes = maybe_unserialize( $schemes );
		}

		if ( ! is_array( $schemes ) || empty( $schemes ) ) {
			return false;
		}

		$all_settings = array();

		// Process all schemes, not just the first one.
		foreach ( $schemes as $scheme_key => $scheme ) {
			// Handle both array format and object format.
			if ( is_object( $scheme ) ) {
				$scheme = (array) $scheme;
			}

			if ( ! is_array( $scheme ) ) {
				continue;
			}

			// Skip inactive schemes if they have an 'active' flag set to false.
			if ( isset( $scheme['active'] ) && ( 'no' === $scheme['active'] || false === $scheme['active'] || 0 === $scheme['active'] ) ) {
				continue;
			}

			// Extract settings from scheme - handle different key formats.
			$price = 0;
			$pricing_method = isset( $scheme['subscription_pricing_method'] ) ? $scheme['subscription_pricing_method'] : 'inherit';

			// If pricing method is 'inherit', use regular product price.
			if ( 'inherit' === $pricing_method || 'override' !== $pricing_method ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$price = (float) $product->get_regular_price();
					if ( $product->is_on_sale() ) {
						$sale_price = $product->get_sale_price();
						if ( ! empty( $sale_price ) ) {
							$price = (float) $sale_price;
						}
					}
				}
			}

			// Override with scheme price if explicitly set.
			if ( isset( $scheme['subscription_price'] ) && '' !== $scheme['subscription_price'] ) {
				$price = (float) $scheme['subscription_price'];
			} elseif ( isset( $scheme['subscription_regular_price'] ) && '' !== $scheme['subscription_regular_price'] ) {
				$price = (float) $scheme['subscription_regular_price'];
				// Check for sale price.
				if ( isset( $scheme['subscription_sale_price'] ) && '' !== $scheme['subscription_sale_price'] ) {
					$price = (float) $scheme['subscription_sale_price'];
				}
			} elseif ( isset( $scheme['price'] ) && '' !== $scheme['price'] ) {
				$price = (float) $scheme['price'];
			}

			$period = 'month';
			if ( isset( $scheme['subscription_period'] ) ) {
				$period = $scheme['subscription_period'];
			} elseif ( isset( $scheme['period'] ) ) {
				$period = $scheme['period'];
			}

			$period_interval = 1;
			if ( isset( $scheme['subscription_period_interval'] ) ) {
				$period_interval = absint( $scheme['subscription_period_interval'] );
			} elseif ( isset( $scheme['period_interval'] ) ) {
				$period_interval = absint( $scheme['period_interval'] );
			} elseif ( isset( $scheme['interval'] ) ) {
				$period_interval = absint( $scheme['interval'] );
			}

			$length = 0;
			if ( isset( $scheme['subscription_length'] ) ) {
				$length = absint( $scheme['subscription_length'] );
			} elseif ( isset( $scheme['length'] ) ) {
				$length = absint( $scheme['length'] );
			}

			$sign_up_fee = 0;
			if ( isset( $scheme['subscription_sign_up_fee'] ) ) {
				$sign_up_fee = (float) $scheme['subscription_sign_up_fee'];
			} elseif ( isset( $scheme['sign_up_fee'] ) ) {
				$sign_up_fee = (float) $scheme['sign_up_fee'];
			} elseif ( isset( $scheme['signup_fee'] ) ) {
				$sign_up_fee = (float) $scheme['signup_fee'];
			}

			$trial_length = 0;
			if ( isset( $scheme['subscription_trial_length'] ) ) {
				$trial_length = absint( $scheme['subscription_trial_length'] );
			} elseif ( isset( $scheme['trial_length'] ) ) {
				$trial_length = absint( $scheme['trial_length'] );
			}

			$trial_period = 'day';
			if ( isset( $scheme['subscription_trial_period'] ) ) {
				$trial_period = $scheme['subscription_trial_period'];
			} elseif ( isset( $scheme['trial_period'] ) ) {
				$trial_period = $scheme['trial_period'];
			}

			// Extract discount from scheme.
			// Discount is only available when pricing_method is 'inherit'.
			$discount = 0;
			if ( 'inherit' === $pricing_method ) {
				if ( isset( $scheme['subscription_discount'] ) && '' !== $scheme['subscription_discount'] && $scheme['subscription_discount'] > 0 ) {
					$discount = (float) $scheme['subscription_discount'];
				} elseif ( isset( $scheme['discount'] ) && '' !== $scheme['discount'] && $scheme['discount'] > 0 ) {
					$discount = (float) $scheme['discount'];
				}
			}

			// Validate that we have at least period.
			if ( empty( $period ) ) {
				// Log debug info if WP_DEBUG is enabled.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'WCS Migrator: Product %d WCS_ATT scheme missing period. Scheme keys: %s', $product_id, implode( ', ', array_keys( $scheme ) ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				continue; // Skip this scheme.
			}

			$all_settings[] = array(
				'price'           => $price,
				'period'          => $period,
				'period_interval' => $period_interval,
				'length'          => $length,
				'sign_up_fee'     => $sign_up_fee,
				'trial_length'    => $trial_length,
				'trial_period'    => $trial_period,
				'discount'        => $discount,
			);
		}

		if ( empty( $all_settings ) ) {
			return false;
		}

		return $all_settings;
	}

	/**
	 * Extract native subscription settings.
	 *
	 * @param int $product_id Product ID.
	 * @return array|false Subscription settings or false.
	 */
	private function extract_native_subscription_settings( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$product_type = $product->get_type();
		if ( ! in_array( $product_type, array( 'subscription', 'variable-subscription' ), true ) ) {
			return false;
		}

		return array(
			'price'           => (float) $product->get_meta( '_subscription_price', true ),
			'period'          => $product->get_meta( '_subscription_period', true ),
			'period_interval' => absint( $product->get_meta( '_subscription_period_interval', true ) ),
			'length'          => absint( $product->get_meta( '_subscription_length', true ) ),
			'sign_up_fee'     => (float) $product->get_meta( '_subscription_sign_up_fee', true ),
			'trial_length'    => absint( $product->get_meta( '_subscription_trial_length', true ) ),
			'trial_period'    => $product->get_meta( '_subscription_trial_period', true ),
		);
	}

	/**
	 * Determine plan type based on product virtual status.
	 *
	 * @param \WC_Product $product Product object.
	 * @return int Plan type (1=Subscribe & Save, 2=Recurring).
	 */
	private function determine_plan_type( $product ) {
		if ( $product->is_virtual() ) {
			return 2; // Recurring.
		}
		return 1; // Subscribe & Save.
	}

	/**
	 * Check if plan already exists.
	 *
	 * @param int   $product_id Product ID.
	 * @param int   $variation_id Variation ID.
	 * @param array $settings Subscription settings.
	 * @return int|false Existing plan ID or false.
	 */
	private function check_plan_exists( $product_id, $variation_id, $settings ) {
		if ( ! class_exists( '\Sublium_WCS\Includes\database\PlanRelations' ) || ! class_exists( '\Sublium_WCS\Includes\database\Plan' ) ) {
			return false;
		}

		// Convert period to interval.
		$interval = $this->convert_period_to_interval( $settings['period'] );
		$frequency = absint( $settings['period_interval'] );
		$trial_days = $this->convert_trial_to_days( $settings['trial_length'], $settings['trial_period'] );

		// First, find plan relations for this product/variation.
		$plan_relations = new \Sublium_WCS\Includes\database\PlanRelations();
		$relations = $plan_relations->read(
			array(
				'oid'    => absint( $product_id ),
				'vid'    => absint( $variation_id ),
				'type'   => 1, // Specific product.
				'status' => 1, // Active.
			)
		);

		if ( empty( $relations ) || ! is_array( $relations ) ) {
			return false;
		}

		// Extract plan IDs from relations.
		$plan_ids = array();
		foreach ( $relations as $relation ) {
			if ( isset( $relation['plan_id'] ) ) {
				$plan_ids[] = absint( $relation['plan_id'] );
			}
		}

		if ( empty( $plan_ids ) ) {
			return false;
		}

		// Check each plan to see if it matches the billing settings.
		$plan_db = new \Sublium_WCS\Includes\database\Plan();
		foreach ( $plan_ids as $plan_id ) {
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
	 * Create plan group.
	 *
	 * @param \WC_Product $product Product object.
	 * @param int         $plan_type Plan type.
	 * @return int|false Plan group ID or false.
	 */
	private function create_plan_group( $product, $plan_type ) {
		if ( ! class_exists( '\Sublium_WCS\Includes\database\GroupPlans' ) ) {
			return false;
		}

		$group_db = new \Sublium_WCS\Includes\database\GroupPlans();
		$group_title = $product->get_name();

		$group_data = array(
			'title'        => sanitize_text_field( $group_title ),
			'type'         => $plan_type,
			'product_type' => 1, // Specific products.
		);

		return $group_db->create( $group_data );
	}

	/**
	 * Create plan.
	 *
	 * @param int         $plan_group_id Plan group ID.
	 * @param \WC_Product $product Product object.
	 * @param array       $settings Subscription settings.
	 * @param int         $plan_type Plan type.
	 * @return int|false Plan ID or false.
	 */
	private function create_plan( $plan_group_id, $product, $settings, $plan_type ) {
		if ( ! class_exists( '\Sublium_WCS\Includes\database\Plan' ) ) {
			return false;
		}

		$plan_db = new \Sublium_WCS\Includes\database\Plan();

		// Convert period to interval.
		$interval = $this->convert_period_to_interval( $settings['period'] );
		$frequency = absint( $settings['period_interval'] );
		$length = absint( $settings['length'] );
		$trial_days = $this->convert_trial_to_days( $settings['trial_length'], $settings['trial_period'] );

		// Format signup fee as JSON.
		$signup_fee = array();
		if ( ! empty( $settings['sign_up_fee'] ) && $settings['sign_up_fee'] > 0 ) {
			$signup_fee = array(
				'signup_fee_type' => 'fixed',
				'signup_amount' => number_format( (float) $settings['sign_up_fee'], 2, '.', '' ),
			);
		}

		// Format discount/offer as JSON.
		// For Type 2 (Recurring), don't store discount in offer field - it will be stored as sale_price in plan relation.
		// For Type 1 (Subscribe and Save), store discount in offer field.
		$offer = array();
		if ( ! empty( $settings['discount'] ) && $settings['discount'] > 0 && 1 === $plan_type ) {
			$offer = array(
				'price_type'     => 'default',
				'discount_type'  => 'percentage',
				'discount_value' => number_format( (float) $settings['discount'], 2, '.', '' ),
			);
		}

		// Generate plan title.
		$plan_title = $this->generate_plan_title( $frequency, $interval );

		$plan_data = array(
			'plan_group_id'     => $plan_group_id,
			'title'             => $plan_title,
			'type'              => $plan_type,
			'billing_frequency' => $frequency,
			'billing_interval'  => $interval,
			'billing_length'    => $length,
			'signup_fee'        => $signup_fee,
			'offer'             => $offer,
			'free_trial'        => $trial_days,
			'status'            => 1, // Published.
		);

		return $plan_db->create( $plan_data );
	}

	/**
	 * Create plan relation.
	 *
	 * @param int   $plan_id Plan ID.
	 * @param int   $product_id Product ID.
	 * @param int   $variation_id Variation ID.
	 * @param array $settings Subscription settings (for discount data).
	 * @param int   $plan_type Plan type.
	 * @return bool Success.
	 */
	private function create_plan_relation( $plan_id, $product_id, $variation_id, $settings = array(), $plan_type = 1 ) {
		if ( ! class_exists( '\Sublium_WCS\Includes\database\PlanRelations' ) ) {
			return false;
		}

		$relations_db = new \Sublium_WCS\Includes\database\PlanRelations();

		// Prepare relation data field based on plan type.
		$relation_data_field = array();

		// For Type 1 (Subscribe and Save), store discount in relation data.
		if ( 1 === $plan_type && ! empty( $settings['discount'] ) && $settings['discount'] > 0 ) {
			$relation_data_field = array(
				'discount_type'  => 'percentage',
				'discount_value' => number_format( (float) $settings['discount'], 2, '.', '' ),
			);
		}

		// For Type 2 (Recurring), store regular/sale price in relation data.
		if ( 2 === $plan_type ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$regular_price = $product->get_regular_price();
				$sale_price = $product->get_sale_price();

				// If there's a discount from WCS_ATT scheme, calculate discounted price.
				// Use WCS_ATT native function if available, otherwise calculate manually.
				if ( ! empty( $settings['discount'] ) && $settings['discount'] > 0 && $regular_price ) {
					$discounted_price = null;

					// Try to use WCS_ATT native function if available.
					// Use backslash prefix to ensure we're using the global namespace.
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
					if ( class_exists( '\WCS_ATT_Product_Prices' ) && method_exists( '\WCS_ATT_Product_Prices', 'get_price' ) ) {
						// Build scheme key from settings.
						$scheme_key = absint( $settings['period_interval'] ) . '_' . $settings['period'] . '_' . absint( $settings['length'] );

						// Try to get discounted price using WCS_ATT function.
						// This requires the product to have the scheme set, so we'll try it but fall back to manual calculation.
						try {
							// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
							$discounted_price = \WCS_ATT_Product_Prices::get_price( $product, $scheme_key );
							if ( ! $discounted_price || $discounted_price >= $regular_price ) {
								$discounted_price = null; // Fall back to manual calculation.
							}
						} catch ( \Exception $e ) {
							// Fall back to manual calculation if WCS_ATT function fails.
							$discounted_price = null;
						}
					}

					// If WCS_ATT function didn't work, calculate manually using WCS_ATT formula.
					// Formula: regular_price * (100 - discount) / 100
					if ( null === $discounted_price ) {
						$discount_percentage = (float) $settings['discount'];
						$discounted_price = round( (float) $regular_price * ( 100 - $discount_percentage ) / 100, wc_get_price_decimals() );
					}

					// Only set sale_price if discounted price is less than regular price.
					if ( $discounted_price < $regular_price ) {
						$sale_price = $discounted_price;
					}
				}

				$relation_data_field = array(
					'regular_price' => $regular_price ? number_format( (float) $regular_price, 2, '.', '' ) : '',
					'sale_price'    => $sale_price ? number_format( (float) $sale_price, 2, '.', '' ) : '',
				);
			}
		}

		$relation_data = array(
			'plan_id' => $plan_id,
			'oid'     => $product_id,
			'vid'     => $variation_id,
			'type'    => 1, // Specific product.
			'status'  => 1, // Active.
			'data'    => $relation_data_field,
		);

		return (bool) $relations_db->create( $relation_data );
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
	 * Generate plan title.
	 *
	 * @param int $frequency Billing frequency.
	 * @param int $interval Billing interval.
	 * @return string Plan title.
	 */
	private function generate_plan_title( $frequency, $interval ) {
		$interval_names = array(
			1 => __( 'Day', 'wcs-sublium-migrator' ),
			2 => __( 'Week', 'wcs-sublium-migrator' ),
			3 => __( 'Month', 'wcs-sublium-migrator' ),
			4 => __( 'Year', 'wcs-sublium-migrator' ),
		);

		$interval_name = isset( $interval_names[ $interval ] ) ? $interval_names[ $interval ] : __( 'Month', 'wcs-sublium-migrator' );

		if ( $frequency === 1 ) {
			return sprintf( __( 'Every %s', 'wcs-sublium-migrator' ), $interval_name );
		} else {
			return sprintf( __( 'Every %d %ss', 'wcs-sublium-migrator' ), $frequency, $interval_name );
		}
	}
}
