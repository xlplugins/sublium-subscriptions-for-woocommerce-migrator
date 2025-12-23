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

1. **Migration Controller** (`includes/migration/scheduler.php`)
   - Main orchestrator for migration process
   - Handles scheduled batch processing via WordPress cron
   - Manages migration state and progress

2. **Discovery** (`includes/migration/discovery.php`)
   - Analyzes WCS data before migration
   - Checks gateway compatibility
   - Provides feasibility assessment
   - Shows breakdown by payment gateways and product types

3. **Processors**
   - `includes/migration/products-processor.php` - Migrates products and creates plans
   - `includes/migration/subscriptions-processor.php` - Migrates subscriptions with plan_data

4. **State Management** (`includes/migration/state.php`)
   - Tracks migration progress
   - Stores errors and warnings
   - Manages migration status

5. **Admin Interface** (`includes/admin/admin.php`)
   - Admin menu and UI
   - Migration control buttons
   - Real-time progress display
   - Gateway compatibility warnings

6. **REST API** (`includes/api/migration-api.php`)
   - REST endpoints for migration control
   - Status and discovery endpoints
   - AJAX handlers for admin UI

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
