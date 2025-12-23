<?php
/**
 * Product Converter
 *
 * @package WCS_Sublium_Migrator\Migration
 */

namespace WCS_Sublium_Migrator\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Product_Converter
 *
 * Converts subscription products to regular simple products.
 */
class Product_Converter {

	/**
	 * Instance.
	 *
	 * @var Product_Converter
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Product_Converter
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
	 * Get all subscription products.
	 *
	 * @return array Array of product IDs with metadata.
	 */
	public function get_subscription_products() {
		$products = array();

		// Get native subscription products.
		$native_products = wc_get_products(
			array(
				'type'   => array( 'subscription', 'variable-subscription' ),
				'limit'  => -1,
				'status' => 'any',
				'return' => 'ids',
			)
		);

		foreach ( $native_products as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$active_subscriptions = $this->get_active_subscriptions_count( $product_id );
			$products[] = array(
				'id'                    => $product_id,
				'name'                  => $product->get_name(),
				'type'                  => $product->get_type(),
				'active_subscriptions'  => $active_subscriptions,
				'can_convert'           => 0 === $active_subscriptions,
				'is_wcsatt'             => false,
			);
		}

		// Get WCS_ATT products if plugin is active.
		if ( class_exists( 'WCS_ATT' ) ) {
			$wcsatt_products = $this->get_wcsatt_products();
			$products = array_merge( $products, $wcsatt_products );
		}

		return $products;
	}

	/**
	 * Get WCS_ATT products.
	 *
	 * @return array Array of product IDs with metadata.
	 */
	private function get_wcsatt_products() {
		global $wpdb;

		$products = array();

		// Find products with _wcsatt_schemes meta.
		$meta_key = '_wcsatt_schemes';
		$query = $wpdb->prepare(
			"SELECT DISTINCT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = %s
			AND meta_value != ''
			AND meta_value != 'a:0:{}'",
			$meta_key
		);

		$product_ids = $wpdb->get_col( $query );

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$active_subscriptions = $this->get_active_subscriptions_count( $product_id );
			$products[] = array(
				'id'                    => $product_id,
				'name'                  => $product->get_name(),
				'type'                  => $product->get_type(),
				'active_subscriptions'  => $active_subscriptions,
				'can_convert'           => 0 === $active_subscriptions,
				'is_wcsatt'             => true,
			);
		}

		return $products;
	}

	/**
	 * Get count of active WCS subscriptions for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return int Count of active subscriptions.
	 */
	private function get_active_subscriptions_count( $product_id ) {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return 0;
		}

		$subscriptions = wcs_get_subscriptions(
			array(
				'status'     => 'active',
				'limit'      => -1,
				'product_id' => $product_id,
			)
		);

		return count( $subscriptions );
	}

	/**
	 * Check if product can be safely converted.
	 *
	 * @param int $product_id Product ID.
	 * @return array Result with can_convert flag and message.
	 */
	public function can_convert_product( $product_id ) {
		$result = array(
			'can_convert' => false,
			'message'     => '',
		);

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$result['message'] = __( 'Product not found.', 'wcs-sublium-migrator' );
			return $result;
		}

		// Check if product is a subscription product.
		$is_subscription = $product->is_type( 'subscription' ) || $product->is_type( 'variable-subscription' );
		$is_wcsatt = $this->is_wcsatt_product( $product_id );

		if ( ! $is_subscription && ! $is_wcsatt ) {
			$result['message'] = __( 'Product is not a subscription product.', 'wcs-sublium-migrator' );
			return $result;
		}

		// Check for active subscriptions.
		$active_count = $this->get_active_subscriptions_count( $product_id );
		if ( $active_count > 0 ) {
			$result['message'] = sprintf(
				/* translators: %d: Number of active subscriptions */
				__( 'Cannot convert: Product has %d active subscription(s).', 'wcs-sublium-migrator' ),
				$active_count
			);
			return $result;
		}

		$result['can_convert'] = true;
		$result['message'] = __( 'Product can be converted.', 'wcs-sublium-migrator' );
		return $result;
	}

	/**
	 * Check if product is WCS_ATT product.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True if WCS_ATT product.
	 */
	private function is_wcsatt_product( $product_id ) {
		if ( ! class_exists( 'WCS_ATT' ) ) {
			return false;
		}

		$schemes = get_post_meta( $product_id, '_wcsatt_schemes', true );
		return ! empty( $schemes ) && is_array( $schemes );
	}

	/**
	 * Convert subscription product to simple product.
	 *
	 * @param int $product_id Product ID.
	 * @return array Result array.
	 */
	public function convert_subscription_product_to_simple( $product_id ) {
		$result = array(
			'success' => false,
			'message' => '',
		);

		// Check if can convert.
		$can_convert = $this->can_convert_product( $product_id );
		if ( ! $can_convert['can_convert'] ) {
			$result['message'] = $can_convert['message'];
			return $result;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$result['message'] = __( 'Product not found.', 'wcs-sublium-migrator' );
			return $result;
		}

		try {
			// Handle variable products.
			if ( $product->is_type( 'variable-subscription' ) ) {
				return $this->convert_variable_subscription_product( $product_id );
			}

			// Handle simple subscription products.
			if ( $product->is_type( 'subscription' ) ) {
				return $this->convert_simple_subscription_product( $product_id );
			}

			// Handle WCS_ATT products.
			if ( $this->is_wcsatt_product( $product_id ) ) {
				return $this->convert_wcsatt_product( $product_id );
			}

			$result['message'] = __( 'Product type not supported for conversion.', 'wcs-sublium-migrator' );
		} catch ( \Exception $e ) {
			$result['message'] = sprintf(
				/* translators: %s: Error message */
				__( 'Conversion failed: %s', 'wcs-sublium-migrator' ),
				$e->getMessage()
			);
		}

		return $result;
	}

	/**
	 * Convert simple subscription product to simple product.
	 *
	 * @param int $product_id Product ID.
	 * @return array Result array.
	 */
	private function convert_simple_subscription_product( $product_id ) {
		global $wpdb;

		// Change product type.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_type' => 'product' ),
			array( 'ID' => $product_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Update product type meta.
		update_post_meta( $product_id, '_product_type', 'simple' );

		// Remove subscription meta keys.
		$this->remove_subscription_meta( $product_id );

		// Clear product cache.
		wc_delete_product_transients( $product_id );

		return array(
			'success' => true,
			'message' => __( 'Product converted successfully.', 'wcs-sublium-migrator' ),
		);
	}

	/**
	 * Convert variable subscription product to variable product.
	 *
	 * @param int $product_id Product ID.
	 * @return array Result array.
	 */
	private function convert_variable_subscription_product( $product_id ) {
		global $wpdb;

		// Change parent product type.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_type' => 'product' ),
			array( 'ID' => $product_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Update product type meta.
		update_post_meta( $product_id, '_product_type', 'variable' );

		// Remove subscription meta from parent.
		$this->remove_subscription_meta( $product_id );

		// Convert variations.
		$product = wc_get_product( $product_id );
		if ( $product && method_exists( $product, 'get_children' ) ) {
			$variation_ids = $product->get_children();
			foreach ( $variation_ids as $variation_id ) {
				// Change variation type.
				$wpdb->update(
					$wpdb->posts,
					array( 'post_type' => 'product_variation' ),
					array( 'ID' => $variation_id ),
					array( '%s' ),
					array( '%d' )
				);

				// Remove subscription meta from variation.
				$this->remove_subscription_meta( $variation_id );
			}
		}

		// Clear product cache.
		wc_delete_product_transients( $product_id );

		return array(
			'success' => true,
			'message' => __( 'Variable product converted successfully.', 'wcs-sublium-migrator' ),
		);
	}

	/**
	 * Convert WCS_ATT product (remove subscription schemes).
	 *
	 * @param int $product_id Product ID.
	 * @return array Result array.
	 */
	private function convert_wcsatt_product( $product_id ) {
		// Remove WCS_ATT schemes meta.
		delete_post_meta( $product_id, '_wcsatt_schemes' );

		// Clear product cache.
		wc_delete_product_transients( $product_id );

		return array(
			'success' => true,
			'message' => __( 'WCS_ATT product converted successfully.', 'wcs-sublium-migrator' ),
		);
	}

	/**
	 * Remove subscription-related meta keys from product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function remove_subscription_meta( $product_id ) {
		$meta_keys = array(
			'_subscription_price',
			'_subscription_period',
			'_subscription_period_interval',
			'_subscription_length',
			'_subscription_sign_up_fee',
			'_subscription_trial_length',
			'_subscription_trial_period',
			'_subscription_one_time_shipping',
			'_subscription_limit',
			'_subscription_payment_sync_date',
		);

		foreach ( $meta_keys as $meta_key ) {
			delete_post_meta( $product_id, $meta_key );
		}
	}

	/**
	 * Bulk convert products.
	 *
	 * @param array $product_ids Array of product IDs.
	 * @return array Result array with success/failure counts.
	 */
	public function bulk_convert_products( $product_ids ) {
		$result = array(
			'success' => 0,
			'failed'  => 0,
			'errors'  => array(),
		);

		foreach ( $product_ids as $product_id ) {
			$convert_result = $this->convert_subscription_product_to_simple( $product_id );
			if ( $convert_result['success'] ) {
				++$result['success'];
			} else {
				++$result['failed'];
				$result['errors'][] = array(
					'product_id' => $product_id,
					'message'    => $convert_result['message'],
				);
			}
		}

		return $result;
	}
}
