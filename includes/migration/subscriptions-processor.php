<?php
/**
 * Subscriptions Migration Processor
 *
 * @package WCS_Sublium_Migrator\Migration
 */

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

		// Get subscriptions batch.
		$subscriptions = $this->get_subscriptions_batch( $offset, $this->batch_size );
		$processed = 0;
		$created = 0;
		$failed = 0;

		foreach ( $subscriptions as $subscription_data ) {
			try {
				$result = $this->migrate_subscription( $subscription_data );
				if ( $result ) {
					++$created;
				} else {
					++$failed;
					$state->add_error(
						sprintf( 'Failed to migrate subscription %d', $subscription_data['id'] ?? 0 ),
						array( 'subscription_id' => $subscription_data['id'] ?? 0 )
					);
				}
				++$processed;
			} catch ( \Exception $e ) {
				++$failed;
				$state->add_error(
					sprintf( 'Error migrating subscription %d: %s', $subscription_data['id'] ?? 0, $e->getMessage() ),
					array( 'subscription_id' => $subscription_data['id'] ?? 0 )
				);
			}
		}

		// Update progress.
		$current_state = $state->get_state();
		$new_processed = $current_state['subscriptions_migration']['processed_subscriptions'] + $processed;
		$new_created = $current_state['subscriptions_migration']['created_subscriptions'] + $created;
		$new_failed = $current_state['subscriptions_migration']['failed_subscriptions'] + $failed;

		$state->update_subscriptions_progress(
			array(
				'processed_subscriptions' => $new_processed,
				'created_subscriptions'   => $new_created,
				'failed_subscriptions'    => $new_failed,
				'last_subscription_id'    => ! empty( $subscriptions ) && isset( $subscriptions[ count( $subscriptions ) - 1 ]['id'] ) ? $subscriptions[ count( $subscriptions ) - 1 ]['id'] : 0,
				'current_batch'            => floor( $new_processed / $this->batch_size ),
			)
		);

		// Check if more subscriptions exist.
		$has_more = count( $subscriptions ) === $this->batch_size;
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
	 * Get subscriptions batch.
	 *
	 * @param int $offset Offset.
	 * @param int $limit Limit.
	 * @return array Subscription data.
	 */
	private function get_subscriptions_batch( $offset, $limit ) {
		// Use Sublium's extractor if available.
		if ( ! class_exists( '\Sublium_WCS\Includes\Migration\Extractors\Subscription_Extractor' ) ) {
			return array();
		}

		$extractor = new \Sublium_WCS\Includes\Migration\Extractors\Subscription_Extractor();
		return $extractor->extract_subscriptions_batch( $limit, $offset );
	}

	/**
	 * Migrate a single subscription.
	 *
	 * @param array $subscription_data Subscription data.
	 * @return int|false Subscription ID or false.
	 */
	private function migrate_subscription( $subscription_data ) {
		// Use Sublium's migration classes if available.
		if ( ! class_exists( '\Sublium_WCS\Includes\Migration\Transformers\Subscription_Transformer' ) ) {
			return false;
		}

		try {
			$transformer = new \Sublium_WCS\Includes\Migration\Transformers\Subscription_Transformer();
			$sublium_data = $transformer->transform( $subscription_data );

			if ( empty( $sublium_data ) ) {
				return false;
			}

			$importer = new \Sublium_WCS\Includes\Migration\Importers\Subscription_Importer();
			return $importer->import( $sublium_data );
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
