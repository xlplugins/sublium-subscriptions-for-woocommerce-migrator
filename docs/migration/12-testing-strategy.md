# Testing Strategy

## Unit Tests

- Test extractors with mock WCS data
- Test transformers with known inputs
- Test importers with sample data
- Test gateway mapping logic
- Test plan type determination
- Test signup fee and trial transformations

## Integration Tests

- Test full migration flow
- Test batch processing
- Test error recovery
- Test duplicate prevention
- Test plan creation and mapping
- Test subscription import with plan linking

## Manual Testing

- Test with small batch (10 subscriptions)
- Test with various payment gateways
- Test with different billing periods
- Test with signup fees and trials
- Test validation and rollback
- Test WCS_ATT products (if plugin available)
- Test native subscription products
- Test variable subscription products

## Test Scenarios

### Scenario 1: Basic Migration
- 10 active subscriptions
- Single payment gateway (Stripe)
- Monthly billing
- No trials or signup fees

### Scenario 2: Complex Migration
- 100+ subscriptions
- Multiple payment gateways
- Various billing periods
- With trials and signup fees
- Mix of virtual and physical products

### Scenario 3: Edge Cases
- Subscriptions with expired trials
- Subscriptions with manual renewal
- Subscriptions with cancelled status
- Products with WCS_ATT schemes
- Variable subscription products

## Security Considerations

- Sanitize all user input
- Use prepared statements for all database queries
- Verify user capabilities (admin only)
- Nonce verification for AJAX requests
- Escape all output in admin UI
- Validate all data before import

## Performance Considerations

- Batch processing to avoid memory issues
- Use direct database queries for large datasets
- Index optimization for lookups
- Progress saving to allow resumption
- Configurable batch size (default: 50 subscriptions per batch)
- Limit concurrent operations

## Documentation

- Update `docs/MIGRATION_TO_SUBLIUM.txt` with tool usage
- Add migration guide to admin
- Document API endpoints
- Create troubleshooting guide
- Document gateway mapping
- Document plan type determination logic
