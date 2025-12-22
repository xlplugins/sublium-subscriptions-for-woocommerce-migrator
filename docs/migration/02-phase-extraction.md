# Phase 2: Data Extraction Layer

## Files to Create

- `includes/migration/extractors/class-base-extractor.php` - Base extractor class
- `includes/migration/extractors/class-subscription-extractor.php` - Extract WCS subscriptions
- `includes/migration/extractors/class-product-extractor.php` - Extract subscription products (native + WCS_ATT)
- `includes/migration/extractors/class-payment-extractor.php` - Extract payment methods

## Subscription Extractor Methods

```php
extract_subscription($subscription_id) - Extract single subscription
extract_subscriptions_batch($limit, $offset) - Batch extraction
extract_subscription_dates($subscription) - Extract schedule dates
extract_subscription_line_items($subscription) - Extract line items
extract_subscription_payment_data($subscription) - Extract payment tokens
extract_subscription_addresses($subscription) - Extract billing/shipping
```

## Product Extractor Methods

```php
extract_product_subscription_settings($product_id, $is_wcsatt = false) - Get product settings
extract_wcsatt_scheme_settings($product_id) - Extract settings from WCS_ATT schemes
extract_native_subscription_settings($product_id) - Extract from native subscription product meta
check_is_wcsatt_product($product_id) - Check if product has WCS_ATT schemes
```

## Data Structure Extracted

- Basic info (ID, customer, status, dates)
- Billing schedule (period, interval, trial)
- Payment method (gateway ID, tokens)
- Financial data (totals, tax, shipping, currency)
- Addresses (billing, shipping)
- Line items (products, quantities, prices)

## Extraction Details

### Subscription Data

- Subscription ID, order number, customer ID
- Status, creation date
- Billing period and interval
- Trial period and length
- Schedule dates (start, trial_end, next_payment, end, cancelled)
- Payment method and gateway tokens
- Financial totals (total, tax, shipping, currency)
- Billing and shipping addresses
- Line items with product IDs, quantities, prices

### Product Data

- Product ID and variation ID
- Product type (subscription, variable-subscription, or WCS_ATT)
- Subscription price
- Billing period and interval
- Subscription length
- Signup fee
- Trial length and period
- Product virtual status (for plan type determination)

### Payment Data

- Gateway ID
- Gateway-specific tokens (Stripe, PayPal, Square, etc.)
- Manual renewal flag
- Payment method title

### Payment Token Extraction by Gateway

**Stripe:**
```php
$tokens = [
    'customer_id' => $subscription->get_meta('_stripe_customer_id'),
    'source_id' => $subscription->get_meta('_stripe_source_id'),
];
```

**PayPal:**
```php
$tokens = [
    'subscription_id' => $subscription->get_meta('_paypal_subscription_id'),
    // Also check user meta
    'billing_agreement' => get_user_meta(
        $subscription->get_customer_id(),
        '_paypal_subscription_id',
        true
    ),
];
```

**Authorize.net:**
```php
$tokens = [
    'customer_profile_id' => $subscription->get_meta('_authorize_net_cim_customer_profile_id'),
    'payment_profile_id' => $subscription->get_meta('_authorize_net_cim_payment_profile_id'),
];
```

**Braintree:**
```php
$tokens = [
    'customer_id' => $subscription->get_meta('_braintree_customer_id'),
    'token' => $subscription->get_meta('_braintree_credit_card_payment_token'),
];
```

**WooCommerce Payments:**
```php
$tokens = [
    'customer_id' => $subscription->get_meta('_wcpay_customer_id'),
    'payment_method_id' => $subscription->get_meta('_wcpay_payment_method_id'),
];
```

**Square:**
```php
$tokens = [
    'customer_id' => $subscription->get_meta('_square_customer_id'),
    'card_id' => $subscription->get_meta('_square_card_id'),
];
```

**Manual Methods (bacs, cheque, cod):**
```php
$tokens = []; // No tokens needed
```

## Complete Extraction Function Example

```php
/**
 * Extract all data from a WooCommerce Subscription for migration
 *
 * @param int $subscription_id Subscription ID
 * @return array|null Extracted subscription data or null if not found
 */
function extract_wcs_subscription_for_migration($subscription_id) {
    $subscription = wcs_get_subscription($subscription_id);

    if (!$subscription) {
        return null;
    }

    return [
        // Identity
        'id' => $subscription->get_id(),
        'order_number' => $subscription->get_order_number(),
        'status' => $subscription->get_status(),
        'customer_id' => $subscription->get_customer_id(),

        // Schedule
        'billing_period' => $subscription->get_billing_period(),
        'billing_interval' => $subscription->get_billing_interval(),
        'trial_period' => $subscription->get_trial_period(),
        'trial_length' => $subscription->get_trial_length(),

        // Dates (GMT)
        'dates' => [
            'created' => $subscription->get_date_created() ? $subscription->get_date_created()->format('Y-m-d H:i:s') : null,
            'start' => $subscription->get_date('start'),
            'trial_end' => $subscription->get_date('trial_end'),
            'next_payment' => $subscription->get_date('next_payment'),
            'end' => $subscription->get_date('end'),
            'cancelled' => $subscription->get_date('cancelled'),
            'last_payment' => $subscription->get_date('last_order_date_created'),
        ],

        // Payment
        'payment' => [
            'method' => $subscription->get_payment_method(),
            'method_title' => $subscription->get_payment_method_title(),
            'is_manual' => $subscription->is_manual(),
            'tokens' => extract_payment_tokens($subscription),
        ],

        // Financial
        'financial' => [
            'currency' => $subscription->get_currency(),
            'total' => $subscription->get_total(),
            'subtotal' => $subscription->get_subtotal(),
            'tax_total' => $subscription->get_total_tax(),
            'shipping_total' => $subscription->get_shipping_total(),
            'discount_total' => $subscription->get_discount_total(),
        ],

        // Addresses
        'billing_address' => $subscription->get_address('billing'),
        'shipping_address' => $subscription->get_address('shipping'),

        // Line Items
        'items' => array_map(function($item) {
            return [
                'item_id' => $item->get_id(),
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'subtotal' => $item->get_subtotal(),
                'subtotal_tax' => $item->get_subtotal_tax(),
                'total' => $item->get_total(),
                'total_tax' => $item->get_total_tax(),
                'tax_class' => $item->get_tax_class(),
                'meta_data' => $item->get_meta_data(),
            ];
        }, array_values($subscription->get_items())),

        // Shipping Items
        'shipping_items' => array_map(function($item) {
            return [
                'item_id' => $item->get_id(),
                'method_id' => $item->get_method_id(),
                'method_title' => $item->get_method_title(),
                'total' => $item->get_total(),
                'total_tax' => $item->get_total_tax(),
            ];
        }, array_values($subscription->get_shipping_methods())),

        // Fee Items
        'fee_items' => array_map(function($item) {
            return [
                'item_id' => $item->get_id(),
                'name' => $item->get_name(),
                'total' => $item->get_total(),
                'total_tax' => $item->get_total_tax(),
            ];
        }, array_values($subscription->get_fees())),

        // Extra
        'suspension_count' => $subscription->get_suspension_count(),
        'requires_manual_renewal' => $subscription->get_requires_manual_renewal(),
        'parent_order_id' => $subscription->get_parent_id(),
    ];
}
```
