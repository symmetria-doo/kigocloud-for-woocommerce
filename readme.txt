=== KigoCloud for WooCommerce ===
Contributors: symmetriahr, dpotocic
Tags: fiskalizacija, fiscalization, croatia, invoice, r1
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 2.1.31
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 5.0
WC tested up to: 11.2

Fiskalizacija for WooCommerce: every order becomes a fiscalized Croatian invoice in Kigo.Cloud, R1 invoices included, PDF sent to the customer.

== Description ==

**Fiskalizacija web trgovine bez prepisivanja.** KigoCloud for WooCommerce connects your shop to [Kigo.Cloud](https://www.kigo.cloud/fiskalizacija-web-trgovine/), the Croatian invoicing and fiscalization service by Symmetria d.o.o. When a customer orders and pays, the order is sent to Kigo.Cloud, which issues the invoice, fiscalizes it (ZKI and JIR from the Tax Administration) and the plugin e-mails the PDF to the customer.

Croatian law requires every sale paid by card, cash or a similar method to be fiscalized, web shops included. With this plugin there is nothing to retype: the items, prices, VAT, shipping and the customer come from the WooCommerce order.

= What it does =

* **Fiscalized invoice for every order.** Per payment method you choose the document (invoice or offer), the payment type on the invoice (card, transaction account, cash, other) and when it is created: when the order is placed or when it is completed.
* **R1 invoices for companies.** OIB (VAT number) and company fields on the checkout, with the OIB checksum validated. Works with the classic checkout and the block checkout (WooCommerce 8.6+).
* **Invoice PDF to the customer** by e-mail, straight after the document is created.
* **Invoices in the customer's language.** A customer outside Croatia can get the invoice and the e-mail in English, or in German for Germany, Austria, Switzerland and Liechtenstein.
* **Stock and price sync** with Kigo.Cloud (Business plan): Kigo.Cloud finds your products by SKU and keeps the quantities and prices in step.
* **Anchor price (sidrena cijena)** under the product price, as the Croatian Government decision in force from 1 October 2026 requires, when Kigo.Cloud sends it.
* **Daily price list on your own domain.** The plugin downloads the price list from Kigo.Cloud once a day and serves the CSV and XML from your uploads folder; the `[kigo_cjenik]` shortcode shows it as a table with the last 30 days.
* **Custom field mapping** for shops that already collect R1 data with another plugin.
* **Logs tab** with the last 50 calls, and a test button that sends a test offer (never a real invoice).
* HPOS (custom order tables) compatible.

= About Kigo.Cloud =

Kigo.Cloud is an online program for issuing and fiscalizing invoices, eRačun (Fiscalization 2.0), stock and price lists, developed in Zagreb since 2013. Connecting a web shop and fiscalizing its orders is available from the Standard plan; two-way sync of items, stock and prices and the anchor price in the shop from the Business plan. Registration is free and needs no card. Plans and prices: [www.kigo.cloud](https://www.kigo.cloud/cijene/).

= Try it without an account =

On the Connection tab, the **Try with the demo account** button fills in the login of the public Kigo.Cloud demo. Orders then become invoices in the demo, signed with a test certificate, so you can see the whole flow before you register. Anyone can log in to the demo, so use it only with test orders.

== Installation ==

1. In WordPress go to Plugins > Add New, search for "KigoCloud" and install and activate **KigoCloud for WooCommerce**. WooCommerce must be active.
2. Open **KigoCloud** in the admin menu.
3. On the **Connection** tab enter the username, password and employee PIN of your Kigo.Cloud user, or click **Try with the demo account**.
4. On the **Orders** tab choose the document type for each payment method you want fiscalized (Disabled leaves that payment method alone).
5. Optionally set up R1 fields on the **R1** tab and the sender on the **Email** tab, then click **Save all settings**.

Nothing is sent to Kigo.Cloud until a username and password are saved and at least one payment method has a document type.

== Frequently Asked Questions ==

= Do I need a Kigo.Cloud account? =

Yes, for real invoices. The plugin is the connection between WooCommerce and Kigo.Cloud, which issues and fiscalizes the invoices. Fiscalizing web shop orders is available from the Standard plan. To look around first, use the demo account button.

= Is the invoice really fiscalized? =

Yes. Kigo.Cloud sends the invoice to the Croatian Tax Administration (Porezna uprava) and puts the ZKI and JIR on it, with the certificate you upload to Kigo.Cloud. Invoices in the demo account are signed with a test certificate.

= Can a company get an R1 invoice? =

Yes. Turn on the R1 fields on the R1 tab: the buyer enters the OIB (and, in full mode, the company name and address) on the checkout, and the invoice is issued to the company.

= Does it work with the block checkout? =

Yes, from WooCommerce 8.6, and with the classic `[woocommerce_checkout]` checkout.

= Which payment methods are supported? =

Every payment method active in WooCommerce: bank transfer, cash on delivery, card gateways (Stripe, CorvusPay, Monri, PayPal and others). Each gets its own settings on the Orders tab.

= Can the invoice be in English or German? =

Yes. On the Orders tab, Language for foreign customers: English for everyone outside Croatia, or by country (German for DE, AT, CH and LI).

= I used the KigoKasa API for WooCommerce plugin. What happens? =

KigoCloud copies its settings on activation and does not send orders while the KigoKasa plugin is active, so no order is fiscalized twice. A notice offers a button that deactivates the old plugin; from then on KigoCloud sends the orders.

= Where do I get help? =

Write to the support forum here or to the e-mail address on [www.kigo.cloud](https://www.kigo.cloud/).

== External services ==

This plugin connects your shop to Kigo.Cloud (https://app.kigo.cloud), an invoicing and fiscalization service run by Symmetria d.o.o., Zagreb, Croatia. It is needed to issue and fiscalize the invoices; without it the plugin does nothing. Nothing is sent before you save a Kigo.Cloud username and password (or the demo login) in the plugin settings.

What is sent, and when:

* **Order to invoice** (`https://app.kigo.cloud/hr/api/v1/invoice/create`, or `/en/` or `/de/` for a foreign customer): when an order reaches the status you chose for its payment method (placed or completed), and only for payment methods that have a document type. Sent: the order number, the items (name, SKU, quantity, price, VAT rate, discount), shipping, the payment type, the customer note, the customer's name or company name, OIB, address, city, postcode, country, e-mail and phone, the employee PIN, and your Kigo.Cloud username and password (the password as an MD5 hash) for authentication.
* **Invoice PDF** (`https://app.kigo.cloud/hr/api/v1/invoice/getAsPdf/...`): right after the invoice is created, only for payment methods where sending the PDF to the customer is turned on. Sent: the document id and your Kigo.Cloud login. The PDF is e-mailed to the customer from your site.
* **Connection test** (R1 tab button): when you click it. Sends a test offer with made-up buyer data and your Kigo.Cloud login.
* **Price list** (the Kigo.Cloud address you enter on the Price list tab): once a day at 7:00, when you save the address, and when a page with the `[kigo_cjenik]` shortcode finds that day's copy missing. Only the CSV and XML files are downloaded; no data about your shop or customers is sent.
* **Demo account**: if you use the demo login, the same order data goes into the public Kigo.Cloud demo, which anyone can open with that login.

Shops that switched from the KigoKasa API for WooCommerce plugin keep using the KigoKasa server they used before (`https://trgovina.kigoserver.com`, also run by Symmetria d.o.o.) for the same calls, with the same data.

In the other direction, Kigo.Cloud reads products from your shop through the WooCommerce REST API (and this plugin's SKU lookup route, `/wp-json/wc/v3/kigocloud/...`) only if you create WooCommerce REST API keys and enter them in Kigo.Cloud.

Kigo.Cloud terms of use: https://www.kigocloud.com/uvjeti-koristenja
Kigo.Cloud privacy policy: https://www.kigocloud.com/zastita-osobnih-podataka

== Screenshots ==

1. Connection tab: your Kigo.Cloud login, or the demo account in one click.
2. Orders tab: document type, payment type and trigger for each payment method.
3. R1 tab: OIB and company fields on the checkout, with a preview.
4. An order with the fiscalized Kigo.Cloud invoice number.

== Changelog ==

= 2.1.31 =
* Fix: the tax rate of a product, fee and shipping is the rate set in WooCommerce, no longer one worked out from the amounts. On a low price the tax rounded to the cent gave a rate that does not exist (24 % instead of 25 %), and Kigo.Cloud could not fiscalize such a receipt. The price sent is what the buyer paid, tax included, as on the order.

= 2.1.30 =
* Ready for wordpress.org. The settings start empty and nothing is sent to Kigo.Cloud until a login is saved; the new "Try with the demo account" button fills in the public demo login for a test run.
* Security: the connection to Kigo.Cloud checks the server certificate, and debug logging no longer writes the API login to debug.log.
* Settings are sanitized when saved, and the price list files are written through the WordPress file system API.

= 2.1.29 =
* Fix: Cheque is no longer offered as a payment type, because KigoCloud does not accept it and every order with it was refused. A gateway that still has Cheque saved sends its orders as Other.
* Tested up to WordPress 7.1 and WooCommerce 11.2.

= 2.1.28 =
* German for foreign customers. The option on the Orders tab is now called Language for foreign customers and has a new choice: by country. With it a customer from Germany, Austria, Switzerland or Liechtenstein gets the invoice PDF and the e-mail in German, and every other customer outside Croatia in English. Shops that already use English keep it until they change the setting.
* The order note and the shipping line on an English or German invoice are in that language as well.

= 2.1.27 =
* Fix: with the full R1 block on the classic checkout, a buyer who does not ask for an R1 invoice keeps the address on the document. Until now the empty company fields replaced it.

= 2.1.26 =
* Fix: the connection test on the R1 tab sends a test R1 offer. Until now it issued a real invoice in KigoCloud.
* Fix: "Fill empty SKU" builds the SKU from the product, so the same product no longer becomes a new item in KigoCloud with every order.

= 2.1.25 =
* Croatian: the R1 fields and messages on the checkout are translated (OIB field, company fields, validation messages).

= 2.1.24 =
* Fix: with the trigger "Order Creation" the document is also created when the order reaches a paid status by another route (a failed payment paid on the second try, a block checkout draft, an order completed at once).
* Fix: with taxes switched off in WooCommerce no tax rate is sent, so the document uses the tax rate of the item in KigoCloud instead of 0 %.

= 2.1.23 =
* Fix: a buyer who enters an OIB is sent as a company again, as before 2.1.22, so the invoice keeps the R1 label.

= 2.1.22 =
* Fix: two status changes arriving together can no longer create two documents for one order.
* Fix: an order whose first attempt timed out is linked to the document KigoCloud already created.
* Fix: SKU lookup accepts SKUs with a dot, a space and other characters.
* Fix: an order with a product deleted in the meantime is sent instead of stopping with an error.
* Change: a buyer with an OIB but without a company name is sent as a private person.

= 2.1.21 =
* English for foreign customers. A new option on the Orders tab: when it is on, a customer whose country on the order is not Croatia gets the invoice PDF and the e-mail in English. Customers from Croatia get the same as before. Off by default.
* Translations are now part of the release, so the e-mail and the order note show the document type (for example Invoice or Račun) instead of pos_type_1.

= 2.1.20 =
* Once the KigoKasa API for WooCommerce plugin is deactivated, by the Switch to KigoCloud button or by hand, KigoCloud copies the KigoKasa document number, type and id and the R1 company details of every order into its own order fields, in the background and in batches. From then on it no longer depends on the old plugin, so the old plugin can be deleted, and a notice says when the copy is done. Versions before KigoKasa 1.7.5 delete that order data when the plugin is deleted.

= 2.1.19 =
* Switching from the KigoKasa API for WooCommerce plugin. When KigoCloud is activated, it copies the KigoKasa settings (connection, per payment method document types, R1 mode, mail, mapping) as long as KigoCloud has no username of its own yet, and keeps the KigoKasa server as the endpoint for those credentials.
* While the KigoKasa plugin is active, KigoCloud does not send orders, so no order is sent twice. A notice in wp-admin offers the Switch to KigoCloud button, which deactivates the KigoKasa plugin (its data is kept). From then on KigoCloud sends the orders.
* Orders already sent by the KigoKasa plugin are recognised and never sent again, their R1 details are used if they are sent now, and the order screen shows their KigoKasa document number.
* Author shows only Symmetria d.o.o.

Older versions: https://github.com/symmetria-doo/kigocloud-for-woocommerce/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 2.1.31 =
The tax rate sent to Kigo.Cloud is the rate set in WooCommerce, so receipts for low prices are fiscalized.

= 2.1.30 =
New installs start with empty settings and a demo account button. Existing settings are kept.
