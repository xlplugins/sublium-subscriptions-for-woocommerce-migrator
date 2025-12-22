# Phase 3: Data Transformation Layer

## Files to Create

- `includes/migration/transformers/class-base-transformer.php` - Base transformer
- `includes/migration/transformers/class-subscription-transformer.php` - Transform subscriptions
- `includes/migration/transformers/class-product-transformer.php` - Transform products
- `includes/migration/transformers/class-payment-transformer.php` - Transform payment data

## Key Transformations

### WCS → Sublium Status Mapping

```php
'wc-active' → STATUSES['ACTIVE'] (3)
'wc-on-hold' → STATUSES['ONHOLD'] (4)
'wc-pending-cancel' → STATUSES['PENDING_CANCEL'] (10)
'wc-cancelled' → STATUSES['CANCELLED'] (9)
'wc-expired' → STATUSES['COMPLETED'] (8)
'wc-pending' → STATUSES['PENDING'] (1)
```

### Billing Period Mapping

```php
WCS: 'day', 'week', 'month', 'year'
Sublium: billing_frequency (int), billing_interval (1=days, 2=weeks, 3=months, 4=years)

Examples:
- 'day' + interval 1 → billing_frequency: 1, billing_interval: 1
- 'week' + interval 1 → billing_frequency: 1, billing_interval: 2
- 'month' + interval 1 → billing_frequency: 1, billing_interval: 3
- 'month' + interval 3 → billing_frequency: 3, billing_interval: 3
- 'year' + interval 1 → billing_frequency: 1, billing_interval: 4
```

### Date Transformation

- Convert WCS GMT dates to Sublium UTC format
- Handle both `next_payment_date` and `next_payment_date_utc`
- Preserve timezone accuracy
- **Trial End Date:** Extract `_schedule_trial_end` from WCS subscription and convert to UTC

### Signup Fee Transformation

WCS stores signup fee as decimal: `_subscription_sign_up_fee` (e.g., `49.99`)

Sublium stores signup fee as JSON:
```json
{
  "signup_fee_type": "fixed",
  "signup_amount": "49.99"
}
```

**Transformation Logic:**
```php
/**
 * Convert WCS signup fee to Sublium format
 *
 * @param float $wcs_signup_fee WCS signup fee (decimal)
 * @return array Sublium signup fee format
 */
private function transform_signup_fee($wcs_signup_fee) {
    $signup_fee = 0.00;

    if (!empty($wcs_signup_fee) && is_numeric($wcs_signup_fee) && $wcs_signup_fee > 0) {
        $signup_fee = (float) $wcs_signup_fee;
    }

    // Sublium stores as JSON with type and amount
    return array(
        'signup_fee_type' => 'fixed',  // WCS only supports fixed amounts
        'signup_amount' => (string) number_format($signup_fee, 2, '.', '')
    );
}
```

### Free Trial Transformation

WCS stores trial as:
- `_subscription_trial_length` (int, e.g., `7`, `14`, `30`)
- `_subscription_trial_period` (string: `day`, `week`, `month`, `year`)

Sublium stores trial as: `free_trial` (TINYINT - number of days)

**Transformation Logic:**
```php
/**
 * Convert WCS trial period to Sublium free_trial (days)
 *
 * @param int $trial_length WCS trial length
 * @param string $trial_period WCS trial period (day, week, month, year)
 * @return int Number of days for Sublium free_trial
 */
private function transform_trial_to_days($trial_length, $trial_period) {
    if (empty($trial_length) || $trial_length <= 0) {
        return 0;
    }

    $trial_length = (int) $trial_length;
    $trial_period = strtolower($trial_period);

    // Convert to days based on period
    switch ($trial_period) {
        case 'day':
            return $trial_length;
        case 'week':
            return $trial_length * 7;
        case 'month':
            return $trial_length * 30; // Approximate (30 days per month)
        case 'year':
            return $trial_length * 365; // Approximate (365 days per year)
        default:
            return 0;
    }
}
```

### Payment Token Mapping

- Map gateway-specific meta keys
- Preserve token IDs for same-site migration
- Handle manual renewal subscriptions

**Important Notes:**
- **Signup Fees:** Stored in plan configuration, not subscription record (signup fee was charged in parent order)
- **Free Trials:** Converted to days and stored in plan `free_trial` field
- **Trial End Dates:** Preserved in subscription `trial_end_date_utc` field for active trials
