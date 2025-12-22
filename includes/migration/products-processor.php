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
				if ( $result ) {
					++$created;
				} else {
					++$failed;
					$state->add_error(
						sprintf( 'Failed to migrate product %d', $product_id ),
						array( 'product_id' => $product_id )
					);
				}
				++$processed;
			} catch ( \Exception $e ) {
				++$failed;
				$state->add_error(
					sprintf( 'Error migrating product %d: %s', $product_id, $e->getMessage() ),
					array( 'product_id' => $product_id )
				);
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
		global $wpdb;

		// Get native subscription products.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration query
		$query = $wpdb->prepare(
			"SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND pm.meta_key = '_product_type'
			AND pm.meta_value IN ('subscription', 'variable-subscription')
			ORDER BY p.ID ASC
			LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		$product_ids = $wpdb->get_col( $query );

		// If we have WCS_ATT products and haven't processed them yet, include them.
		if ( class_exists( '\WCS_ATT' ) && $offset === 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration query
			$wcsatt_query = $wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND pm.meta_key = '_wcsatt_schemes'
				AND pm.meta_value != ''
				AND pm.meta_value != 'a:0:{}'"
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
			$wcsatt_ids = $wpdb->get_col( $wcsatt_query );
			$product_ids = array_merge( $product_ids, $wcsatt_ids );
		}

		return array_map( 'absint', $product_ids );
	}

	/**
	 * Migrate a single product.
	 *
	 * @param int $product_id Product ID.
	 * @return int|false Plan ID or false.
	 */
	private function migrate_product( $product_id ) {
		// Use Sublium's migration classes if available.
		if ( ! class_exists( '\Sublium_WCS\Includes\Migration\Extractors\Product_Extractor' ) ) {
			return false;
		}

		try {
			$extractor = new \Sublium_WCS\Includes\Migration\Extractors\Product_Extractor();
			$wcs_data = $extractor->extract( $product_id );

			if ( ! $wcs_data ) {
				return false;
			}

			$transformer = new \Sublium_WCS\Includes\Migration\Transformers\Product_Transformer();
			$plan_data = $transformer->transform( $wcs_data );

			if ( empty( $plan_data ) ) {
				return false;
			}

			$importer = new \Sublium_WCS\Includes\Migration\Importers\Plan_Importer();
			return $importer->import( $plan_data );
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
