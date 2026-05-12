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
- In-place auto-update against GitHub Releases

## Installation

1. Download the latest release zip from [Releases](https://github.com/dpotocic/kigocloud-for-woocommerce/releases).
2. In WordPress admin: Plugins -> Add New -> Upload Plugin -> choose the zip -> Install.
3. Activate the plugin.
4. WooCommerce -> Settings -> KigoCloud tab. Fill in API credentials.

After installation the plugin checks for updates against GitHub Releases automatically.

## Configuration

API endpoint defaults to `https://app.kigo.cloud/hr/api/v1/`. To point at a different server, set the `kigocloud_api_url` option (override available from the Connection section under WooCommerce -> Settings -> KigoCloud).

## Development

```bash
git clone git@github.com:dpotocic/kigocloud-for-woocommerce.git
```

No build step is required for the core plugin. See `CONTRIBUTING.md` for the release workflow.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

## License

GPL-2.0+. See `LICENSE` for details.

## Credits

Forked from `kigokasa-api-for-woocommerce` originally authored by Dejan Potocic at Symmetria d.o.o. The KigoCloud line is maintained by Dejan Potocic at [github.com/dpotocic](https://github.com/dpotocic).
