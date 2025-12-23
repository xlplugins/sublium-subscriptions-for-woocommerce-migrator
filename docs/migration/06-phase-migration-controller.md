# Phase 6: Migration Controller & Batch Processing

## Files to Create

- `includes/migration/class-wcs-migrator.php` - Main migration controller
- `includes/migration/class-migration-state.php` - Track migration state
- `admin/app/api/migration.php` - REST API endpoints

## Migration Controller Features

### Two-Stage Migration Process

1. **Stage 1: Product/Plan Migration** (must complete first)
2. **Stage 2: Subscription Migration** (uses plans from Stage 1)

### Core Features

- Batch processing (configurable batch size, default: 50 subscriptions per batch)
- Progress tracking for both stages
- Error handling and recovery
- Rollback capability
- Resume from interruption

## State Management

Store migration state in `wp_options`:
- Track: total count, processed count, failed count
- Store last processed subscription ID
- Track migration start/end time
- Store current stage (plan migration or subscription migration)
- Store batch progress

## API Endpoints

```
GET  /wp-json/sublium/v1/migration/discovery              # Full discovery including gateways
GET  /wp-json/sublium/v1/migration/discovery/gateways     # Gateway discovery only
GET  /wp-json/sublium/v1/migration/discovery/wcs-status   # Check WCS plugin status
POST /wp-json/sublium/v1/migration/migrate-plans          # Stage 1: Migrate products → plans
POST /wp-json/sublium/v1/migration/migrate-subscriptions  # Stage 2: Migrate subscriptions
POST /wp-json/sublium/v1/migration/start                   # Start full migration (both stages)
GET  /wp-json/sublium/v1/migration/status                # Get migration status
POST /wp-json/sublium/v1/migration/pause                  # Pause migration
POST /wp-json/sublium/v1/migration/resume                 # Resume migration
POST /wp-json/sublium/v1/migration/rollback               # Rollback migration
```

## Migration State Structure

```php
array(
    'status' => 'idle' | 'products_migrating' | 'subscriptions_migrating' | 'completed' | 'paused',
    'products_migration' => array(
        'total_products' => 0,
        'processed_products' => 0,
        'created_plans' => 0,
        'failed_products' => 0,
        'last_product_id' => 0,
    ),
    'subscriptions_migration' => array(
        'total_subscriptions' => 0,
        'processed_subscriptions' => 0,
        'created_subscriptions' => 0,
        'failed_subscriptions' => 0,
        'last_subscription_id' => 0,
    ),
    'start_time' => '',
    'end_time' => '',
    'last_activity' => '',
    'errors' => array(),
    'progress' => array(
        'products' => 0,
        'subscriptions' => 0,
    ),
)
```

## Batch Processing

- Process subscriptions in batches to avoid memory issues
- Configurable batch size (default: 50, can be set to 1 for testing)
- Uses WordPress cron (`wp_schedule_single_event`) for background processing
- Save progress after each batch
- Allow resumption from last processed item
- Handle timeouts gracefully
- Restart-safe: Can resume from any interruption
- Uses `spawn_cron()` to trigger immediate execution when possible

## Error Recovery

- Log all errors with subscription/product ID
- Continue processing on individual failures
- Generate error report at end
- Allow retry of failed items
- Track error types and counts
