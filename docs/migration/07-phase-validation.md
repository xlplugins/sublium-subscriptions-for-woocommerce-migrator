# Phase 7: Validation & Error Handling

## Files to Create

- `includes/migration/validators/class-migration-validator.php` - Validate migrated data
- `includes/migration/exceptions/class-migration-exception.php` - Custom exceptions

## Validation Checks

- Subscription count matches (WCS active subscriptions = Sublium active subscriptions)
- All active subscriptions migrated
- Next payment dates preserved (within acceptable range)
- Payment methods assigned correctly
- Line items linked properly
- Totals match original (within rounding tolerance)
- Plans created for all products
- Product-plan mapping is complete

## Error Handling

- Log failed migrations with reasons
- Continue processing on individual failures
- Generate error report
- Allow retry of failed items
- Track error types:
  - Missing plan
  - Invalid product type
  - Gateway compatibility issues
  - Data transformation errors
  - Database errors

## Validation Report

Generate report showing:
- Total subscriptions to migrate
- Successfully migrated count
- Failed count with reasons
- Skipped count (non-migratable products)
- Validation results
- Recommendations for fixes

## Key Methods

```php
validate_migration() - Run all validation checks
validate_subscription_count() - Check subscription counts match
validate_payment_dates() - Verify next payment dates
validate_payment_methods() - Check payment methods assigned
validate_line_items() - Verify line items linked
generate_validation_report() - Create validation report
```
