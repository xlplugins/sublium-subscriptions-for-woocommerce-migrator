# Phase 1: Discovery & Analysis Tools

## Files to Create

- `includes/migration/class-wcs-discovery.php` - Discovery/analysis class
- `includes/migration/mappers/class-gateway-mapper.php` - Gateway mapping class
- `admin/app/pages/migration/discovery.jsx` - Discovery UI component

## Gateway Mapping Logic

```php
/**
 * Map WCS gateway IDs to Sublium gateway IDs
 *
 * @param string $wcs_gateway_id WCS gateway ID
 * @return array ['sublium_id' => string, 'compatible' => bool, 'message' => string]
 */
private function map_wcs_to_sublium_gateway($wcs_gateway_id) {
    $gateway_map = array(
        // Stripe variations
        'stripe' => array(
            'sublium_id' => 'fkwcs_stripe',
            'compatible' => true,
            'message' => 'Maps to FunnelKit Stripe'
        ),
        'stripe_cc' => array(
            'sublium_id' => 'fkwcs_stripe',
            'compatible' => true,
            'message' => 'Maps to FunnelKit Stripe'
        ),

        // PayPal variations
        'ppec_paypal' => array(
            'sublium_id' => 'fkwcppcp_paypal',
            'compatible' => true,
            'message' => 'Maps to FunnelKit PayPal'
        ),
        'paypal' => array(
            'sublium_id' => 'fkwcppcp_paypal',
            'compatible' => true,
            'message' => 'Maps to FunnelKit PayPal (may need re-authorization)'
        ),
        'ppcp-gateway' => array(
            'sublium_id' => 'fkwcppcp_paypal',
            'compatible' => true,
            'message' => 'Maps to FunnelKit PayPal'
        ),

        // Square
        'square_credit_card' => array(
            'sublium_id' => 'fkwcsq_square',
            'compatible' => true,
            'message' => 'Maps to FunnelKit Square'
        ),

        // Manual/Offline gateways
        'bacs' => array(
            'sublium_id' => 'bacs',
            'compatible' => true,
            'message' => 'Manual renewal - no automatic payments'
        ),
        'cheque' => array(
            'sublium_id' => 'cheque',
            'compatible' => true,
            'message' => 'Manual renewal - no automatic payments'
        ),
        'cod' => array(
            'sublium_id' => 'cod',
            'compatible' => true,
            'message' => 'Manual renewal - no automatic payments'
        ),

        // Empty/Manual
        '' => array(
            'sublium_id' => '',
            'compatible' => true,
            'message' => 'Manual renewal subscription'
        ),
    );

    // Check if gateway is in map
    if (isset($gateway_map[$wcs_gateway_id])) {
        $mapped = $gateway_map[$wcs_gateway_id];

        // Verify gateway exists in Sublium
        $sublium_gateways = Gateways::get_instance()->get_supported_gateways();
        if (!empty($mapped['sublium_id']) && !isset($sublium_gateways[$mapped['sublium_id']])) {
            $mapped['compatible'] = false;
            $mapped['message'] = 'Gateway not available in Sublium - needs configuration';
        }

        return $mapped;
    }

    // Unknown gateway
    return array(
        'sublium_id' => '',
        'compatible' => false,
        'message' => 'Gateway not mapped - may need manual configuration'
    );
}

/**
 * Discover all payment gateways from active WCS subscriptions
 *
 * @return array Gateway summary with counts and compatibility
 */
public function discover_payment_gateways() {
    global $wpdb;

    // Get all unique payment methods from active subscriptions
    $query = $wpdb->prepare(
        "SELECT
            pm.meta_value as gateway_id,
            COUNT(DISTINCT p.ID) as subscription_count,
            COUNT(DISTINCT CASE WHEN pm2.meta_value = 'true' THEN p.ID END) as manual_renewal_count
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_payment_method'
        LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_requires_manual_renewal'
        WHERE p.post_type = 'shop_subscription'
        AND p.post_status = 'wc-active'
        GROUP BY pm.meta_value
        ORDER BY subscription_count DESC"
    );

    $gateway_data = $wpdb->get_results($query, ARRAY_A);

    $gateway_summary = array();
    foreach ($gateway_data as $gateway) {
        $gateway_id = $gateway['gateway_id'] ?? '';
        $mapping = $this->map_wcs_to_sublium_gateway($gateway_id);

        $gateway_summary[] = array(
            'wcs_gateway_id' => $gateway_id,
            'wcs_gateway_title' => $this->get_wcs_gateway_title($gateway_id),
            'subscription_count' => (int) $gateway['subscription_count'],
            'manual_renewal_count' => (int) $gateway['manual_renewal_count'],
            'sublium_gateway_id' => $mapping['sublium_id'],
            'compatible' => $mapping['compatible'],
            'message' => $mapping['message'],
        );
    }

    return $gateway_summary;
}
```

## Functionality

### Pre-Migration Checks

- Verify WooCommerce Subscriptions plugin is installed and active
- Check if WCS plugin version is compatible
- Verify Sublium plugin is active

### Payment Gateway Discovery (CRITICAL - Must happen before migration)

- Fetch all unique payment gateways from active WCS subscriptions
- Count subscriptions per gateway
- Identify manual renewal subscriptions (no gateway)
- Map WCS gateway IDs to Sublium gateway IDs
- Check gateway compatibility/availability in Sublium
- Show gateway mapping information
- Warn about incompatible or missing gateways

### Discovery Analysis

- Count active WCS subscriptions
- Group by payment gateway (with compatibility status)
- Group by billing period
- Identify subscription products:
  - Native subscription products (`subscription`, `variable-subscription` types)
  - Simple/variable products with WCS_ATT schemes (if "All Products for Subscriptions" plugin is active)
  - **Note:** Products without WCS_ATT meta are NOT migrated
- Analyze date ranges (next payment dates)
- Check if "All Products for Subscriptions" plugin is active
- Generate discovery report
- **Warning:** Show count of subscriptions with non-subscription product types and no WCS_ATT meta (will be skipped)

## Key Methods

```php
check_wcs_plugin_exists() - Verify WooCommerce Subscriptions is active
discover_payment_gateways() - Get all gateways from active subscriptions
map_wcs_to_sublium_gateways() - Map WCS gateway IDs to Sublium gateway IDs
check_gateway_compatibility($wcs_gateway_id) - Check if gateway is supported in Sublium
get_payment_methods_summary() - Summary with compatibility status
get_active_subscription_count()
get_billing_periods_summary()
get_subscription_products_summary()
get_date_range_analysis()
```

## API Endpoints

```
GET  /wp-json/sublium/v1/migration/discovery              # Full discovery including gateways
GET  /wp-json/sublium/v1/migration/discovery/gateways     # Gateway discovery only
GET  /wp-json/sublium/v1/migration/discovery/wcs-status   # Check WCS plugin status
```

## Discovery API Response Example

```json
{
  "success": true,
  "data": {
    "wcs_plugin": {
      "active": true,
      "version": "5.8.0",
      "compatible": true
    },
    "gateways": [
      {
        "wcs_gateway_id": "stripe",
        "wcs_gateway_title": "Credit Card (Stripe)",
        "subscription_count": 150,
        "manual_renewal_count": 0,
        "sublium_gateway_id": "fkwcs_stripe",
        "compatible": true,
        "message": "Maps to FunnelKit Stripe"
      }
    ],
    "subscriptions": {
      "total": 195
    }
  }
}
```
