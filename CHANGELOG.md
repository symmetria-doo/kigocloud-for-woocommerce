# Changelog

All notable changes to KigoCloud for WooCommerce are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
