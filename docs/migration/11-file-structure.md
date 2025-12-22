# File Structure

## Migration Directory Structure

```
includes/migration/
├── class-wcs-migrator.php
├── class-migration-state.php
├── class-wcs-discovery.php
├── extractors/
│   ├── class-base-extractor.php
│   ├── class-subscription-extractor.php
│   ├── class-product-extractor.php
│   └── class-payment-extractor.php
├── transformers/
│   ├── class-base-transformer.php
│   ├── class-subscription-transformer.php
│   ├── class-product-transformer.php
│   └── class-payment-transformer.php
├── importers/
│   ├── class-base-importer.php
│   ├── class-subscription-importer.php
│   ├── class-plan-importer.php
│   └── class-item-importer.php
├── validators/
│   └── class-migration-validator.php
├── loggers/
│   └── class-migration-logger.php
├── mappers/
│   ├── class-gateway-mapper.php
│   └── class-product-plan-mapper.php
└── exceptions/
    └── class-migration-exception.php

admin/app/pages/migration/
├── index.jsx
├── components/
│   ├── DiscoveryPanel.jsx
│   ├── MigrationProgress.jsx
│   ├── ErrorLog.jsx
│   └── ValidationResults.jsx
└── hooks/
    └── useMigration.js

includes/api/
└── migration.php
```

## File Descriptions

### Core Migration Classes

- `class-wcs-migrator.php` - Main migration controller, orchestrates the entire process
- `class-migration-state.php` - Tracks migration state and progress
- `class-wcs-discovery.php` - Discovery and analysis tools

### Extractors

- `class-base-extractor.php` - Base class for all extractors
- `class-subscription-extractor.php` - Extracts WCS subscription data
- `class-product-extractor.php` - Extracts product data (native + WCS_ATT)
- `class-payment-extractor.php` - Extracts payment method data

### Transformers

- `class-base-transformer.php` - Base class for all transformers
- `class-subscription-transformer.php` - Transforms subscription data
- `class-product-transformer.php` - Transforms product data
- `class-payment-transformer.php` - Transforms payment data

### Importers

- `class-base-importer.php` - Base class for all importers
- `class-subscription-importer.php` - Imports subscriptions
- `class-plan-importer.php` - Creates/imports plans
- `class-item-importer.php` - Imports line items

### Mappers

- `class-gateway-mapper.php` - Maps WCS gateways to Sublium gateways
- `class-product-plan-mapper.php` - Maps products to plans

### Utilities

- `class-migration-validator.php` - Validates migrated data
- `class-migration-logger.php` - Logs migration activities
- `class-migration-exception.php` - Custom exception class

### Admin UI

- `index.jsx` - Main migration page component
- `DiscoveryPanel.jsx` - Discovery results display
- `MigrationProgress.jsx` - Progress tracking component
- `ErrorLog.jsx` - Error display component
- `ValidationResults.jsx` - Validation report component
- `useMigration.js` - React hooks for migration API

### API

- `migration.php` - REST API endpoints for migration
