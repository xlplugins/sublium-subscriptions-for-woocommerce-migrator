# Phase 8: Admin UI

## Files to Create

- `admin/app/pages/migration/index.jsx` - Main migration page
- `admin/app/pages/migration/components/DiscoveryPanel.jsx` - Discovery results
- `admin/app/pages/migration/components/MigrationProgress.jsx` - Progress bar
- `admin/app/pages/migration/components/ErrorLog.jsx` - Error display
- `admin/app/pages/migration/components/ValidationResults.jsx` - Validation report
- `admin/app/pages/migration/hooks/useMigration.js` - Migration hooks

## UI Features

### Discovery Dashboard

- **WooCommerce Subscriptions Plugin Status:**
  - Plugin installed/active status
  - Plugin version
  - Warning if not found

- **Payment Gateway Discovery** (Critical Section):
  - List of all WCS gateways found in active subscriptions
  - Subscription count per gateway
  - Sublium gateway mapping for each
  - Compatibility status (✅ Compatible / ⚠️ Warning / ❌ Not Compatible)
  - Action required messages

- Total subscriptions to migrate
- Total subscription products found
- Payment gateway breakdown (with compatibility indicators)
- Billing period distribution
- Date range analysis

### Two-Stage Migration UI

**Stage 1: Product/Plan Migration**
- Show products found
- Show plans created
- Progress bar for plan creation
- Product type breakdown (native vs WCS_ATT)
- "Start Product Migration" button

**Stage 2: Subscription Migration** (disabled until Stage 1 completes)
- Show subscriptions to migrate
- Progress bar for subscription migration
- Success/failure counts
- **Gateway Compatibility Warning**: Before starting migration, check gateway compatibility
  - If readiness status is "partial" or "blocked", show alert dialog
  - Display list of incompatible gateways with subscription counts
  - Require user confirmation before proceeding
  - "Start Subscription Migration" button triggers warning if needed

### Migration Controls

- "Start Product Migration" button (Stage 1)
- "Start Subscription Migration" button (Stage 2, enabled after Stage 1)
  - Shows gateway compatibility warning if incompatible gateways detected
  - Requires user confirmation before proceeding
- "Reset/Cancel" button - Resets migration state
- Progress tracking with real-time updates

### Real-time Progress

- Progress bar for current stage
- Current item being processed
- Success/failure counts
- Estimated time remaining

### Error Log Viewer

- List of errors with details
- Filter by error type
- Export error log
- Retry failed items

### Validation Results Display

- Validation summary
- Pass/fail indicators
- Detailed validation report
- Recommendations for fixes

## React Components

- `DiscoveryPanel` - Shows discovery results
- `MigrationProgress` - Progress tracking component
- `ErrorLog` - Error display component
- `ValidationResults` - Validation report component
- `GatewayCompatibility` - Gateway mapping display

## API Integration

- Use `useMigration` hook for API calls
- Real-time status updates via polling
- Handle API errors gracefully
- Show loading states
