# Changelog

All notable changes to KigoCloud for WooCommerce are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.32] - 2026-10-27

### Changed
- `Woo_KigoCloud_i18n::load_plugin_textdomain()` loads the bundled `languages/kigocloud-for-woocommerce-<locale>.mo` with `load_textdomain()` on `init` instead of calling `load_plugin_textdomain()` on `plugins_loaded` (discouraged by Plugin Check). Without a bundled file WordPress loads the wordpress.org language pack just in time.
- R1 tab: the inline `<script>` that revealed the test result box is removed; `admin/js/kigocloud-admin.js` already shows it on click.

## [2.1.31] - 2026-10-26

### Fixed
- Tax rate of products, fees and shipping comes from the order's tax items (`WC_Order_Item_Tax::get_rate_percent()` by the rate ids of the line, `get_lines_tax_rate()`), no longer `round(total_tax / total * 100)`: on a low price the tax rounded to the cent gave a rate that does not exist (0.19 / 0.776 = 24 % for a 25 % product) and Porezna refused the receipt (s013/182). The amounts are still used when the line has no rate the order knows.
- The product price sent is (total + total tax) / quantity, what the buyer paid, instead of the net price times the rate worked out above.

## [2.1.30] - 2026-10-25

### Added
- wordpress.org build: `bin/build-wporg.sh` builds the plugin without the GitHub updater (no `vendor/`, no `Update URI` header, the updater bootstrap between the `wporg-strip` markers removed) and checks that the header, the define, `Woo_KigoCloud::PLUGIN_VERSION` and the readme `Stable tag` carry the same version. Workflow `.github/workflows/wporg.yml` builds it on every `v*` tag and commits it to the wordpress.org SVN only when the repository variable `WPORG_DEPLOY` is `true` (secrets `SVN_USERNAME`, `SVN_PASSWORD`).
- Connection tab: "Try with the demo account" fills in the public demo login (admin_demo, PIN 1) in the form, with a warning that the demo is public; nothing is saved or sent until Save.
- readme.txt in the wordpress.org format, with the External services section.

### Changed
- The API username and password start empty (they defaulted to admin_demo in the form). `Woo_KigoCloud_Request::has_credentials()`: no order is sent and the R1 test button refuses until both are saved.
- HTTPS calls to KigoCloud verify the certificate (`sslverify => false` removed). The WP_DEBUG `error_log` of the request and response, which carried the API login, is removed.
- Every setting has a sanitize callback; the price list files are written and moved with `WP_Filesystem_Direct` and read with `SplFileObject`; `wp_delete_file()` instead of `unlink()`; every PHP file stops on direct access.
- Document type in the order note and the e-mail comes from the `Invoice` / `Offer` strings (`document_type_title()`) instead of the dynamic `pos_type_N` msgid; German translations for both added.
- Plugin URI points to https://www.kigo.cloud/fiskalizacija-web-trgovine/, the About tab links the website and shows the GitHub releases only in the GitHub build. `README.txt` renamed to `readme.txt`.

### Fixed
- `Woo_KigoCloud::PLUGIN_VERSION` was left at 2.1.28 in 2.1.29, so the admin page and `kigocloud_version` showed 2.1.28.

## [2.1.29] - 2026-10-14

### Fixed
- The payment type list on the Orders tab no longer offers Cheque (`C`). The KigoCloud API accepts only `T`, `G`, `K` and `O`, so an order from a gateway mapped to Cheque was refused with "Payment mode is not in the list." A gateway that still has `C` saved is shown as Other and its orders are sent with `O`.

### Changed
- Tested up to WordPress 7.1 and WooCommerce 11.2.

## [2.1.28] - 2026-10-12

### Added
- Option `kigocloud_english_for_foreign` (Orders tab) is now "Language for foreign customers" and has a third value, 2 = by country: a customer whose country on the order is Germany, Austria, Switzerland or Liechtenstein gets the invoice PDF and the e-mail in German (the API is called under `/de/api/v1/`), every other customer outside Croatia in English. Values 0 (off) and 1 (English) work as before.
- `languages/kigocloud-for-woocommerce-de_DE.po` with the document type, the e-mail to the customer, the order note and the shipping line in German.
- The note on the document ("Web Order #...") and the name of the shipping line follow the document's language, so an English or German invoice no longer carries them in the shop's language. `en_US` now translates the shipping line ("Shipping").

### Changed
- The e-mail to a foreign customer reads its strings straight from the plugin's own translation file (`Woo_KigoCloud_Request::language_translations()`) instead of `switch_to_locale()`, which works only for a language installed on the site: a shop in Croatian seldom has German installed. `use_english()`, `english_api_url()`, `switch_to_english()`, `restore_language()` and `reload_textdomain()` are replaced by `document_language()`, `language_api_url()`, `language_translations()` and `mail_text()`.

## [2.1.27] - 2026-10-09

### Fixed
- R1 mode "Show full R1 block" on the classic checkout saves the company fields even when the buyer leaves the R1 checkbox off, as empty strings. The field mapping then copied those empty values over `billing.company`, `billing.address_1`, `billing.city` and `billing.postcode`, so the document was created without the buyer's address. Empty source values are now skipped, for the built-in R1 fields and for custom mapping rules alike.

## [2.1.26] - 2026-10-09

### Fixed
- The "Test KigoCloud connection" button sent `pos_type` 1, so every click issued a real (and, with fiscalisation on, fiscalised) invoice in the shop owner's KigoCloud account. It now sends `pos_type` 2, an R1 offer; the button reads "Send test R1 offer".
- "Fill empty SKU" sent `sku-<order line id>`, a different value on every order, so KigoCloud created a new item each time. It now sends `sku-<variation id or product id>`; the order line id is used only for a line without a product.

## [2.1.25] - 2026-10-09

### Changed
- Croatian translation: 13 checkout strings of the R1 class (`includes/class-woo-kigocloud-r1.php`) were missing from `languages/kigocloud-for-woocommerce-hr.po` and showed in English. The VAT field is now labelled "OIB / PDV broj (za R1 račun)". No code change.

## [2.1.24] - 2026-10-08

### Fixed
- Trigger "Order Creation" fired only on `pending -> on-hold` and `pending -> processing`. An order that reached a paid status by another route (`failed -> processing` after a second payment attempt, `checkout-draft -> processing` on block checkout, `pending -> completed` for virtual products) never got a document. It now fires the first time the order enters `on-hold`, `processing` or `completed` from any status outside that set; a move inside the set (`processing -> completed`) does not send, so orders that were already open before the plugin was installed are left alone.
- With taxes disabled in WooCommerce (`wc_tax_enabled()` false) every line was sent with `vat_percent` 0 and the document was created without VAT. `vat_percent` is now left out in that case, for products, fees and shipping, and KigoCloud uses the tax rate of the item.

## [2.1.23] - 2026-10-08

### Fixed
- `client.client_vat_type` is 0 whenever the buyer gave a VAT number, as before 2.1.22. In 2.1.22 it also required a company name; with R1 mode "OIB / VAT field only" the checkout has no company field, so every buyer with an OIB was sent as a private person and KigoCloud did not print the R1 label on the invoice.

## [2.1.22] - 2026-10-08

### Fixed
- `Woo_KigoCloud_Request::send_once()` takes a MySQL advisory lock (`GET_LOCK('kigocloud_order_<id>', 0)`) around the send and re-reads the order meta under it. Two `woocommerce_order_status_changed` calls arriving together (payment callback and the customer's return) both passed the "no document yet" check and could both post the order.
- An order whose first attempt timed out after KigoCloud had created the document stayed without `_kigocloud_id_pos` for good, because every later attempt was answered with "Internal number ... already exists". KigoCloud now returns that document in `data.existing` and the plugin links the order to it.
- SKU lookup routes accept any SKU (`(?P<sku>.+)`, value passed through `rawurldecode`). `[\w-]+` answered `rest_no_route` for a SKU with a dot, a space or a diacritic, so KigoCloud could not match such products.
- `execute_api_call()` no longer calls `get_sku()` on `false` when a product was deleted after the order was placed (fatal error on PHP 8, the status change died and no document was sent).
- A network error is logged with its `WP_Error` code and message; it used to read "HTTP : ".
- An error message that arrives as a list or an object is logged as JSON instead of failing on the string cast.

### Changed
- `client.client_vat_type` was 0 (company) only with both a VAT number and a company name. Taken back in 2.1.23.

## [2.1.21] - 2026-10-02

### Added
- Option `kigocloud_english_for_foreign` (Orders tab, "English for foreign customers", off by default). When it is on and the country on the order is not Croatia, the plugin calls the API under `/en/api/v1/` instead of `/hr/api/v1/` for that order (`invoice/create` and `invoice/getAsPdf`), so KigoCloud returns the invoice PDF in English, and writes the e-mail with the PDF in English (`switch_to_locale('en_US')`). The decision is made only in the plugin; KigoCloud takes the language from the address, as it already does. The KigoKasa switch copies `woo_kigokasa_api_english_for_foreign`.

### Fixed
- The release zip now contains compiled translations. `languages/*.mo` is ignored by git and the release workflow did not build it, so WordPress loaded no translations for the plugin: the e-mail to the customer and the order note showed `pos_type_1` instead of the document type.

## [2.1.20] - 2026-09-24

### Added
- `Woo_KigoCloud_KigoKasa_Switch` copies the KigoKasa order meta (`_kigokasa_id_pos`, `_kigokasa_pos_number`, `_kigokasa_doc_type`, `woo_kigokasa_api_vat_invoices_*`) into the matching KigoCloud keys, filling only keys that are still empty. It starts on `deactivated_plugin` for the KigoKasa plugin, and once on `admin_init` for installs whose KigoKasa plugin is not active. WP-Cron (`kigocloud_copy_kigokasa_order_meta`) works through `wc_get_orders()` in batches of 100 for up to 20 seconds per run, in two passes (orders with a KigoKasa document, then orders with KigoKasa R1 details), and reschedules itself until done. Works on HPOS and on post storage. When it finishes and the KigoKasa plugin is still installed, a one-time notice says it can be deleted.

### Why
- `uninstall.php` of KigoKasa 1.7.3 and 1.7.4 deletes `_kigokasa_*` and `woo_kigokasa_api_vat_invoices_*` order meta. Without a copy, deleting the old plugin would remove the only record that an order already has a fiscalized document. KigoKasa 1.7.5 keeps that meta.

## [2.1.19] - 2026-09-24

### Added
- `Woo_KigoCloud_KigoKasa_Switch`. On activation (and on `admin_init` for installs updated in place) it copies the `woo_kigokasa_api_*` options, including the per-gateway `pos_type`, `payment_type`, `on_status` and `pdf_payment_type`, into the matching `kigocloud_*` options, but only while `kigocloud_username` is empty. The imported credentials keep `https://trgovina.kigoserver.com/hr/api/v1/` as `kigocloud_api_url` unless an endpoint is already set.
- While `kigokasa-api-for-woocommerce` is active, `Woo_KigoCloud_Request::on_order_status_change()` returns early and an admin notice offers **Switch to KigoCloud** (`admin-post.php?action=kigocloud_switch_from_kigokasa`, nonce and `activate_plugins` capability). The handler deactivates the KigoKasa plugin, without deleting it.

### Fixed
- The duplicate guard also reads `_kigokasa_id_pos`, so an order the KigoKasa plugin already sent is not sent again after a status change.
- R1 mode 2 also maps the `woo_kigokasa_api_vat_invoices_*` order meta, and the order screen falls back to `_kigokasa_pos_number` / `_kigokasa_doc_type`.
- `Woo_KigoCloud::PLUGIN_VERSION` was left at 2.1.17 in 2.1.18.

### Changed
- `Author` header is `Symmetria d.o.o.` only.

## [2.1.18] - 2026-09-23

### Changed
- Price list files are stored under the name KigoCloud sends in `Content-Disposition` (form, address and code of the outlet, storage number, date and time), as the decision on price list publication (NN 101/2026, point VI) prescribes. `Woo_KigoCloud_Pricelist::path()` and `url()` return the current file from the `kigocloud_pricelist_current` option; the fixed `cjenik.csv` / `cjenik.xml` of 2.1.17 are removed on the first fetch.
- Every publication stays in `wp-content/uploads/kigo-cjenik/` for 30 days (point II) and `[kigo_cjenik]` lists them under the table (`Woo_KigoCloud_Pricelist::archive()`).
- Freshness is the calendar day (site time zone), not 26 hours: `is_current()` drives the lazy refresh, the fetch and the wp-admin notice.

## [2.1.17] - 2026-09-22

### Added
- Price list published on the shop's own domain (Croatian decision on price list publication, NN 101/2026). `Woo_KigoCloud_Pricelist` pulls the CSV and the XML from the configured KigoCloud address once a day (WP-Cron at 07:00, plus a lazy refresh for low traffic sites) and writes them into `wp-content/uploads/kigo-cjenik/`, where the shop web server serves them statically. Conditional requests (`If-Modified-Since`) keep the daily fetch close to free.
- Admin tab **Price list**: the KigoCloud address, the two published addresses, the last refresh time and the last error. Saving the address fetches immediately.
- Shortcode `[kigo_cjenik]` renders the local CSV as a table with links to both files. No page view reaches KigoCloud.
- An admin notice when the published copy is more than two days old, so a stale price list is not missed.

## [2.1.16] - 2026-09-22

### Changed
- The API endpoint field is removed from the Connection tab. Pointing the integration at another host would send the API credentials and the order data there, so it no longer belongs on a screen every shop administrator can open. The effective endpoint stays visible on the About tab, and `Woo_KigoCloud_Request::resolveApiUrl()` now reads, in order: the `WOO_KIGOCLOUD_API_URL` constant, a value stored by an earlier version, and the `kigocloud_api_url` filter.
- Changelog and source comments for the anchor price are in English.

## [2.1.15] - 2026-09-22

### Added
- Anchor price ("sidrena cijena", Croatian Government decision effective 1 October 2026): the product and variation price HTML can carry a line with the price that applied on the reference day, read from the `_kigo_anchor_price` and `_kigo_anchor_price_date` meta that KigoCloud sends with the product (`woocommerce_get_price_html` filter).
- The display is opt-in: KigoCloud admin, tab **Prices**, option **Anchor price** (default: do not show). Until it is switched on, and for products without that meta, the price HTML is returned unchanged.

## [2.1.14] - 2026-05-29

### Changed
- `Author` plugin header now reads `Symmetria d.o.o. (Dejan Potočić)` with Author URI `https://www.symmetria.hr/` (previously just `Dejan Potočić`). `composer.json` now lists both Symmetria d.o.o. and Dejan Potocic (Developer).
- Registered `woocommerce_validate_additional_field` via `add_action` instead of `add_filter`. It is a `do_action` hook in WooCommerce core (`CheckoutFields.php:924`); the prior filter registration functioned by mutating the shared `WP_Error`, but the action registration is the documented contract.

## [2.1.13] - 2026-05-13

### Fixed
Full code audit. Approximately fifteen bugs fixed across the codebase:

- **Activator**: `deactivate_plugins(plugin_basename(__FILE__))` resolved to the activator class file (not the main plugin file), so both bail-out branches silently failed. Switched to `WOO_KIGOCLOUD_PLUGIN_FILE`. Removed ~40 lines of dead duplicate migration logic that also contained an immediate double-assignment bug.
- **Deactivator**: stopped deleting `kigocloud_version` on deactivate. The migrator would treat the next activation as a fresh install and re-run the pre-1.7.0 per-gateway settings migration, clobbering admin-tuned values. Only the post-update review notice flag is cleared now.
- **Uninstall**: rewrote as a single SQL wipe of every `kigocloud_%` option and `_kigocloud_%` / `kigocloud_vat_invoices_%` post-meta and HPOS order meta. The previous file named a non-existent option (`kigocloud_employee_pin`), skipped most real options, and registered an action filter that would never fire.
- **R1 block-checkout sync**: `get_additional_field_value()` looked under `_wc_other/<id>` only. WooCommerce stores `address`-location fields under `_wc_billing/<id>` and `_wc_shipping/<id>` instead - so OIB and company values entered through the block checkout never landed in the legacy `kigocloud_vat_invoices_*` meta the request builder reads. Now tries all three prefixes.
- **HPOS metadata writes** (`Woo_KigoCloud_Request::execute_api_call`): replaced `update_post_meta` with `$order->update_meta_data` + `$order->save()`. Without this fix the `_kigocloud_id_pos`, `_kigocloud_pos_number` and `_kigocloud_doc_type` meta would have been written only to `wp_postmeta` on stores that have migrated to HPOS order tables - and the duplicate-call guard (`get_meta('_kigocloud_id_pos')`) would never see them, leading to duplicated KigoCloud documents on each status change.
- **HPOS metadata reads** (`display_admin_order_meta`, `display_admin_order_kigocloud`): replaced `get_post_meta` with `$order->get_meta`.
- **JSON safety**: `execute_api_call` accessed `$body->pos_number` even when `json_decode` returned `null`. PHP 8 turns that into a warning and would treat the order as having no document number, double-charging the next status transition. Added an `is_object` guard plus a structured log entry on malformed responses.
- **PDF download**: `send_invoice_email` now uses `wp_remote_retrieve_body()` and checks for the `%PDF` magic header before writing. Removed the broken cleanup branch that called `file_exists()` / `unlink()` on an integer attachment id (always failed silently, left temp files on disk).
- **Customer note suffix**: `"\n\r"` changed to the correct `"\r\n"`.
- **Custom mapping parser**: skips malformed pairs (no colon, empty key, empty value) instead of triggering an undefined-index notice on PHP 8.
- **Dead code removal**: deleted two dead public methods on the request class (`send_api_request`, `send_api_request_on_completed` - the originals from the kigokasa scaffolding, never hooked here), the unused `removeDir` helper, and ~295 lines of dead WooCommerce settings tab code in the admin class that nothing hooks since 2.1.0.

## [2.1.12] - 2026-05-13

### Fixed
- **CRITICAL**: plugin failed to activate on a fresh install with `Call to undefined method YahnisElsts\PluginUpdateChecker\v5p6\Vcs\PluginUpdateChecker::setCheckPeriod()` (PHP fatal). The bug was introduced in 2.1.10. `plugin-update-checker` has no `setCheckPeriod()` setter - the check interval has to be passed as the 4th argument to `PucFactory::buildUpdateChecker()`. Switched to that signature.
- Wrapped optional method calls (`setBranch`, `getVcsApi`, `enableReleaseAssets`) in `method_exists()` guards so any future PUC API change degrades gracefully instead of fatal-erroring.

## [2.1.11] - 2026-05-12

### Changed
- Repository home moved from `github.com/dpotocic` to `github.com/symmetria-doo`. All plugin headers (Plugin URI, Update URI, Author URI, `@link`), About-tab links, README and CHANGELOG release URLs, the `plugin-update-checker` target and `composer.json` package name now point at the `symmetria-doo` organisation. Existing installations keep updating through GitHub's permanent redirect; this release just makes the new home canonical in the code.

## [2.1.10] - 2026-05-12

### Changed
- Auto-update check interval lowered from the `plugin-update-checker` default of 12 hours to 1 hour. This plugin is self-hosted via GitHub Releases (no wordpress.org throttle concerns), so faster propagation of fixes is worth the marginal API traffic. Admins still get the same red badge on the Plugins menu and the "Update available" banner — just within an hour of a new release instead of within a day.

## [2.1.9] - 2026-05-12

### Notes
- Test release to verify the end-to-end auto-update path: tag is pushed, GitHub Actions builds and attaches the zip, `plugin-update-checker` on installed sites picks it up and surfaces "Update available" in the WP Plugins screen. No functional changes.

## [2.1.8] - 2026-05-12

### Added
- GitHub Actions release workflow (`.github/workflows/release.yml`). Every pushed `vX.Y.Z` tag now triggers an automated build that:
  - Produces a clean `kigocloud-for-woocommerce.zip` with the right plugin folder structure WordPress expects (`kigocloud-for-woocommerce/...`, no version suffix).
  - Excludes dev-only files (`.git/`, `.github/`, `.idea/`, `node_modules/`, `.gitignore`, `composer.lock`, `CONTRIBUTING.md`).
  - Creates a fresh GitHub Release for the tag with auto-generated notes.
  - Attaches the zip as a release asset so `plugin-update-checker` on installed sites can pull it directly.
- Releases are now zero-touch: `git tag vX.Y.Z && git push origin vX.Y.Z` is enough.

## [2.1.7] - 2026-05-12

### Added
- Top-level `CHANGELOG.md` (this file), following the Keep-a-Changelog format. Linked from `README.md` and from the admin About tab.

## [2.1.6] - 2026-05-12

### Fixed
- Reverted the `X-Username` / `X-Password` auth header rename. The KigoCloud server reads incoming headers via `apache_request_headers()` and matches the literal keys `HTTP_X_USERNAME` / `HTTP_X_PASSWORD` (see `protected/modules/v1/components/RestController.php` line 102). Switching to the standard `X-Username` form was wrong against this backend - the server did not see those headers and returned `"Unauthorized. No username/password."`. Both real-order push and the Test push button now use the literal `HTTP_X_USERNAME` / `HTTP_X_PASSWORD` header names again.

### Notes
- A companion server-side change in `protected/modules/v1/components/RestController.php` normalizes incoming header names to a single canonical form, so the backend now accepts `HTTP_X_USERNAME`, `http_x_username`, `Http-X-Username` (Cloudflare-rewritten), and `X-Username` interchangeably. This unblocks the path through `app.kigo.cloud` (which routes via Cloudflare and rewrites `_` to `-` on inbound headers).

## [2.1.5] - 2026-05-12

### Fixed
- R1 fields are now actually registered on the block checkout. Root cause: the `woocommerce_init` hook was wrapped in a `Woo_KigoCloud_R1::block_supported()` check that tested `WC_VERSION`. Plugins load alphabetically and `kigocloud-` < `woocommerce-`, so at hook-registration time `WC_VERSION` was not yet defined and the check returned false, silently preventing the hook from being added. The hook is now registered unconditionally; the handler itself validates WC availability when it fires.

## [2.1.4] - 2026-05-12

### Changed
- Admin: every tab is now pre-rendered in the DOM. Switching tabs is an instant show/hide (no fetch, no reload). One **Save All** button at the bottom posts the entire admin in a single `options.php` request, persisting every option across every tab in one go.
- Sticky save bar at the bottom of the form so the Save button is always visible regardless of how far down a long tab the user scrolls.

### Added
- Additional row in the R1 diagnostics panel listing the Gutenberg blocks found on the WooCommerce checkout page, so it is obvious when the page is missing a `woocommerce/checkout` block.

## [2.1.3] - 2026-05-12

### Added
- Block-checkout diagnostics panel on the R1 admin tab. Shows WC version, whether the field API function exists, whether the registration hook fires this request, live registration status, and the registered field IDs. If the fields are missing from the checkout, the red row in this panel points to the cause.

### Changed
- Simplified block-checkout R1 field registration to the documented minimum (`id`, `label`, `location`, `type`, `required`) so WooCommerce can no longer silently reject the registration over an unexpected key.
- Sanitize and validate moved off the closure args and onto the dedicated `woocommerce_sanitize_additional_field` / `woocommerce_validate_additional_field` filters.
- Field IDs renamed from `kigocloud/r1_vat_number` to `kigocloud/r1-vat-number` (hyphens) to match the API's id pattern conservatively.

## [2.1.2] - 2026-05-12

### Added
- AJAX tab switching on the KigoCloud admin page. Clicking a tab fetches the new tab content via XHR, swaps it in place and updates the URL. Browser Back / Forward work via `history.pushState`. No jQuery, no build. (Superseded by the instant pre-render swap in 2.1.4.)
- R1 admin tab now has a **live preview pane** that mirrors what the customer will see on the checkout based on the current mode (optional vs required, OIB only vs full block).
- R1 admin tab has a **Send test R1 invoice** button. Posts a synthetic invoice to KigoCloud with the saved credentials and shows the raw response inline. Test results are also written to the Logs tab.
- R1 admin tab has a **Force billing company required** option. When enabled, marks the standard WooCommerce `billing.company` field as required everywhere (block and classic checkout, Customer Account address forms).

## [2.1.1] - 2026-05-12

### Fixed
- R1 fields (OIB / company) now actually render on the block checkout. The previous registration silently failed because it passed unsupported keys (`show_in_order_confirmation`, `attributes.pattern`) to the Additional Checkout Fields API.
- Validation moved from a separate filter into the `sanitize_callback` / `validate_callback` args so it follows the documented API contract.
- R1 mode 2 now correctly marks OIB and company name fields as **required** on the block checkout (was optional in 2.1.0).

## [2.1.0] - 2026-05-12

### Added
- New dedicated KigoCloud admin page (top-level menu) with proper nav-tab navigation: **Connection / Orders / R1 / Email / Mapping / Logs / About**.
- Per-gateway settings render as a single table instead of a vertical wall of inputs.
- **Logs** tab with the last 50 KigoCloud API calls (success/error indicator, order link, message).

### Changed
- Declares WooCommerce HPOS and cart/checkout block compatibility so WC no longer flags the plugin as incompatible.
- Removed the legacy WooCommerce settings tab; all configuration lives on the standalone KigoCloud page.

## [2.0.0] - 2026-05-11

### Added
- Initial KigoCloud for WooCommerce release, forked from `kigokasa-api-for-woocommerce` 1.7.2.
- Block checkout (Gutenberg) support for R1 fields on WooCommerce 8.6+.
- GitHub Releases auto-update via `plugin-update-checker`.
- Cleaner WooCommerce settings layout with grouped sections.

### Changed
- Default API endpoint switched to `https://app.kigo.cloud/hr/api/v1/`.
- Minimum PHP raised to 7.2.
- Minimum WooCommerce raised to 5.0.

### Notes
- For changes prior to 2.0.0 see the [kigokasa-api-for-woocommerce](https://wordpress.org/plugins/kigokasa-api-for-woocommerce/) history.

[2.1.14]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.14
[2.1.13]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.13
[2.1.12]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.12
[2.1.11]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.11
[2.1.10]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.10
[2.1.9]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.9
[2.1.8]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.8
[2.1.7]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.7
[2.1.6]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.6
[2.1.5]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.5
[2.1.4]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.4
[2.1.3]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.3
[2.1.2]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.2
[2.1.1]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.1
[2.1.0]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.1.0
[2.0.0]: https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/tag/v2.0.0
