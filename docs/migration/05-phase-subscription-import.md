# Phase 5: Subscription Import Layer

## Overview

This phase migrates subscriptions from WCS to Sublium, linking them to plans created in Phase 4.

## Files to Create

- `includes/migration/importers/class-base-importer.php` - Base importer
- `includes/migration/importers/class-subscription-importer.php` - Import subscriptions
- `includes/migration/importers/class-item-importer.php` - Import line items

## Import Process

### 1. Plan Lookup (using mapping from Phase 4)

- Get product_id and variation_id from subscription line item
- **Verify product is migratable:**
  - Check if product has `_product_type = 'subscription'` or `'variable-subscription'` (native)
  - **OR** check if product has `_wcsatt_schemes` meta AND WCS_ATT plugin is active
  - Skip subscription if product doesn't meet either condition (log warning)
- Lookup plan_id from product_plan_mapping
- Verify plan exists (fail gracefully if not found)

### 2. Subscription Creation

- Insert into `wp_sublium_wcs_subscriptions`
- Set all dates (start, next_payment, trial_end, end)
- **Trial End Date Handling:**
  - Extract `_schedule_trial_end` from WCS subscription
  - Convert GMT to UTC format
  - Store in `trial_end_date_utc` field
  - If trial already ended, set `trial_end_date` accordingly
- Set billing schedule (period, interval)
- Set payment info (gateway, mode)
- Set totals and currency
- **Note:** Signup fees are handled at order level in WCS, not subscription level
  - Signup fee was charged in parent order
  - No need to store signup fee in subscription record
  - Plan already has signup fee configuration for future subscriptions

### 3. Line Items Import

- Insert into `wp_sublium_wcs_subscription_items`
- Link to subscription
- Preserve product IDs, quantities, prices
- Preserve line item meta data

### 4. Meta Data Import

- Insert payment tokens into `wp_sublium_wcs_subscription_meta`
- Preserve gateway-specific tokens
- Store original WCS subscription ID for reference (`_wcs_original_subscription_id`)

### 5. Activity Logging

- Create activity entry: "Migrated from WooCommerce Subscriptions"
- Store migration metadata
- Set user_type as 'system'

## Key Methods

```php
import_subscription($wcs_subscription_id) - Import single subscription
import_subscriptions_batch($limit, $offset) - Batch import
lookup_plan_for_product($product_id, $variation_id) - Get plan_id from mapping
create_subscription_record($subscription_data) - Insert subscription into database
import_subscription_items($subscription_id, $line_items) - Import line items
import_subscription_meta($subscription_id, $meta_data) - Import meta data
log_migration_activity($subscription_id, $message) - Create activity log entry
```

## Data Mapping

- WCS subscription → Sublium subscription record
- WCS line items → Sublium subscription_items records
- WCS payment meta → Sublium subscription_meta records
- WCS dates → Sublium UTC dates
- WCS status → Sublium status (mapped)

## Error Handling

- Skip subscriptions with non-migratable products (log warning)
- Skip subscriptions without matching plans (log error)
- Continue processing on individual failures
- Track failed subscriptions for retry
