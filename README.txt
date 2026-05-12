=== KigoCloud for WooCommerce ===

Contributors: dpotocic
Tags: woocommerce, hrvatska fiskalizacija, croatian fiscalization, fiscalization, fiskalizacija, kigocloud, r1, b2b, shop, payments
Requires at least: 5.5
Requires PHP: 7.2
Tested up to: 6.9
Stable tag: 2.1.8
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

The canonical changelog lives at https://github.com/dpotocic/kigocloud-for-woocommerce/blob/main/CHANGELOG.md - the section below is a mirror for the WordPress.org-style README format.

= 2.1.8 =
* Build: new GitHub Actions release workflow. Every pushed vX.Y.Z tag now triggers an automated build that produces a clean kigocloud-for-woocommerce.zip with the proper plugin folder structure and attaches it to a freshly created GitHub Release with auto-generated notes. No more manual zip uploads per release.

= 2.1.7 =
* Docs: new top-level CHANGELOG.md following the Keep-a-Changelog format, linked from README.md and from the admin About tab.

= 2.1.6 =
* Fix: reverted the X-Username / X-Password auth header rename. The KigoCloud server reads incoming headers via apache_request_headers() and matches the literal keys "HTTP_X_USERNAME" / "HTTP_X_PASSWORD" (see protected/modules/v1/components/RestController.php line 102). Switching to standard "X-Username" form was wrong here - the server would not see those headers and returned "Unauthorized. No username/password.". Both real-order push and the Test push button now use the literal HTTP_X_USERNAME / HTTP_X_PASSWORD header names again.

= 2.1.5 =
* Fix: R1 fields are now actually registered on the block checkout. Root cause: the woocommerce_init hook was wrapped in a Woo_KigoCloud_R1::block_supported() check that tested WC_VERSION. Plugins load alphabetically and "kigocloud-" < "woocommerce-", so at hook-registration time WC_VERSION was not yet defined and the check returned false, silently preventing the hook from being added. The hook is now registered unconditionally; the handler itself validates WC availability when it fires.

= 2.1.4 =
* Admin: every tab is now pre-rendered in the DOM. Switching tabs is an instant show/hide (no fetch, no reload). One Save All button at the bottom posts the entire admin in a single options.php request, persisting every option across every tab in one go.
* Added an additional row to the R1 diagnostics panel listing the Gutenberg blocks found on the WooCommerce checkout page so it is obvious when the page is missing a woocommerce/checkout block.
* Sticky save bar at the bottom of the form so the Save button is always visible regardless of how far down a long tab the user scrolls.

= 2.1.3 =
* Fix attempt: simplified block-checkout R1 field registration to the documented minimum (id, label, location, type, required) so WooCommerce can no longer silently reject the registration over an unexpected key
* Sanitize and validate moved off the closure args and onto the dedicated woocommerce_sanitize_additional_field / woocommerce_validate_additional_field filters
* Field IDs renamed from kigocloud/r1_vat_number to kigocloud/r1-vat-number (hyphens) to match the API's id pattern conservatively
* New: Block-checkout diagnostics panel on the R1 admin tab. Shows WC version, whether the field API function exists, whether our hook fires this request, the live registration status, and the registered field IDs. If the fields are missing from the checkout, the red row in this panel points to the cause

= 2.1.2 =
* New: AJAX tab switching on the KigoCloud admin page. Clicking a tab now fetches the new tab content via XHR, swaps it in place and updates the URL. Browser Back/Forward work via history.pushState. No jQuery, no build.
* New: R1 admin tab now has a live preview pane that mirrors what the customer will see on the checkout based on the current mode (optional vs required, OIB only vs full block).
* New: R1 admin tab has a "Send test R1 invoice" button. Posts a synthetic invoice to KigoCloud with the saved credentials and shows the raw response inline, so you can verify the connection without placing a real order. Test results are also written to the Logs tab.
* New: R1 admin tab has a "Force billing company required" option. When enabled, marks the standard WooCommerce billing.company field as required everywhere (block and classic checkout, Customer Account address forms).

= 2.1.1 =
* Fix: R1 fields (OIB / company) now actually render on the block checkout. The previous registration silently failed because it passed unsupported keys (show_in_order_confirmation, attributes.pattern) to the Additional Checkout Fields API
* Validation moved from a separate filter into the sanitize_callback / validate_callback args so it follows the documented API contract
* R1 mode 2 now correctly marks OIB and company name fields as required on the block checkout (was optional in 2.1.0)

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
