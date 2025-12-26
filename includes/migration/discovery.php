<?php
/**
 * WCS Discovery Class
 *
 * @package WCS_Sublium_Migrator\Migration
 */

namespace WCS_Sublium_Migrator\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Discovery
 *
 * Handles discovery and analysis of WCS subscriptions, products, and gateways.
 */
class Discovery {

	/**
	 * Check if WooCommerce Subscriptions plugin exists and is active.
	 *
	 * @return array {
	 *     @type bool   $active    Whether plugin is active.
	 *     @type string $version   Plugin version.
	 *     @type bool   $compatible Whether version is compatible.
	 * }
	 */
	public function check_wcs_plugin() {
		$result = array(
			'active'     => false,
			'version'    => '',
			'compatible' => false,
		);

		// Check if WCS functions exist (most reliable check).
		if ( function_exists( 'wcs_get_subscription_statuses' ) || function_exists( 'wcs_get_subscription' ) ) {
			$result['active'] = true;
			// Try to get version from constant or class.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WCS_VERSION is defined by WooCommerce Subscriptions plugin.
			if ( defined( 'WCS_VERSION' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WCS_VERSION is defined by WooCommerce Subscriptions plugin.
				$wcs_version = constant( 'WCS_VERSION' );
				$result['version']    = $wcs_version;
				$result['compatible'] = version_compare( $wcs_version, '2.0.0', '>=' );
			} elseif ( class_exists( '\WC_Subscriptions' ) || class_exists( 'WC_Subscriptions' ) ) {
				$wcs_class = class_exists( '\WC_Subscriptions' ) ? '\WC_Subscriptions' : 'WC_Subscriptions';
				if ( method_exists( $wcs_class, 'instance' ) ) {
					$wcs = $wcs_class::instance();
					if ( isset( $wcs->version ) ) {
						$result['version']    = $wcs->version;
						$result['compatible'] = version_compare( $wcs->version, '2.0.0', '>=' );
					}
				}
			}
			return $result;
		}

		// Check if class exists (loaded).
		if ( class_exists( '\WC_Subscriptions' ) || class_exists( 'WC_Subscriptions' ) ) {
			$result['active'] = true;

			$wcs_class = class_exists( '\WC_Subscriptions' ) ? '\WC_Subscriptions' : 'WC_Subscriptions';

			if ( method_exists( $wcs_class, 'instance' ) ) {
				$wcs = $wcs_class::instance();
				if ( isset( $wcs->version ) ) {
					$result['version']    = $wcs->version;
					$result['compatible'] = version_compare( $wcs->version, '2.0.0', '>=' );
				}
			}
			return $result;
		}

		// Check if plugin is active even if class not loaded yet.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugins = get_option( 'active_plugins', array() );
		$is_active = false;
		$plugin_file = '';

		foreach ( $active_plugins as $plugin ) {
			if ( strpos( $plugin, 'woocommerce-subscriptions' ) !== false ) {
				$is_active = true;
				$plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
				break;
			}
		}

		// Check network active plugins if multisite.
		if ( ! $is_active && is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			foreach ( $network_plugins as $plugin => $timestamp ) {
				if ( strpos( $plugin, 'woocommerce-subscriptions' ) !== false ) {
					$is_active = true;
					$plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
					break;
				}
			}
		}

		// Also check using is_plugin_active.
		if ( ! $is_active ) {
			if ( is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) ) {
				$is_active = true;
				$plugin_file = WP_PLUGIN_DIR . '/woocommerce-subscriptions/woocommerce-subscriptions.php';
			}
		}

		if ( $is_active ) {
			$result['active'] = true;

			// Try to get version from plugin file.
			if ( ! empty( $plugin_file ) && file_exists( $plugin_file ) ) {
				$plugin_data = get_file_data(
					$plugin_file,
					array(
						'Version' => 'Version',
					)
				);
				if ( ! empty( $plugin_data['Version'] ) ) {
					$result['version']    = $plugin_data['Version'];
					$result['compatible'] = version_compare( $plugin_data['Version'], '2.0.0', '>=' );
				} else {
					// If we can't get version but plugin is active, assume compatible.
					$result['compatible'] = true;
				}
			} else {
				// If we can't find plugin file but it's active, assume compatible.
				$result['compatible'] = true;
			}
		}

		return $result;
	}

	/**
	 * Get subscription count (all statuses).
	 *
	 * @return int Number of subscriptions (including active, on-hold, cancelled, expired, etc.).
	 */
	public function get_active_subscription_count() {
		// Use WCS native function to get all subscriptions (including on-hold, cancelled, etc.).
		if ( function_exists( 'wcs_get_subscriptions' ) ) {
			try {
				// Get all subscriptions regardless of status (active, on-hold, cancelled, expired, etc.).
				$subscriptions = wcs_get_subscriptions(
					array(
						'status'  => 'any', // Get all subscription statuses.
						'limit'   => -1,
						'return'  => 'ids',
					)
				);

				if ( is_array( $subscriptions ) ) {
					return count( $subscriptions );
				}
			} catch ( \Exception $e ) {
				// If WCS function fails, fall through to wc_get_orders method.
			}
		}

		// Fallback: Use direct database query when WCS is deactivated.
		// wc_get_orders() filters by wc_get_order_statuses() which doesn't include subscription statuses when WCS is deactivated.
		global $wpdb;

		try {
			// Check if HPOS is enabled using OrderUtil helper.
			$is_hpos_enabled = false;
			if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
				$is_hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			}

			if ( $is_hpos_enabled ) {
				// HPOS enabled - query wc_orders table directly.
				$orders_table = $wpdb->prefix . 'wc_orders';
				$count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$orders_table} WHERE type = %s",
						'shop_subscription'
					)
				);
			} else {
				// Traditional post storage - query posts table directly.
				$count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
						'shop_subscription'
					)
				);
			}

			if ( is_numeric( $count ) ) {
				return absint( $count );
			}
		} catch ( \Exception $e ) {
			// If database query fails, return 0.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'WCS Migrator: Failed to count subscriptions via database query: %s', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return 0;
		}

		return 0;
	}

	/**
	 * Get subscription counts by status.
	 *
	 * @return array Subscription counts grouped by status.
	 */
	public function get_subscription_counts_by_status() {
		$status_counts = array();

		// Use WCS native function if available.
		if ( function_exists( 'wcs_get_subscriptions' ) ) {
			// Get all available WCS subscription statuses.
			$wcs_statuses = array();
			if ( function_exists( 'wcs_get_subscription_statuses' ) ) {
				$wcs_statuses_raw = wcs_get_subscription_statuses();
				// Extract status keys (remove 'wc-' prefix if present).
				foreach ( $wcs_statuses_raw as $status_key => $status_label ) {
					$clean_key = str_replace( 'wc-', '', $status_key );
					$wcs_statuses[] = $clean_key;
				}
			} else {
				// Fallback to common statuses if function not available.
				$wcs_statuses = array( 'active', 'on-hold', 'cancelled', 'expired', 'pending', 'pending-cancel', 'switched' );
			}

			// Count subscriptions for each status.
			foreach ( $wcs_statuses as $status ) {
				try {
					$subscriptions = wcs_get_subscriptions(
						array(
							'subscription_status' => $status,
							'limit'              => -1,
							'return'             => 'ids',
						)
					);

					$count = is_array( $subscriptions ) ? count( $subscriptions ) : 0;
					if ( $count > 0 ) {
						$status_counts[ $status ] = $count;
					}
				} catch ( \Exception $e ) {
					// Skip status if query fails.
					continue;
				}
			}
		} elseif ( function_exists( 'wc_get_orders' ) ) {
			// Fallback: Use wc_get_orders when WCS is deactivated.
			$common_statuses = array( 'active', 'on-hold', 'cancelled', 'expired', 'pending', 'pending-cancel', 'switched' );

			foreach ( $common_statuses as $status ) {
				try {
					$subscriptions = wc_get_orders(
						array(
							'type'   => 'shop_subscription',
							'status' => 'wc-' . $status,
							'limit'  => -1,
							'return' => 'ids',
						)
					);

					$count = is_array( $subscriptions ) ? count( $subscriptions ) : 0;
					if ( $count > 0 ) {
						$status_counts[ $status ] = $count;
					}
				} catch ( \Exception $e ) {
					// Skip status if query fails.
					continue;
				}
			}
		}

		return $status_counts;
	}

	/**
	 * Discover all payment gateways from active WCS subscriptions.
	 *
	 * @return array Gateway summary with counts and compatibility.
	 */
	public function discover_payment_gateways() {
		// Use WCS native function to get subscriptions.
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return array();
		}

		try {
			// Get all subscriptions (all statuses) for gateway discovery.
			$subscriptions = wcs_get_subscriptions(
				array(
					'status'  => 'any', // Get all subscription statuses.
					'limit'  => -1,
					'return' => 'objects',
				)
			);

			if ( ! is_array( $subscriptions ) || empty( $subscriptions ) ) {
				return array();
			}

			// Count gateways from subscription objects.
			$gateway_counts = array();
			foreach ( $subscriptions as $subscription ) {
				if ( ! is_a( $subscription, 'WC_Subscription' ) ) {
					continue;
				}

				$gateway_id = $subscription->get_payment_method();

				// Fallback to parent order's payment method if subscription payment method is empty.
				if ( empty( $gateway_id ) ) {
					$parent_order_id = $subscription->get_parent_id();
					if ( $parent_order_id ) {
						$parent_order = wc_get_order( $parent_order_id );
						if ( $parent_order instanceof \WC_Order ) {
							$gateway_id = $parent_order->get_payment_method();
						}
					}
				}

				if ( empty( $gateway_id ) ) {
					continue;
				}

				if ( ! isset( $gateway_counts[ $gateway_id ] ) ) {
					$gateway_counts[ $gateway_id ] = 0;
				}
				$gateway_counts[ $gateway_id ]++;
			}

			// Sort by count descending.
			arsort( $gateway_counts );

			// Build gateway summary.
			$gateway_summary = array();
			foreach ( $gateway_counts as $gateway_id => $count ) {
				$compatible = $this->check_gateway_compatibility( $gateway_id );

				$gateway_summary[] = array(
					'gateway_id'          => $gateway_id,
					'gateway_title'       => $this->get_gateway_title( $gateway_id ),
					'subscription_count'  => absint( $count ),
					'compatible'          => $compatible['compatible'],
					'message'            => $compatible['message'],
				);
			}

			return $gateway_summary;
		} catch ( \Exception $e ) {
			// If WCS function fails, return empty array.
			return array();
		}
	}

	/**
	 * Check gateway compatibility with Sublium.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return array Compatibility info.
	 */
	private function check_gateway_compatibility( $gateway_id ) {
		// Check if Sublium has this gateway.
		if ( ! class_exists( '\Sublium_WCS\Includes\Main\Gateways' ) ) {
			return array(
				'compatible' => false,
				'message'   => __( 'Sublium gateways not available', 'wcs-sublium-migrator' ),
			);
		}

		// Map common WCS gateways to Sublium.
		$gateway_map = array(
			'stripe'              => 'fkwcs_stripe',
			'stripe_cc'           => 'fkwcs_stripe',
			'paypal'              => 'fkwcppcp_paypal',
			'ppcp-gateway'        => 'fkwcppcp_paypal',
			'square_credit_card'  => 'fkwcsq_square',
			'fkwcs_stripe'=>'fkwcs_stripe',
			'fkwcppcp_paypal'=>'fkwcppcp_paypal',
			'fkwcsq_square'=>'fkwcsq_square',
			'fkwcs_stripe'=>'fkwcs_stripe',
			'fkwcppcp_paypal'=>'fkwcppcp_paypal',
			'fkwcsq_square'=>'fkwcsq_square',
			'fkwcs_stripe'=>'fkwcs_stripe',
			'fkwcppcp_paypal'=>'fkwcppcp_paypal',
			'fkwcsq_square'=>'fkwcsq_square',
			'fkwcs_stripe'=>'fkwcs_stripe',
			'fkwcppcp_paypal'=>'fkwcppcp_paypal',
			'fkwcsq_square'=>'fkwcsq_square',
			'authorize_net_cim'    => 'authorize_net',
			'bacs'                => 'bacs',
			'cheque'              => 'cheque',
			'cod'                 => 'cod',
		);

		$sublium_id = isset( $gateway_map[ $gateway_id ] ) ? $gateway_map[ $gateway_id ] : '';

		if ( empty( $sublium_id ) ) {
			return array(
				'compatible' => false,
				'message'   => __( 'Gateway not mapped to Sublium', 'wcs-sublium-migrator' ),
			);
		}

		return array(
			'compatible' => true,
			'message'   => sprintf( __( 'Maps to Sublium gateway: %s', 'wcs-sublium-migrator' ), $sublium_id ),
		);
	}

	/**
	 * Get gateway title.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return string Gateway title.
	 */
	private function get_gateway_title( $gateway_id ) {
		if ( ! function_exists( 'WC' ) ) {
			return $gateway_id;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways[ $gateway_id ] ) ) {
			return $gateways[ $gateway_id ]->get_title();
		}

		return ucfirst( str_replace( '_', ' ', $gateway_id ) );
	}

	/**
	 * Get subscription products summary.
	 *
	 * @return array Product counts by type.
	 */
	public function get_subscription_products_summary() {
		// Use WooCommerce native function to get products.
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array(
				'simple_count'   => 0,
				'variable_count' => 0,
				'wcsatt_count'   => 0,
				'wcsatt_active'  => false,
				'total'          => 0,
			);
		}

		// Get simple subscription products.
		$simple_products = wc_get_products(
			array(
				'type'   => array( 'subscription' ),
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'ids',
			)
		);
		$simple_count = is_array( $simple_products ) ? count( $simple_products ) : 0;

		// Get variable subscription products.
		$variable_products = wc_get_products(
			array(
				'type'   => array( 'variable-subscription' ),
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'ids',
			)
		);
		$variable_count = is_array( $variable_products ) ? count( $variable_products ) : 0;

		// Check for WCS_ATT products.
		$wcsatt_count = 0;
		$wcsatt_active = false;

		// Check if WCS_ATT plugin is active - only count WCS_ATT products if plugin is active.
		if ( class_exists( '\WCS_ATT' ) ) {
			$wcsatt_active = true;
		} else {
			// Check if plugin file exists even if class not loaded.
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$active_plugins = get_option( 'active_plugins', array() );
			foreach ( $active_plugins as $plugin ) {
				if ( strpos( $plugin, 'all-products-for-subscriptions' ) !== false || strpos( $plugin, 'wcs-att' ) !== false ) {
					$wcsatt_active = true;
					break;
				}
			}
		}

		// Only count WCS_ATT products if plugin is actually active (same logic as products-processor.php).
		if ( $wcsatt_active ) {
			// Get all published products to check for WCS_ATT meta.
			$all_products = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => -1,
					'return' => 'ids',
				)
			);

			if ( is_array( $all_products ) ) {
				foreach ( $all_products as $product_id ) {
					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}

					// Check for WCS_ATT schemes meta.
					$schemes = $product->get_meta( '_wcsatt_schemes', true );
					if ( ! empty( $schemes ) ) {
						// Handle serialized data.
						if ( is_string( $schemes ) ) {
							$schemes = maybe_unserialize( $schemes );
						}

						if ( is_array( $schemes ) && ! empty( $schemes ) ) {
							$wcsatt_count++;
						}
					}
				}
			}
		}

		return array(
			'simple_count'   => absint( $simple_count ),
			'variable_count' => absint( $variable_count ),
			'wcsatt_count'   => absint( $wcsatt_count ),
			'wcsatt_active'  => $wcsatt_active,
			'total'          => absint( $simple_count ) + absint( $variable_count ) + absint( $wcsatt_count ),
		);
	}

	/**
	 * Get comprehensive feasibility data.
	 *
	 * @return array Feasibility analysis.
	 */
	public function get_feasibility_data() {
		$wcs_status = $this->check_wcs_plugin();
		$active_subscriptions = $this->get_active_subscription_count();
		$subscription_statuses = $this->get_subscription_counts_by_status();
		$gateways = $this->discover_payment_gateways();
		$products = $this->get_subscription_products_summary();

		// Calculate readiness status.
		$readiness = $this->calculate_readiness( $wcs_status, $gateways, $products, $active_subscriptions );

		return array(
			'wcs_status'           => $wcs_status,
			'active_subscriptions' => $active_subscriptions,
			'subscription_statuses' => $subscription_statuses,
			'gateways'             => $gateways,
			'products'             => $products,
			'readiness'            => $readiness,
		);
	}

	/**
	 * Calculate migration readiness.
	 *
	 * @param array $wcs_status WCS plugin status.
	 * @param array $gateways Gateway data.
	 * @param array $products Product data.
	 * @param int   $active_subscriptions Active subscriptions count.
	 * @return array Readiness status.
	 */
	private function calculate_readiness( $wcs_status, $gateways, $products, $active_subscriptions ) {
		// Check if WCS is active - if version check fails but plugin is active, still allow migration.
		if ( ! $wcs_status['active'] ) {
			return array(
				'status'  => 'blocked',
				'message' => __( 'WooCommerce Subscriptions plugin is not active', 'wcs-sublium-migrator' ),
			);
		}

		// If active but version check failed, warn but don't block (might be version detection issue).
		if ( ! $wcs_status['compatible'] && ! empty( $wcs_status['version'] ) ) {
			return array(
				'status'  => 'partial',
				'message' => sprintf(
					// translators: %s: WCS version.
					__( 'WooCommerce Subscriptions version %s may not be fully compatible. Proceed with caution.', 'wcs-sublium-migrator' ),
					$wcs_status['version']
				),
			);
		}

		// Check if Sublium is available.
		if ( ! class_exists( '\Sublium_WCS\Plugin' ) ) {
			return array(
				'status'  => 'blocked',
				'message' => __( 'Sublium plugin is not active', 'wcs-sublium-migrator' ),
			);
		}

		// Check gateway compatibility.
		$incompatible_gateways = array_filter(
			$gateways,
			function( $gateway ) {
				return ! $gateway['compatible'];
			}
		);

		if ( ! empty( $incompatible_gateways ) ) {
			return array(
				'status'  => 'partial',
				'message' => sprintf(
					// translators: %d: number of incompatible gateways.
					__( '%d gateway(s) are not compatible with Sublium', 'wcs-sublium-migrator' ),
					count( $incompatible_gateways )
				),
			);
		}

		if ( $active_subscriptions === 0 && $products['total'] === 0 ) {
			return array(
				'status'  => 'feasible',
				'message' => __( 'No data to migrate', 'wcs-sublium-migrator' ),
			);
		}

		return array(
			'status'  => 'feasible',
			'message' => __( 'Migration is ready to proceed', 'wcs-sublium-migrator' ),
		);
	}
}
