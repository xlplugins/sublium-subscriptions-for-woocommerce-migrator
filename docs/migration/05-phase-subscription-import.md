# Phase 5: Subscription Import Layer

## Overview

This phase migrates subscriptions from WCS to Sublium using `plan_data` directly in subscription meta (no actual plan creation required).

## Implementation File

- `includes/migration/subscriptions-processor.php` - Handles subscription migration

## Import Process

### 1. Gateway Compatibility Check

- **Pre-migration Warning**: Check gateway compatibility before starting migration
- If gateway is not supported by Sublium, show warning to admin
- Admin must confirm before proceeding with subscription migration
- Unsupported gateways are logged as warnings but migration continues

### 2. Subscription Data Extraction

- Extract subscription data from WCS subscription object
- Get product IDs and variation IDs from subscription items
- Extract billing information from product (not subscription object):
  - Use `WC_Subscriptions_Product::get_length()` for billing length
  - Use `WC_Subscriptions_Product::get_trial_length()` for trial length
  - Use `WC_Subscriptions_Product::get_trial_period()` for trial period
  - Use `WC_Subscriptions_Product::get_sign_up_fee()` for signup fee
- Create `plan_data` structure matching Sublium's plan format
- **Critical**: Store `plan_data` in subscription meta, set `plan_id` to `0`

### 3. Subscription Creation

- Create subscription using `Subscription::create()` with:
  - `plan_id` set to `array('0')` (indicates plan_data is used)
  - `plan_data` stored in `meta_data` array
  - `parent_order_id` set to **WCS subscription ID** (not parent order ID)
  - All dates preserved (created_at, next_payment_date, etc.)
- **Created Date Preservation**: After creation, directly update database to preserve original WCS `created_at` dates (since `Subscription::save()` overrides them during creation)

### 4. Line Items Import

- Add items using `Subscription::add_item()` with nested structure:
  ```php
  array(
      'item_type' => 1, // Product item
      'item_data' => array(
          'product_id' => ...,
          'variation_id' => ...,
          'quantity' => ...,
          'name' => ...,
          'subtotal' => ...,
          'total' => ...,
          // ... other item data
      )
  )
  ```
- Call `Subscription::update_items()` after adding all items to link them correctly

### 5. Meta Data Import

- Store `plan_data` in subscription meta (for recurring payments)
- Store `wcs_subscription_id` in subscription meta (for maintaining relationship)
- Store billing details, shipping details, payment method title
- Store trial end date if exists
- All meta stored in `wp_sublium_wcs_subscription_meta` table

### 6. Order Linking

- Link parent order and renewal orders to Sublium subscription
- Use direct database queries (not native WooCommerce functions) for reliability
- Add `_sublium_wcs_subscription_id` meta to parent order
- Add `_sublium_wcs_subscription_id` meta to all renewal orders
- Add `_sublium_wcs_subscription_renewal='yes'` meta to renewal orders
- Works with both HPOS and CPT modes

### 7. Activity Logging

- Create activity entry: "Migrated from WooCommerce Subscriptions - Subscription #X"
- Store migration metadata
- Set user_type as 'system'

## Key Methods

```php
process_batch($offset) - Process batch of subscriptions
migrate_subscription($wcs_subscription) - Migrate single subscription
extract_subscription_data($wcs_subscription) - Extract and transform subscription data
create_plan_data_from_subscription($wcs_subscription, $parent_order) - Create plan_data structure
add_subscription_items($sublium_subscription, $wcs_subscription) - Add line items
link_orders_to_sublium_subscription($wcs_subscription, $sublium_subscription_id) - Link orders via DB queries
is_gateway_supported_by_sublium($gateway_id) - Check gateway compatibility
```

## Data Mapping

- WCS subscription → Sublium subscription record
- WCS subscription ID → `parent_order_id` field (for traceability)
- WCS subscription ID → `wcs_subscription_id` meta field (for relationship)
- WCS line items → Sublium subscription_items records (nested structure)
- WCS product subscription settings → `plan_data` in subscription meta
- WCS dates → Sublium UTC dates (preserved via direct DB update)
- WCS status → Sublium status (mapped)
- WCS parent order → Linked via `_sublium_wcs_subscription_id` meta
- WCS renewal orders → Linked via `_sublium_wcs_subscription_id` meta + `_sublium_wcs_subscription_renewal='yes'`

## Plan Data Structure

Instead of creating actual plans, subscription migration stores `plan_data` directly in subscription meta:

```php
'plan_data' => array(
    'plan_id' => 0, // Always 0 for migrated subscriptions
    'type' => 1|2, // 1=Subscribe & Save, 2=Recurring
    'billing_frequency' => 1,
    'billing_interval' => 1|2|3|4, // 1=days, 2=weeks, 3=months, 4=years
    'billing_length' => 0, // 0=unlimited
    'free_trial' => 0, // Days
    'signup_fee' => array(...), // JSON structure
    'relation_data' => array(...), // Plan relation data
    // ... other plan fields
)
```

## Error Handling

- Check gateway compatibility before migration (show warning if incompatible)
- Skip subscriptions without parent order (log error)
- Skip subscriptions without valid product data (log error)
- Continue processing on individual failures
- Track failed subscriptions in migration state
- Log all errors with subscription ID and error message
