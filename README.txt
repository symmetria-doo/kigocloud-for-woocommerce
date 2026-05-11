=== KigoCloud for WooCommerce ===

Contributors: dpotocic
Tags: woocommerce, hrvatska fiskalizacija, croatian fiscalization, fiscalization, fiskalizacija, kigocloud, r1, b2b, shop, payments
Requires at least: 5.5
Requires PHP: 7.2
Tested up to: 6.9
Stable tag: 2.1.0
WC requires at least: 5.0
WC tested up to: 9.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Sends WooCommerce orders to the KigoCloud service. R1 invoices, fiscalization, inventory.

== Description ==

The plugin hooks into the WooCommerce order lifecycle and forwards orders to KigoCloud (https://app.kigo.cloud). KigoCloud handles Croatian fiscalization, R1 (B2B) invoicing, and inventory sync.

Highlights:

* Per-gateway document type and payment method mapping
* Per-gateway choice of when to trigger the API call (Order Created / Order Completed)
* R1 fields on the WooCommerce checkout: company name, address, city, postcode, OIB (VAT number)
* Classic checkout (shortcode) and block checkout (Gutenberg) both supported
* Custom meta-to-billing mapping for sites that already collect R1 data via other plugins
* Sends invoice PDF to the customer by email after a successful KigoCloud document creation
* In-place auto-update against GitHub Releases

This plugin is not hosted on the WordPress.org repository. Updates are delivered from https://github.com/dpotocic/kigocloud-for-woocommerce.

== Installation ==

= Minimum requirements =

* WordPress 5.5 or higher
* WooCommerce 5.0 or higher
* PHP 7.2 or higher

= Steps =

1. Download the latest zip from https://github.com/dpotocic/kigocloud-for-woocommerce/releases
2. WordPress admin -> Plugins -> Add New -> Upload Plugin
3. Activate after upload
4. WooCommerce -> Settings -> KigoCloud, fill in your API credentials

== Changelog ==

= 2.1.0 =
* New dedicated KigoCloud admin page (top-level menu) with proper nav-tab navigation: Connection, Orders, R1, Email, Mapping, Logs, About
* Per-gateway settings now render as a single table instead of a vertical wall of inputs
* New Logs tab with the last 50 KigoCloud API calls (success/error indicator, order link, message)
* Declares WooCommerce HPOS and cart/checkout block compatibility so WC no longer flags the plugin as incompatible
* Removed the legacy WooCommerce settings tab; all configuration lives on the standalone KigoCloud page

= 2.0.0 =
* Forked from kigokasa-api-for-woocommerce 1.7.2 for the KigoCloud brand
* Default API endpoint switched to https://app.kigo.cloud/hr/api/v1/
* Block checkout (Gutenberg) support for R1 fields on WooCommerce 8.6+
* GitHub Releases auto-update via plugin-update-checker
* Cleaner WooCommerce settings layout with grouped sections
* Minimum PHP raised to 7.2, minimum WC raised to 5.0

For changes prior to 2.0.0 see the kigokasa-api-for-woocommerce history.
