=== WCS to Sublium Migrator ===
Contributors: sublium
Tags: woocommerce, subscriptions, migration, sublium
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate WooCommerce Subscriptions data to Sublium with background processing and feasibility analysis.

== Description ==

WCS to Sublium Migrator is a standalone plugin that helps you migrate your WooCommerce Subscriptions data to Sublium Subscriptions for WooCommerce.

**Key Features:**

* **Feasibility Analysis** - Before starting migration, see exactly what will be migrated:
  * Total number of active subscriptions
  * Breakdown by payment gateways
  * Count of simple and variable subscription products
  * Overall readiness status

* **Background Processing** - Migration runs in the background using WordPress cron:
  * Products migrator - Processes subscription products independently
  * Subscriptions migrator - Processes active subscriptions independently
  * Batch processing - Handles large datasets efficiently
  * Restart-safe - Can pause and resume migration

* **Progress Tracking** - Real-time progress updates:
  * Total items to migrate
  * Number of items migrated successfully
  * Number of failures with error logs
  * Current status (pending / running / completed)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wcs-sublium-migrator` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to WooCommerce > WCS Migration to access the migration interface

== Requirements ==

* WooCommerce (5.0+)
* WooCommerce Subscriptions (2.0+)
* Sublium Subscriptions for WooCommerce (must be installed and active)

== Frequently Asked Questions ==

= How does the migration work? =

The migration runs in two stages:
1. Products Migration - Converts WCS subscription products to Sublium plans
2. Subscriptions Migration - Migrates active subscriptions to Sublium

Both stages run in the background using WordPress cron, so they won't block your admin interface.

= Can I pause and resume migration? =

Yes! You can pause the migration at any time and resume it later. The migration will continue from where it left off.

= What happens if migration fails? =

Failed items are logged with error messages. You can review errors in the migration interface and retry if needed.

= Will this affect my existing WCS subscriptions? =

No. This plugin only reads data from WooCommerce Subscriptions. It does not modify or delete any WCS data.

== Changelog ==

= 1.0.0 =
* Initial release
* Feasibility analysis screen
* Background migration processing
* Progress tracking
* Pause/resume functionality
