# KigoCloud for WooCommerce

WordPress plugin that sends WooCommerce orders to the KigoCloud service. Handles R1 invoices (Croatian VAT receipts), fiscalization, and inventory synchronization.

## Requirements

- WordPress 5.5+
- WooCommerce 5.0+
- PHP 7.2+

## Features

- Sends WooCommerce orders to KigoCloud (`app.kigo.cloud`) as invoices or offers
- Per-gateway document type and payment method mapping
- Per-gateway choice of when to trigger the API call (Order Created / Order Completed)
- R1 (B2B) customer fields on checkout: company name, address, city, postcode, OIB (VAT number)
- Both classic checkout (shortcode) and block checkout (Gutenberg) supported. Uses the WooCommerce Additional Checkout Fields API on WC 8.6+
- Custom meta-to-billing mapping for sites that already collect R1 data through other plugins
- Sends invoice PDF to the customer by email after successful KigoCloud document creation
- Custom REST endpoints for SKU lookup (product + variation)
- Updates through wordpress.org; installs from the GitHub release zip update in place from GitHub Releases

## Installation

KigoCloud for WooCommerce is the official plugin in the WordPress Plugin Directory: [wordpress.org/plugins/kigocloud-for-woocommerce](https://wordpress.org/plugins/kigocloud-for-woocommerce/). Install it straight from WordPress, with no file to download:

1. In WordPress admin: Plugins -> Add New.
2. Search for "KigoCloud".
3. At KigoCloud for WooCommerce click Install Now, then Activate.
4. Open the KigoCloud item in the left WordPress menu and fill in the API credentials on the Connection tab.

For manual installation, download the ZIP from [GitHub Releases](https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases/latest/download/kigocloud-for-woocommerce.zip) or the [WordPress.org plugin page](https://wordpress.org/plugins/kigocloud-for-woocommerce/), then use Plugins -> Add New -> Upload Plugin -> Install Now -> Activate.

WordPress offers new versions like for any other plugin. If you installed the plugin earlier from the zip on [GitHub Releases](https://github.com/symmetria-doo/kigocloud-for-woocommerce/releases), you don't need to change anything: that installation keeps updating itself from GitHub Releases.

## Configuration

API endpoint defaults to `https://app.kigo.cloud/hr/api/v1/`. It is not editable from the admin screen. To point at a different server, define `WOO_KIGOCLOUD_API_URL` in `wp-config.php` or use the `kigocloud_api_url` filter; a `kigocloud_api_url` option stored by an earlier version is still honoured.

## Development

```bash
git clone git@github.com:symmetria-doo/kigocloud-for-woocommerce.git
```

No build step is required for the core plugin. See `CONTRIBUTING.md` for the release workflow.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

## License

GPL-2.0+. See `LICENSE` for details.

## Credits

Forked from `kigokasa-api-for-woocommerce` originally authored by Dejan Potocic at Symmetria d.o.o. The KigoCloud line is maintained by Dejan Potočić at Symmetria d.o.o. on [github.com/symmetria-doo](https://github.com/symmetria-doo).
