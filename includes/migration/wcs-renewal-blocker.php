<?php
/**
 * WCS Renewal Blocker
 *
 * @package WCS_Sublium_Migrator\Migration
 */

namespace WCS_Sublium_Migrator\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCS_Renewal_Blocker
 *
 * Prevents WCS from processing renewals for migrated subscriptions.
 */
class WCS_Renewal_Blocker {

	/**
	 * Instance.
	 *
	 * @var WCS_Renewal_Blocker
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return WCS_Renewal_Blocker
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
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		// Hijack action scheduler hooks to prevent renewal actions from being scheduled.
		add_filter( 'pre_as_schedule_single_action', array( $this, 'prevent_scheduling_single_renewal_action' ), 10, 7 );
		add_filter( 'pre_as_schedule_recurring_action', array( $this, 'prevent_scheduling_recurring_renewal_action' ), 10, 8 );

		// Block scheduled payment hook (priority 1 to run before WCS processes it).
		add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'block_migrated_subscription_renewal' ), 1, 1 );

		// Block renewal order creation (works for both scheduled and manual).
		// Hook into the filter that runs after renewal order is created.
		add_filter( 'wcs_renewal_order_created', array( $this, 'prevent_renewal_order_after_creation' ), 10, 2 );

		// Block manual renewal order processing.
		add_action( 'woocommerce_generated_manual_renewal_order', array( $this, 'block_manual_renewal_processing' ), 1, 2 );

		// Block early renewal from admin UI.
		add_filter( 'wcs_early_renewal_allowed', array( $this, 'prevent_early_renewal_for_migrated' ), 10, 2 );

		// Block auto-renewal toggle.
		add_filter( 'woocommerce_can_subscription_be_updated_to', array( $this, 'prevent_auto_renewal_for_migrated' ), 10, 3 );

		// Add admin notices and UI modifications.
		add_action( 'admin_notices', array( $this, 'add_migration_notice' ) );
		add_action( 'admin_footer', array( $this, 'hide_renewal_buttons' ) );
	}

	/**
	 * Check if subscription is migrated.
	 *
	 * @param int|\WC_Subscription $subscription Subscription ID or object.
	 * @return bool True if migrated, false otherwise.
	 */
	private function is_subscription_migrated( $subscription ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return false;
		}

		if ( is_numeric( $subscription ) ) {
			$subscription = wcs_get_subscription( absint( $subscription ) );
		}

		if ( ! $subscription || ! is_a( $subscription, 'WC_Subscription' ) ) {
			return false;
		}

		// Check if subscription has _sublium_wcs_subscription_id meta.
		$sublium_id = $subscription->get_meta( '_sublium_wcs_subscription_id', true );
		return ! empty( $sublium_id );
	}

	/**
	 * Prevent scheduling single renewal actions for migrated subscriptions.
	 *
	 * @param int|null|false $pre_value Pre-filter value (null to continue, false/0 to prevent).
	 * @param int            $timestamp Timestamp when action should run.
	 * @param string         $hook      Action hook.
	 * @param array          $args      Action arguments.
	 * @param string         $group     Action group.
	 * @param int            $priority  Action priority.
	 * @param bool           $unique    Whether action should be unique.
	 * @return int|null|false Null to continue scheduling, 0/false to prevent.
	 */
	public function prevent_scheduling_single_renewal_action( $pre_value, $timestamp, $hook, $args, $group = '', $priority = 10, $unique = false ) {
		return $this->check_and_prevent_renewal_action( $pre_value, $hook, $args );
	}

	/**
	 * Prevent scheduling recurring renewal actions for migrated subscriptions.
	 *
	 * @param int|null|false $pre_value Pre-filter value (null to continue, false/0 to prevent).
	 * @param int            $timestamp Timestamp when action should run.
	 * @param int            $interval  Interval in seconds.
	 * @param string         $hook      Action hook.
	 * @param array          $args      Action arguments.
	 * @param string         $group     Action group.
	 * @param int            $priority  Action priority.
	 * @param bool           $unique    Whether action should be unique.
	 * @return int|null|false Null to continue scheduling, 0/false to prevent.
	 */
	public function prevent_scheduling_recurring_renewal_action( $pre_value, $timestamp, $interval, $hook, $args, $group = '', $priority = 10, $unique = false ) {
		return $this->check_and_prevent_renewal_action( $pre_value, $hook, $args );
	}

	/**
	 * Check if renewal action should be prevented for migrated subscriptions.
	 *
	 * @param int|null|false $pre_value Pre-filter value (null to continue, false/0 to prevent).
	 * @param string         $hook      Action hook.
	 * @param array          $args      Action arguments.
	 * @return int|null|false Null to continue scheduling, 0/false to prevent.
	 */
	private function check_and_prevent_renewal_action( $pre_value, $hook, $args ) {
		// If already filtered, return as-is.
		if ( null !== $pre_value ) {
			return $pre_value;
		}

		// Check if this is a WCS renewal-related hook.
		$wcs_renewal_hooks = array(
			'woocommerce_scheduled_subscription_payment',
			'woocommerce_scheduled_subscription_expiration',
			'woocommerce_scheduled_subscription_trial_end',
			'woocommerce_scheduled_subscription_end_of_prepaid_term',
		);

		if ( ! in_array( $hook, $wcs_renewal_hooks, true ) ) {
			return null; // Continue with normal scheduling.
		}

		// Extract subscription ID from args.
		$subscription_id = 0;
		if ( ! empty( $args ) && is_array( $args ) ) {
			// WCS typically passes subscription_id as first arg.
			if ( isset( $args[0] ) && is_numeric( $args[0] ) ) {
				$subscription_id = absint( $args[0] );
			} elseif ( isset( $args['subscription_id'] ) ) {
				$subscription_id = absint( $args['subscription_id'] );
			}
		}

		if ( empty( $subscription_id ) ) {
			return null; // Continue with normal scheduling if we can't determine subscription ID.
		}

		// Check if subscription is migrated.
		if ( $this->is_subscription_migrated( $subscription_id ) ) {
			// Log blocked attempt.
			$this->log_blocked_renewal( $subscription_id, 'action_scheduler_prevented' );

			// Return 0 to prevent scheduling (Action Scheduler expects 0 or false to prevent).
			return 0;
		}

		return null; // Continue with normal scheduling.
	}

	/**
	 * Block scheduled subscription renewal payment.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return void
	 */
	public function block_migrated_subscription_renewal( $subscription_id ) {
		if ( ! $this->is_subscription_migrated( $subscription_id ) ) {
			return;
		}

		// Log blocked attempt.
		$this->log_blocked_renewal( $subscription_id, 'scheduled_payment' );

		// Remove the action to prevent WCS from processing it.
		remove_action( 'woocommerce_scheduled_subscription_payment', 'WC_Subscriptions_Manager::prepare_renewal', 10 );
	}


	/**
	 * Prevent manual renewal order creation from admin order edit screen.
	 *
	 * @param \WC_Order|bool|\WP_Error $renewal_order Renewal order or false/error.
	 * @param \WC_Subscription          $subscription  Subscription object.
	 * @return \WC_Order|bool|\WP_Error Renewal order or error.
	 */
	public function prevent_manual_renewal_order_for_migrated( $renewal_order, $subscription ) {
		if ( ! $this->is_subscription_migrated( $subscription ) ) {
			return $renewal_order;
		}

		// Log blocked attempt.
		$this->log_blocked_renewal( $subscription->get_id(), 'manual_renewal_order_creation' );

		// Add admin notice.
		add_action( 'admin_notices', function() use ( $subscription ) {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					printf(
						/* translators: %d: Subscription ID */
						esc_html__( 'Cannot create renewal order: Subscription #%d has been migrated to Sublium. Renewals are now handled by Sublium.', 'wcs-sublium-migrator' ),
						esc_html( $subscription->get_id() )
					);
					?>
				</p>
			</div>
			<?php
		} );

		// Return WP_Error to prevent order creation.
		return new \WP_Error(
			'migrated_subscription',
			__( 'This subscription has been migrated to Sublium. Renewals are now handled by Sublium.', 'wcs-sublium-migrator' )
		);
	}

	/**
	 * Block manual renewal order processing.
	 *
	 * @param int            $order_id      Order ID.
	 * @param \WC_Subscription $subscription Subscription object.
	 * @return void
	 */
	public function block_manual_renewal_processing( $order_id, $subscription ) {
		if ( ! $this->is_subscription_migrated( $subscription ) ) {
			return;
		}

		// Log blocked attempt.
		$this->log_blocked_renewal( $subscription->get_id(), 'manual_renewal_processing' );

		// Prevent further processing by removing hooks.
		remove_all_actions( 'woocommerce_order_status_changed' );
	}

	/**
	 * Prevent early renewal for migrated subscriptions.
	 *
	 * @param bool            $allowed      Whether early renewal is allowed.
	 * @param \WC_Subscription $subscription Subscription object.
	 * @return bool False if migrated, original value otherwise.
	 */
	public function prevent_early_renewal_for_migrated( $allowed, $subscription ) {
		if ( ! $this->is_subscription_migrated( $subscription ) ) {
			return $allowed;
		}

		// Log blocked attempt.
		$this->log_blocked_renewal( $subscription->get_id(), 'early_renewal' );

		return false;
	}

	/**
	 * Prevent auto-renewal toggle for migrated subscriptions.
	 *
	 * @param bool            $can_update   Whether subscription can be updated.
	 * @param \WC_Subscription $subscription Subscription object.
	 * @param string          $new_status   New status.
	 * @return bool False if trying to enable auto-renewal on migrated subscription.
	 */
	public function prevent_auto_renewal_for_migrated( $can_update, $subscription, $new_status ) {
		if ( ! $this->is_subscription_migrated( $subscription ) ) {
			return $can_update;
		}

		// Block enabling auto-renewal (active status) for migrated subscriptions.
		if ( 'active' === $new_status && ! $subscription->is_manual() ) {
			// Log blocked attempt.
			$this->log_blocked_renewal( $subscription->get_id(), 'auto_renewal_toggle' );
			return false;
		}

		return $can_update;
	}

	/**
	 * Add admin notice on subscription edit screen.
	 *
	 * @return void
	 */
	public function add_migration_notice() {
		global $post;

		if ( ! $post || 'shop_subscription' !== $post->post_type ) {
			return;
		}

		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $post->ID );
		if ( ! $subscription || ! $this->is_subscription_migrated( $subscription ) ) {
			return;
		}

		$sublium_id = $subscription->get_meta( '_sublium_wcs_subscription_id', true );
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Migrated Subscription', 'wcs-sublium-migrator' ); ?></strong>
				<?php
				printf(
					/* translators: %d: Sublium subscription ID */
					esc_html__( 'This subscription has been migrated to Sublium (Subscription #%d). Renewals are now handled by Sublium and cannot be processed from WooCommerce Subscriptions.', 'wcs-sublium-migrator' ),
					esc_html( $sublium_id )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Hide renewal buttons on subscription edit screen.
	 *
	 * @return void
	 */
	public function hide_renewal_buttons() {
		global $post;

		if ( ! $post || 'shop_subscription' !== $post->post_type ) {
			return;
		}

		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $post->ID );
		if ( ! $subscription || ! $this->is_subscription_migrated( $subscription ) ) {
			return;
		}

		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Hide "Create Renewal Order" button.
			$('a[href*="create_renewal_order"], button[data-action="create_renewal_order"]').hide();

			// Hide early renewal button.
			$('a[href*="early_renewal"], button[data-action="early_renewal"]').hide();

			// Disable auto-renewal toggle.
			$('.subscription-auto-renew-toggle').prop('disabled', true).addClass('disabled');
		});
		</script>
		<?php
	}

	/**
	 * Log blocked renewal attempt.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $type            Block type.
	 * @return void
	 */
	private function log_blocked_renewal( $subscription_id, $type ) {
		$state = new State();
		$current_state = $state->get_state();

		if ( ! isset( $current_state['blocked_renewals'] ) ) {
			$current_state['blocked_renewals'] = array();
		}

		$current_state['blocked_renewals'][] = array(
			'subscription_id' => absint( $subscription_id ),
			'type'            => sanitize_text_field( $type ),
			'timestamp'       => current_time( 'mysql' ),
		);

		$state->update_state( $current_state );
	}
}
