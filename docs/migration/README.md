# WooCommerce Subscriptions to Sublium Migration Tool

## Overview

This migration tool migrates subscriptions from WooCommerce Subscriptions (WCS) to Sublium. The migration extracts data from WCS's `shop_subscription` post type and meta tables, transforms it to match Sublium's database schema, and imports it into Sublium's custom tables.

## Architecture

### Data Flow

```
WooCommerce Subscriptions (Source)
├── wp_posts (shop_subscription)
├── wp_postmeta (_billing_period, _schedule_next_payment, etc.)
├── wp_woocommerce_order_items
└── wp_woocommerce_order_itemmeta
         ↓
    [Migration Tool]
         ↓
Sublium (Target)
├── wp_sublium_wcs_subscriptions
├── wp_sublium_wcs_subscription_items
├── wp_sublium_wcs_subscription_meta
├── wp_sublium_wcs_subscription_activity
└── wp_sublium_wcs_plan
```

### Key Components

1. **Migration Controller** (`includes/migration/class-wcs-migrator.php`)
   - Main orchestrator for migration process
   - Handles batch processing
   - Manages migration state

2. **Data Extractors**
   - `includes/migration/extractors/class-subscription-extractor.php` - Extract WCS subscriptions
   - `includes/migration/extractors/class-product-extractor.php` - Extract subscription products (native + WCS_ATT)
   - `includes/migration/extractors/class-payment-extractor.php` - Extract payment methods

3. **Data Transformers**
   - `includes/migration/transformers/class-subscription-transformer.php` - Transform WCS → Sublium format
   - `includes/migration/transformers/class-product-transformer.php` - Transform products
   - `includes/migration/transformers/class-payment-transformer.php` - Transform payment tokens

4. **Data Importers**
   - `includes/migration/importers/class-subscription-importer.php` - Import subscriptions
   - `includes/migration/importers/class-plan-importer.php` - Import/create plans

5. **Admin Interface**
   - `admin/app/pages/migration/` - React admin UI
   - Migration status dashboard
   - Progress tracking
   - Error reporting

6. **Validation & Logging**
   - `includes/migration/validators/class-migration-validator.php` - Validate migrated data
   - `includes/migration/loggers/class-migration-logger.php` - Log migration activities

## Migration Sequence

**Critical Order:**

1. **Discovery Phase** - Analyze WCS data
2. **Product/Plan Migration Phase** - Scan WCS subscription products → Create plans in Sublium
3. **Subscription Migration Phase** - Migrate subscriptions → Link to created plans

**Why this order?**

- Subscriptions require `plan_id` to be created
- Plans are created from product subscription settings
- Must have plans ready before migrating subscriptions

## Implementation Phases

- [Phase 1: Discovery & Analysis Tools](01-phase-discovery.md)
- [Phase 2: Data Extraction Layer](02-phase-extraction.md)
- [Phase 3: Data Transformation Layer](03-phase-transformation.md)
- [Phase 4: Product & Plan Migration](04-phase-plan-migration.md) ⚠️ **MUST HAPPEN FIRST**
- [Phase 5: Subscription Import Layer](05-phase-subscription-import.md)
- [Phase 6: Migration Controller & Batch Processing](06-phase-migration-controller.md)
- [Phase 7: Validation & Error Handling](07-phase-validation.md)
- [Phase 8: Admin UI](08-phase-admin-ui.md)
- [Phase 9: Safety Features](09-phase-safety.md)

## Reference Documentation

- [Data Mapping Reference](10-data-mapping-reference.md)
- [File Structure](11-file-structure.md)
- [Testing Strategy](12-testing-strategy.md)

## Additional Resources

- **[MIGRATION_TO_SUBLIUM.txt](../MIGRATION_TO_SUBLIUM.txt)** - Complete data extraction reference with SQL queries and PHP code examples
- **[DATABASE_SCHEMA.md](../DATABASE_SCHEMA.md)** - Sublium database schema reference
- **[MIGRATION_PLANNING.txt](../MIGRATION_PLANNING.txt)** - Original migration planning document

## Quick Start

1. Run discovery to analyze WCS data
2. Review gateway compatibility
3. Migrate plans (Stage 1)
4. Migrate subscriptions (Stage 2)
5. Validate migration results
