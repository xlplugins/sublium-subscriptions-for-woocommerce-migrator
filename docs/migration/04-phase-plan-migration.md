# Phase 4: Product & Plan Migration (MUST HAPPEN FIRST)

## Overview

This phase scans WCS subscription products and creates corresponding plans in Sublium. **This MUST complete before subscription migration** because subscriptions require `plan_id` to be created.

## Files to Create

- `includes/migration/importers/class-plan-importer.php` - Create plans from products
- `includes/migration/mappers/class-product-plan-mapper.php` - Map WCS products to Sublium plans

## Migration Process

### 1. Scan WCS Subscription Products

**Method 1: Native Subscription Products**
- Find all products with type `subscription` or `variable-subscription`

**Method 2: All Products for Subscriptions Plugin** (Conditional)
- **Only if:** "All Products for Subscriptions" plugin is active (`class_exists('WCS_ATT')`)
- **And:** Product has `_wcsatt_schemes` meta key present
- Find simple and variable products with WCS_ATT subscription schemes
- Extract subscription settings from `_wcsatt_schemes` meta

**Extract Product Subscription Settings:**
- For native products: Standard WCS meta keys (`_subscription_price`, etc.)
- For WCS_ATT products: Extract from `_wcsatt_schemes` meta (scheme data)
- `_subscription_price` or scheme price
- `_subscription_period` + `_subscription_period_interval` or scheme period
- `_subscription_length` or scheme length
- `_subscription_sign_up_fee` or scheme signup fee
- `_subscription_trial_length` + `_subscription_trial_period` or scheme trial
- For variable products: scan all variations

**Note:** Simple/variable products without WCS_ATT meta are NOT migrated

### 2. Determine Plan Type (Critical Mapping)

For each product/variation:
- Check product virtual status: `$product->is_virtual()`
- **Type 1 (Subscribe & Save)**: Physical products (`is_virtual() === false`)
- **Type 2 (Recurring)**: Virtual products (`is_virtual() === true`)
- **Type 3 (Installments)**: Rare - only if explicitly indicated via custom meta
- For variable products: Check variation's virtual status
- Default fallback: Type 2 (Recurring) if unable to determine

### 3. Create Plans in Sublium (with Duplicate Prevention)

For each unique product/variation combination:

**Check for existing plan** using multiple criteria:
- Primary: `product_id` + `variation_id` + `billing_frequency` + `billing_interval`
- Also check: `type` + `signup_fee` + `free_trial` (to avoid near-duplicates)

**Duplicate Detection Logic:**
```php
// Check if plan exists with same:
// - product_id + variation_id
// - billing_frequency + billing_interval
// - type (plan type)
// - signup_fee
// - free_trial
```

- If plan exists: Use existing plan_id
- If plan doesn't exist:
  - Determine plan type using `determine_plan_type()` method
  - Create plan in `wp_sublium_wcs_plan` table with correct `type` field
  - Map WCS billing period → Sublium interval
  - **Transform and store signup fee:**
    - Extract `_subscription_sign_up_fee` from product meta
    - Convert to Sublium JSON format: `{signup_fee_type: 'fixed', signup_amount: 'X.XX'}`
    - Store in `signup_fee` field (LONGTEXT, JSON encoded)
  - **Transform and store free trial:**
    - Extract `_subscription_trial_length` + `_subscription_trial_period` from product meta
    - Convert trial period to days (e.g., 1 month = 30 days)
    - Store in `free_trial` field (TINYINT - number of days)
  - Store plan settings (price, trial, signup fee, etc.)
- Store product_id → plan_id mapping for later use

**Important:** Multiple products with same billing settings will get separate plans (plans are product-specific)

### 4. Plan Mapping Storage

- Create mapping table/option: `wcs_product_plan_map`
- Format: `[product_id][variation_id] => plan_id`
- Used during subscription migration to link subscriptions to plans

## Subscription Product Meta Keys Reference

| Meta Key | Type | Description | Example |
|----------|------|-------------|---------|
| `_subscription_price` | decimal | Recurring price | `29.99` |
| `_subscription_period` | string | Billing unit | `day`, `week`, `month`, `year` |
| `_subscription_period_interval` | int | Billing frequency | `1`, `2`, `3` |
| `_subscription_length` | int | Total cycles (0=unlimited) | `0`, `12` |
| `_subscription_sign_up_fee` | decimal | One-time initial fee | `49.99` |
| `_subscription_trial_length` | int | Trial duration | `14` |
| `_subscription_trial_period` | string | Trial unit | `day`, `week`, `month` |
| `_subscription_one_time_shipping` | string | Ship only once | `yes`, `no` |
| `_subscription_limit` | string | Limit subscriptions | `no`, `active`, `any` |
| `_subscription_payment_sync_date` | mixed | Sync billing date | `0` or `['day' => 1]` |

## Key Methods

```php
scan_wcs_subscription_products() - Find all subscription products (native + WCS_ATT if plugin active)
scan_native_subscription_products() - Find native subscription products (subscription/variable-subscription types)
scan_wcsatt_products() - Find simple/variable products with WCS_ATT schemes (if plugin active)
extract_product_subscription_settings($product_id, $is_wcsatt = false) - Get product settings (including signup fee and trial)
extract_wcsatt_scheme_settings($product_id) - Extract subscription settings from WCS_ATT schemes
transform_signup_fee($wcs_signup_fee) - Convert WCS signup fee to Sublium JSON format
transform_trial_to_days($trial_length, $trial_period) - Convert WCS trial period to days
determine_plan_type($product, $variation_id = 0) - Determine plan type (1, 2, or 3)
check_plan_exists($product_id, $variation_id, $billing_settings) - Check for duplicate plan
create_plan_from_product($product_id, $variation_id = 0) - Create plan with correct type, signup fee, and trial
get_or_create_plan($product_id, $variation_id, $billing_settings) - Get existing or create new (with duplicate check)
build_product_plan_mapping() - Create product → plan mapping
```

## Complete Product Extraction Function

```php
/**
 * Extract subscription settings from a WooCommerce product
 *
 * @param int $product_id Product ID
 * @param bool $is_wcsatt Whether product uses WCS_ATT schemes
 * @return array|null Product subscription settings or null if not found
 */
function extract_wcs_product_subscription_settings($product_id, $is_wcsatt = false) {
    $product = wc_get_product($product_id);

    if (!$product) {
        return null;
    }

    $product_type = $product->get_type();

    // For WCS_ATT products, extract from schemes
    if ($is_wcsatt) {
        return extract_wcsatt_scheme_settings($product_id);
    }

    // Check if it's a native subscription product
    if (!in_array($product_type, ['subscription', 'variable-subscription', 'subscription_variation'])) {
        return null;
    }

    $settings = [
        // Product identity
        'id' => $product->get_id(),
        'name' => $product->get_name(),
        'sku' => $product->get_sku(),
        'type' => $product_type,
        'status' => $product->get_status(),

        // Subscription settings
        'subscription' => [
            'price' => get_post_meta($product_id, '_subscription_price', true),
            'period' => get_post_meta($product_id, '_subscription_period', true),
            'period_interval' => get_post_meta($product_id, '_subscription_period_interval', true),
            'length' => get_post_meta($product_id, '_subscription_length', true),
            'sign_up_fee' => get_post_meta($product_id, '_subscription_sign_up_fee', true),
            'trial_length' => get_post_meta($product_id, '_subscription_trial_length', true),
            'trial_period' => get_post_meta($product_id, '_subscription_trial_period', true),
            'one_time_shipping' => get_post_meta($product_id, '_subscription_one_time_shipping', true),
            'limit' => get_post_meta($product_id, '_subscription_limit', true),
            'payment_sync_date' => get_post_meta($product_id, '_subscription_payment_sync_date', true),
        ],

        // Standard WooCommerce fields
        'regular_price' => $product->get_regular_price(),
        'sale_price' => $product->get_sale_price(),
        'tax_status' => $product->get_tax_status(),
        'tax_class' => $product->get_tax_class(),
        'downloadable' => $product->is_downloadable(),
        'virtual' => $product->is_virtual(),
    ];

    // For variable subscriptions, get variations
    if ($product_type === 'variable-subscription') {
        $settings['variations'] = [];

        $variations = $product->get_children();
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $settings['variations'][] = [
                    'id' => $variation_id,
                    'sku' => $variation->get_sku(),
                    'attributes' => $variation->get_attributes(),
                    'subscription' => [
                        'price' => get_post_meta($variation_id, '_subscription_price', true),
                        'period' => get_post_meta($variation_id, '_subscription_period', true),
                        'period_interval' => get_post_meta($variation_id, '_subscription_period_interval', true),
                        'length' => get_post_meta($variation_id, '_subscription_length', true),
                        'sign_up_fee' => get_post_meta($variation_id, '_subscription_sign_up_fee', true),
                        'trial_length' => get_post_meta($variation_id, '_subscription_trial_length', true),
                        'trial_period' => get_post_meta($variation_id, '_subscription_trial_period', true),
                    ],
                    'regular_price' => $variation->get_regular_price(),
                    'sale_price' => $variation->get_sale_price(),
                    'stock_status' => $variation->get_stock_status(),
                    'virtual' => $variation->is_virtual(),
                ];
            }
        }
    }

    return $settings;
}
```

## Product Scanning Logic

See [README.md](README.md) for complete code examples.

## Plan Type Determination Logic

```php
/**
 * Determine Sublium plan type from WCS product
 *
 * @param WC_Product $product Product object
 * @param int $variation_id Variation ID (0 for simple products)
 * @return int Plan type (1=Subscribe & Save, 2=Recurring, 3=Installments)
 */
private function determine_plan_type($product, $variation_id = 0) {
    // Get the actual product/variation to check
    $check_product = $product;
    if ($variation_id > 0) {
        $check_product = wc_get_product($variation_id);
    }

    if (!$check_product) {
        return 2; // Default to Recurring
    }

    // Check virtual status
    $is_virtual = $check_product->is_virtual();

    // Type 2: Recurring (for virtual products)
    if ($is_virtual) {
        return 2;
    }

    // Type 1: Subscribe & Save (for physical products)
    return 1;
}
```

## Duplicate Prevention Logic

```php
/**
 * Check if plan already exists to prevent duplicates
 *
 * @param int $product_id Product ID
 * @param int $variation_id Variation ID (0 for simple products)
 * @param array $billing_settings Billing settings (frequency, interval, type, etc.)
 * @return int|false Existing plan ID or false if not found
 */
private function check_plan_exists($product_id, $variation_id, $billing_settings) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'sublium_wcs_plan';

    $query = $wpdb->prepare(
        "SELECT id FROM {$table_name}
        WHERE product_id = %d
        AND variation_id = %d
        AND frequency = %d
        AND interval = %d
        AND type = %d
        AND signup_fee = %f
        AND trial_days = %d
        LIMIT 1",
        $product_id,
        $variation_id,
        $billing_settings['frequency'],
        $billing_settings['interval'],
        $billing_settings['type'],
        $billing_settings['signup_fee'],
        $billing_settings['trial_days']
    );

    $plan_id = $wpdb->get_var($query);

    return $plan_id ? (int) $plan_id : false;
}
```

## Important Notes

- This phase MUST complete before subscription migration
- **Product Detection**: Two methods:
  1. **Native Subscription Products:** Products with `_product_type = 'subscription'` or `'variable-subscription'`
  2. **WCS_ATT Products (Conditional):** Simple/variable products with `_wcsatt_schemes` meta
     - **Only if:** "All Products for Subscriptions" plugin is active (`class_exists('WCS_ATT')`)
     - **And:** Product has `_wcsatt_schemes` meta key present
     - Extract subscription settings from scheme data
  3. **Excluded:** Products without subscription type AND without WCS_ATT meta (will be skipped)
- **Duplicate Prevention**: Check for existing plans before creating new ones
  - Check by: product_id + variation_id + billing_frequency + billing_interval + type + signup_fee + trial_days
  - Prevents creating duplicate plans for same product with same settings
  - Reuses existing plan if found
- Plans are product-specific (each product gets its own plan, even if billing settings match other products)
- Multiple subscriptions can reference the same plan
- Store mapping for fast lookup during subscription import
- Handle edge case: Same product scanned multiple times during migration (should reuse existing plan)
- **Critical**: Migrate native subscription products AND WCS_ATT products (if plugin active)
- **WCS_ATT Products**: Only migrate if plugin is active AND product has `_wcsatt_schemes` meta
- **Warning**: Subscriptions with products that are neither subscription type nor have WCS_ATT meta will be skipped
