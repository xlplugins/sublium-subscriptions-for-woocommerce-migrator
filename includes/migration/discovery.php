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
			if ( defined( 'WCS_VERSION' ) ) {
				$result['version']    = WCS_VERSION;
				$result['compatible'] = version_compare( WCS_VERSION, '2.0.0', '>=' );
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
	 * Get active subscription count.
	 *
	 * @return int Number of active subscriptions.
	 */
	public function get_active_subscription_count() {
		// Try using WCS function first if available.
		if ( function_exists( 'wcs_get_subscriptions' ) ) {
			try {
				$subscriptions = wcs_get_subscriptions(
					array(
						'subscription_status' => 'active',
						'limit'               => -1,
						'return'              => 'ids',
					)
				);
				if ( is_array( $subscriptions ) && ! empty( $subscriptions ) ) {
					return count( $subscriptions );
				}
			} catch ( \Exception $e ) {
				// Fall through to direct query.
			}
		}

		global $wpdb;

		// Check if HPOS is enabled.
		$hpos_enabled = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		if ( $hpos_enabled ) {
			// Use HPOS tables.
			$orders_table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration discovery query
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$orders_table}
					WHERE type = 'shop_subscription'
					AND status IN ('wc-active', 'wc-pending-cancel')"
				)
			);
		} else {
			// Use posts table - try different status formats.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration discovery query
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$wpdb->posts}
					WHERE post_type = 'shop_subscription'
					AND post_status IN ('wc-active', 'wc-pending-cancel', 'active', 'pending-cancel')"
				)
			);

			// If still 0, try without status filter to see total subscriptions.
			if ( 0 === absint( $count ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration discovery query
				$total = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*)
						FROM {$wpdb->posts}
						WHERE post_type = 'shop_subscription'"
					)
				);
				// If we have subscriptions but none match active status, try getting all statuses.
				if ( absint( $total ) > 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration discovery query
					$statuses = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT DISTINCT post_status
							FROM {$wpdb->posts}
							WHERE post_type = 'shop_subscription'
							LIMIT 10"
						)
					);
					// Try with found statuses.
					if ( ! empty( $statuses ) ) {
						$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration discovery query
						$count = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(*)
								FROM {$wpdb->posts}
								WHERE post_type = 'shop_subscription'
								AND post_status IN ($status_placeholders)",
								$statuses
							)
						);
					}
				}
			}
		}

		return absint( $count );
	}

	/**
	 * Discover all payment gateways from active WCS subscriptions.
	 *
	 * @return array Gateway summary with counts and compatibility.
	 */
	public function discover_payment_gateways() {
		global $wpdb;

		// Check if HPOS is enabled.
		$hpos_enabled = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		if ( $hpos_enabled ) {
			// Use HPOS tables.
			$orders_table = $wpdb->prefix . 'wc_orders';
			$orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration discovery query
			$query = $wpdb->prepare(
				"SELECT
					om1.meta_value as gateway_id,
					COUNT(DISTINCT o.id) as subscription_count,
					COUNT(DISTINCT CASE WHEN om2.meta_value = 'true' THEN o.id END) as manual_renewal_count
				FROM {$orders_table} o
				INNER JOIN {$orders_meta_table} om1 ON o.id = om1.order_id AND om1.meta_key = '_payment_method'
				LEFT JOIN {$orders_meta_table} om2 ON o.id = om2.order_id AND om2.meta_key = '_requires_manual_renewal'
				WHERE o.type = 'shop_subscription'
				AND o.status IN ('wc-active', 'wc-pending-cancel')
				GROUP BY om1.meta_value
				ORDER BY subscription_count DESC"
			);
		} else {
			// Use posts table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration discovery query
			$query = $wpdb->prepare(
				"SELECT
					pm.meta_value as gateway_id,
					COUNT(DISTINCT p.ID) as subscription_count,
					COUNT(DISTINCT CASE WHEN pm2.meta_value = 'true' THEN p.ID END) as manual_renewal_count
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_payment_method'
				LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_requires_manual_renewal'
				WHERE p.post_type = 'shop_subscription'
				AND p.post_status IN ('wc-active', 'wc-pending-cancel')
				GROUP BY pm.meta_value
				ORDER BY subscription_count DESC"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		$gateway_data = $wpdb->get_results( $query, ARRAY_A );

		$gateway_summary = array();
		foreach ( $gateway_data as $gateway ) {
			$gateway_id = isset( $gateway['gateway_id'] ) ? $gateway['gateway_id'] : '';
			$compatible = $this->check_gateway_compatibility( $gateway_id );

			$gateway_summary[] = array(
				'gateway_id'          => $gateway_id,
				'gateway_title'       => $this->get_gateway_title( $gateway_id ),
				'subscription_count'  => absint( $gateway['subscription_count'] ),
				'manual_renewal_count' => absint( $gateway['manual_renewal_count'] ),
				'compatible'          => $compatible['compatible'],
				'message'            => $compatible['message'],
			);
		}

		return $gateway_summary;
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
			'stripe'              => 'stripe',
			'stripe_cc'           => 'stripe',
			'paypal'              => 'paypal',
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
		global $wpdb;

		// Simple subscription products - check both taxonomy and postmeta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration discovery query
		$simple_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_product_type'
				WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND (
					(tt.taxonomy = 'product_type' AND t.slug = 'subscription')
					OR pm.meta_value = 'subscription'
				)"
			)
		);

		// Variable subscription products - check both taxonomy and postmeta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration discovery query
		$variable_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_product_type'
				WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND (
					(tt.taxonomy = 'product_type' AND t.slug = 'variable-subscription')
					OR pm.meta_value = 'variable-subscription'
				)"
			)
		);

		// WCS_ATT products - check if plugin exists or if meta exists.
		$wcsatt_count = 0;
		$wcsatt_active = false;

		// Check if WCS_ATT plugin is active by checking for the class or plugin file.
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

		// Always check for WCS_ATT products if meta exists, regardless of plugin status.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration discovery query
		$wcsatt_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND pm.meta_key = '_wcsatt_schemes'
				AND pm.meta_value != ''
				AND pm.meta_value != 'a:0:{}'"
			)
		);

		// If we found WCS_ATT products, mark plugin as active.
		if ( $wcsatt_count > 0 ) {
			$wcsatt_active = true;
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
		$gateways = $this->discover_payment_gateways();
		$products = $this->get_subscription_products_summary();

		// Calculate readiness status.
		$readiness = $this->calculate_readiness( $wcs_status, $gateways, $products, $active_subscriptions );

		return array(
			'wcs_status'          => $wcs_status,
			'active_subscriptions' => $active_subscriptions,
			'gateways'            => $gateways,
			'products'            => $products,
			'readiness'           => $readiness,
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
