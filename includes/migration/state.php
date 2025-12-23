<?php
/**
 * Migration State Manager
 *
 * @package WCS_Sublium_Migrator\Migration
 */

namespace WCS_Sublium_Migrator\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class State
 *
 * Manages migration state and progress tracking.
 */
class State {

	/**
	 * Option name for migration state.
	 *
	 * @var string
	 */
	const STATE_OPTION_NAME = 'wcs_sublium_migration_state';

	/**
	 * Get migration state.
	 *
	 * @return array Migration state.
	 */
	public function get_state() {
		$state = get_option( self::STATE_OPTION_NAME, array() );
		$default_state = $this->get_default_state();
		
		// Merge with defaults to ensure all keys exist.
		if ( is_array( $state ) ) {
			$state = array_merge( $default_state, $state );
			// Ensure nested arrays are merged too.
			if ( isset( $state['products_migration'] ) && is_array( $state['products_migration'] ) ) {
				$state['products_migration'] = array_merge( $default_state['products_migration'], $state['products_migration'] );
			} else {
				$state['products_migration'] = $default_state['products_migration'];
			}
			if ( isset( $state['subscriptions_migration'] ) && is_array( $state['subscriptions_migration'] ) ) {
				$state['subscriptions_migration'] = array_merge( $default_state['subscriptions_migration'], $state['subscriptions_migration'] );
			} else {
				$state['subscriptions_migration'] = $default_state['subscriptions_migration'];
			}
			return $state;
		}
		
		return $default_state;
	}

	/**
	 * Update migration state.
	 *
	 * @param array $state State data.
	 * @return void
	 */
	public function update_state( $state ) {
		update_option( self::STATE_OPTION_NAME, $state, false );
	}

	/**
	 * Get default state structure.
	 *
	 * @return array Default state.
	 */
	private function get_default_state() {
		return array(
			'status'                  => 'idle', // idle, discovering, products_migrating, subscriptions_migrating, completed, paused, error.
			'products_migration'      => array(
				'total_products'      => 0,
				'processed_products' => 0,
				'created_plans'       => 0,
				'failed_products'     => 0,
				'last_product_id'     => 0,
				'current_batch'       => 0,
			),
			'subscriptions_migration' => array(
				'total_subscriptions'      => 0,
				'processed_subscriptions' => 0,
				'created_subscriptions'    => 0,
				'failed_subscriptions'     => 0,
				'last_subscription_id'     => 0,
				'current_batch'            => 0,
			),
			'start_time'              => '',
			'end_time'                => '',
			'last_activity'           => '',
			'estimated_completion'     => '',
			'errors'                  => array(),
		);
	}

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	public function reset_state() {
		delete_option( self::STATE_OPTION_NAME );
	}

	/**
	 * Update products migration progress.
	 *
	 * @param array $progress Progress data.
	 * @return void
	 */
	public function update_products_progress( $progress ) {
		$state                      = $this->get_state();
		$state['products_migration'] = array_merge( $state['products_migration'], $progress );
		$state['status']            = 'products_migrating';
		$state['last_activity']     = current_time( 'mysql' );
		$this->update_state( $state );
	}

	/**
	 * Update subscriptions migration progress.
	 *
	 * @param array $progress Progress data.
	 * @return void
	 */
	public function update_subscriptions_progress( $progress ) {
		$state                           = $this->get_state();
		$state['subscriptions_migration'] = array_merge( $state['subscriptions_migration'], $progress );
		$state['status']                 = 'subscriptions_migrating';
		$state['last_activity']          = current_time( 'mysql' );
		$this->update_state( $state );
	}

	/**
	 * Add error.
	 *
	 * @param string $message Error message.
	 * @param array  $context Error context.
	 * @return void
	 */
	public function add_error( $message, $context = array() ) {
		$state = $this->get_state();
		if ( ! isset( $state['errors'] ) ) {
			$state['errors'] = array();
		}
		$state['errors'][] = array(
			'message' => sanitize_text_field( $message ),
			'context' => $context,
			'time'    => current_time( 'mysql' ),
		);
		$this->update_state( $state );
	}

	/**
	 * Set status.
	 *
	 * @param string $status Status.
	 * @return void
	 */
	public function set_status( $status ) {
		$state              = $this->get_state();
		$state['status']    = $status;
		$state['last_activity'] = current_time( 'mysql' );
		$this->update_state( $state );
	}

	/**
	 * Create database tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		// No custom tables needed, using WordPress options API.
	}
}
