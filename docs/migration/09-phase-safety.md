# Phase 9: Safety Features

## Critical Safety Measures

### 1. Pre-Migration Checks

- **Verify WooCommerce Subscriptions Plugin:**
  - Check if `woocommerce-subscriptions/woocommerce-subscriptions.php` is active
  - Verify WCS version compatibility
  - Show error if not found (migration cannot proceed)

- **Payment Gateway Discovery** (Must happen first):
  - Discover all payment gateways from active WCS subscriptions
  - Map WCS gateways to Sublium gateways
  - Check gateway compatibility
  - Show gateway mapping information in UI
  - Warn about incompatible/missing gateways

- Check database connectivity
- Verify Sublium tables exist
- Check for existing migrated subscriptions (prevent duplicates)

### 2. WCS Scheduled Actions Management

- Detect WCS scheduled renewal actions
- Warn admin before migration
- Option to cancel WCS actions after migration
- Prevent double-charging

**WCS Scheduled Actions to Cancel:**

| Action Hook | Description |
|-------------|-------------|
| `woocommerce_scheduled_subscription_payment` | Renewal payments (CRITICAL - prevents double-charging) |
| `woocommerce_scheduled_subscription_expiration` | Subscription expiration |
| `woocommerce_scheduled_subscription_trial_end` | Trial ending |
| `woocommerce_scheduled_subscription_end_of_prepaid_term` | Prepaid term end |

**Cancel Scheduled Actions Function:**
```php
/**
 * Cancel all WCS scheduled actions for a subscription
 *
 * @param int $subscription_id Subscription ID
 * @return int Number of actions cancelled
 */
function cancel_wcs_scheduled_actions($subscription_id) {
    $actions_cancelled = 0;

    $hooks = [
        'woocommerce_scheduled_subscription_payment',
        'woocommerce_scheduled_subscription_expiration',
        'woocommerce_scheduled_subscription_trial_end',
        'woocommerce_scheduled_subscription_end_of_prepaid_term',
    ];

    foreach ($hooks as $hook) {
        $cancelled = as_unschedule_all_actions($hook, ['subscription_id' => $subscription_id]);
        $actions_cancelled += $cancelled;
    }

    return $actions_cancelled;
}

/**
 * Detect all WCS scheduled actions across all subscriptions
 *
 * @return array Summary of scheduled actions
 */
function detect_wcs_scheduled_actions() {
    global $wpdb;

    $actions_table = $wpdb->prefix . 'actionscheduler_actions';
    $logs_table = $wpdb->prefix . 'actionscheduler_logs';

    $query = "
        SELECT
            a.hook,
            COUNT(*) as action_count,
            MIN(a.scheduled_date_gmt) as earliest_action,
            MAX(a.scheduled_date_gmt) as latest_action
        FROM {$actions_table} a
        WHERE a.hook IN (
            'woocommerce_scheduled_subscription_payment',
            'woocommerce_scheduled_subscription_expiration',
            'woocommerce_scheduled_subscription_trial_end',
            'woocommerce_scheduled_subscription_end_of_prepaid_term'
        )
        AND a.status = 'pending'
        GROUP BY a.hook
        ORDER BY action_count DESC
    ";

    return $wpdb->get_results($query, ARRAY_A);
}
```

### 3. Dry Run Mode

- Test migration without importing
- Show what would be migrated
- Validate transformations
- Generate dry-run report

### 4. Rollback Capability

- Store original WCS subscription IDs
- Allow deletion of migrated subscriptions
- Restore WCS scheduled actions if needed
- Track rollback operations

### 5. Duplicate Prevention

- Check if subscription already migrated (by original ID)
- Skip already migrated subscriptions
- Option to re-migrate with force flag
- Store migration metadata for tracking

## Safety Checks Implementation

```php
/**
 * Pre-migration safety checks
 *
 * @return array ['can_proceed' => bool, 'warnings' => array, 'errors' => array]
 */
public function run_pre_migration_checks() {
    $checks = array(
        'can_proceed' => true,
        'warnings' => array(),
        'errors' => array(),
    );

    // Check WCS plugin
    if (!class_exists('WC_Subscriptions')) {
        $checks['can_proceed'] = false;
        $checks['errors'][] = 'WooCommerce Subscriptions plugin not found';
    }

    // Check scheduled actions
    $scheduled_actions = $this->detect_wcs_scheduled_actions();
    if (!empty($scheduled_actions)) {
        $checks['warnings'][] = sprintf(
            'Found %d scheduled WCS renewal actions. These should be cancelled after migration.',
            count($scheduled_actions)
        );
    }

    // Check gateway compatibility
    $gateways = $this->discover_payment_gateways();
    foreach ($gateways as $gateway) {
        if (!$gateway['compatible']) {
            $checks['warnings'][] = sprintf(
                'Gateway %s is not compatible: %s',
                $gateway['wcs_gateway_id'],
                $gateway['message']
            );
        }
    }

    return $checks;
}
```

## Migration Safety Flow

1. **Pre-Migration:**
   - Run all safety checks
   - Show warnings and errors
   - Require admin confirmation
   - Option to run dry-run first

2. **During Migration:**
   - Save progress after each batch
   - Handle errors gracefully
   - Log all operations
   - Allow pause/resume

3. **Post-Migration:**
   - Run validation checks
   - Generate migration report
   - Option to rollback if needed
   - Cleanup WCS scheduled actions
