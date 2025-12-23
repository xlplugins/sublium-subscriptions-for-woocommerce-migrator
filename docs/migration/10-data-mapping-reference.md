# Data Mapping Reference

## Subscription Status Mapping

| WCS Status | Sublium Status | Status Code |
|------------|----------------|-------------|
| `wc-active` | ACTIVE | `3` |
| `wc-on-hold` | ONHOLD | `4` |
| `wc-pending-cancel` | PENDING_CANCEL | `10` |
| `wc-cancelled` | CANCELLED | `9` |
| `wc-expired` | COMPLETED | `8` |
| `wc-pending` | PENDING | `1` |
| `wc-switched` | CANCELLED | `9` (Original cancelled) |

## Billing Period Mapping

| WCS Period | WCS Interval | Sublium Frequency | Sublium Interval |
|------------|--------------|-------------------|------------------|
| `day` | `1` | `1` | `1` (days) |
| `week` | `1` | `1` | `2` (weeks) |
| `month` | `1` | `1` | `3` (months) |
| `month` | `3` | `3` | `3` (months) |
| `year` | `1` | `1` | `4` (years) |

**Sublium Interval Values:**
- `1` = Days
- `2` = Weeks
- `3` = Months
- `4` = Years

## Payment Gateway Mapping

| WCS Gateway ID | Sublium Gateway ID | Compatible | Notes |
|----------------|-------------------|------------|-------|
| `stripe` | `fkwcs_stripe` | ✅ Yes | Direct mapping |
| `stripe_cc` | `fkwcs_stripe` | ✅ Yes | Maps to FunnelKit Stripe |
| `ppec_paypal` | `fkwcppcp_paypal` | ✅ Yes | Maps to FunnelKit PayPal |
| `paypal` | `fkwcppcp_paypal` | ⚠️ Partial | May need re-authorization |
| `ppcp-gateway` | `fkwcppcp_paypal` | ✅ Yes | Maps to FunnelKit PayPal |
| `square_credit_card` | `fkwcsq_square` | ✅ Yes | Maps to FunnelKit Square |
| `bacs` | `bacs` | ✅ Yes | Manual renewal |
| `cheque` | `cheque` | ✅ Yes | Manual renewal |
| `cod` | `cod` | ✅ Yes | Manual renewal |
| `` (empty) | `` (empty) | ✅ Yes | Manual renewal |
| `authorize_net_cim_credit_card` | - | ❌ No | Not supported in Sublium |
| `braintree_credit_card` | - | ❌ No | Not supported in Sublium |
| `woocommerce_payments` | - | ❌ No | Not supported in Sublium |

**Gateway Discovery Process:**
1. Scan all active WCS subscriptions for `_payment_method` meta
2. Count subscriptions per gateway
3. Map each WCS gateway to Sublium gateway
4. Check if Sublium gateway is available/enabled
5. Show compatibility status and warnings

## Payment Gateway Token Mapping

| Gateway ID | Subscription Meta Keys | User Meta Keys | Notes |
|------------|----------------------|----------------|-------|
| `stripe` | `_stripe_customer_id`, `_stripe_source_id` | - | Both required for auto-renewal |
| `paypal` | `_paypal_subscription_id` | `_paypal_subscription_id` | Check both subscription and user meta |
| `ppec_paypal` | - | `_paypal_subscription_id` | Check user meta only |
| `authorize_net_cim_credit_card` | `_authorize_net_cim_customer_profile_id`, `_authorize_net_cim_payment_profile_id` | - | Both required |
| `braintree_credit_card` | `_braintree_customer_id`, `_braintree_credit_card_payment_token` | - | Both required |
| `woocommerce_payments` | `_wcpay_customer_id`, `_wcpay_payment_method_id` | - | Payment method ID critical |
| `square_credit_card` | `_square_customer_id`, `_square_card_id` | - | Both required |
| `bacs`, `cheque`, `cod`, `` (empty) | (none) | - | Manual renewal - no tokens |

**Token Validation Function:**
```php
/**
 * Check if subscription has valid payment token
 *
 * @param WC_Subscription $subscription Subscription object
 * @return bool|null True if valid, false if invalid, null if unknown gateway
 */
function wcs_subscription_has_valid_payment_token($subscription) {
    $payment_method = $subscription->get_payment_method();

    if (empty($payment_method)) {
        return false;
    }

    // Manual methods don't need tokens
    $manual_methods = ['bacs', 'cheque', 'cod'];
    if (in_array($payment_method, $manual_methods)) {
        return true; // Valid but manual
    }

    // Check for gateway-specific tokens
    switch ($payment_method) {
        case 'stripe':
            return !empty($subscription->get_meta('_stripe_customer_id'));

        case 'paypal':
        case 'ppec_paypal':
            $sub_meta = $subscription->get_meta('_paypal_subscription_id');
            $user_meta = get_user_meta($subscription->get_customer_id(), '_paypal_subscription_id', true);
            return !empty($sub_meta) || !empty($user_meta);

        case 'woocommerce_payments':
            return !empty($subscription->get_meta('_wcpay_payment_method_id'));

        case 'square_credit_card':
            return !empty($subscription->get_meta('_square_customer_id'));

        default:
            // Unknown gateway - assume needs checking
            return null;
    }
}
```

## Plan Type Mapping

**CRITICAL RULE:**
- **Virtual Products** → **Type 2 (Recurring Plan)**
- **Physical Products** → **Type 1 (Subscribe & Save Plan)**

| WCS Product Type | Virtual Status | Sublium Plan Type | Plan Type Name |
|-----------------|----------------|-------------------|----------------|
| `subscription` | Virtual (`yes`) | `2` | Recurring |
| `subscription` | Physical (`no`) | `1` | Subscribe & Save |
| `variable-subscription` | All variations virtual | `2` | Recurring |
| `variable-subscription` | Any variation physical | `1` | Subscribe & Save |
| Any (with custom meta) | Any | `3` | Installments (rare) |

**Mapping Rules:**

1. **Primary Rule**:
   - `$product->is_virtual() === true` → **Type 2 (Recurring)**
   - `$product->is_virtual() === false` → **Type 1 (Subscribe & Save)**
2. **Variable Products**: Check variation's virtual status (not parent product)
3. **Installments (Type 3)**: Only if explicitly indicated via custom meta or extension
4. **Fallback**: If unable to determine, default to Type 2 (Recurring)

**Implementation Logic:**
```php
if ($product->is_virtual()) {
    $plan_type = 2; // Recurring - for virtual products
} else {
    $plan_type = 1; // Subscribe & Save - for physical products
}
```

## Signup Fee Mapping

**WCS Format:** Decimal (e.g., `49.99`)

**Sublium Format:** JSON
```json
{
  "signup_fee_type": "fixed",
  "signup_amount": "49.99"
}
```

## Free Trial Mapping

**WCS Format:**
- `_subscription_trial_length` (int, e.g., `7`, `14`, `30`)
- `_subscription_trial_period` (string: `day`, `week`, `month`, `year`)

**Sublium Format:** `free_trial` (TINYINT - number of days)

**Conversion:**
- `day` → days as-is
- `week` → days × 7
- `month` → days × 30 (approximate)
- `year` → days × 365 (approximate)

## Subscription Meta Data Mapping

### WCS Subscription ID Storage

**Meta Key:** `wcs_subscription_id`

**Purpose:** Maintains relationship between Sublium and WooCommerce Subscriptions

**Storage:** Stored in `wp_sublium_wcs_subscription_meta` table

**Usage:**
- Track original WCS subscription
- Maintain bidirectional relationship
- Enable cross-referencing between systems

**Retrieval:**
```php
$sublium_subscription->get_meta( 'wcs_subscription_id' );
```

### Plan Data Structure

**Meta Key:** `plan_data`

**Purpose:** Stores complete plan configuration for recurring payments (when `plan_id` is `0`)

**Structure:**
```php
array(
    'plan_id' => 0, // Always 0 for migrated subscriptions
    'type' => 1|2, // Plan type
    'billing_frequency' => 1,
    'billing_interval' => 1|2|3|4,
    'billing_length' => 0,
    'free_trial' => 0,
    'signup_fee' => array(
        'signup_fee_type' => 'fixed',
        'signup_amount' => '49.99'
    ),
    'relation_data' => array(
        'regular_price' => '29.99',
        'sale_price' => '0.00',
        // ... other relation data
    ),
    // ... other plan fields
)
```

**Note:** For migrated subscriptions, `plan_id` is set to `0` and `plan_data` contains the full plan configuration in meta.

## Parent Order ID Mapping

**WCS Field:** `WC_Subscription::get_id()` (subscription ID)

**Sublium Field:** `parent_order_id`

**Mapping:** Stores WCS subscription ID (not parent order ID) for better traceability

**Rationale:**
- Direct reference to source WCS subscription
- Easier to trace back to original subscription
- Maintains clear relationship between systems

## Order Linking Meta

### Parent Order

**Meta Key:** `_sublium_wcs_subscription_id`

**Value:** Sublium subscription ID

**Location:** Parent order meta (HPOS: `wp_wc_orders_meta`, CPT: `wp_postmeta`)

### Renewal Orders

**Meta Key:** `_sublium_wcs_subscription_id`

**Value:** Sublium subscription ID

**Additional Meta:** `_sublium_wcs_subscription_renewal` = `'yes'`

**Location:** Renewal order meta (HPOS: `wp_wc_orders_meta`, CPT: `wp_postmeta`)

**Implementation:** Uses direct database queries for reliability across HPOS and CPT modes
