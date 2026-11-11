# WooComPort

WooComPort is an independent WooCommerce helper plugin that imports basic product information from public product pages and saves reviewed products as drafts.

## Current MVP

- Direct product-page URL fetch.
- Best-effort extraction from Open Graph, common meta tags, and Product JSON-LD.
- Category/listing-page scan that collects up to 20 same-host links.
- Editable preview before saving.
- Saves products as **Draft**; it never publishes automatically.
- Requires WordPress 6.2+, PHP 7.4+, and WooCommerce 7.0+.

## Installation

1. Download the repository as a ZIP from **Code → Download ZIP**.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP and activate WooComPort.
4. Open **WooCommerce → WooComPort**.

## Important limitations

This is an early MVP, not a universal scraper. Product sites vary widely, may block automated requests, or render data with JavaScript. The parser currently uses server-rendered HTML metadata and JSON-LD. Category scanning only lists candidate links; each product URL is then previewed individually. Image URLs are stored as source references and are not downloaded into the WordPress media library yet. Always review extracted values, currency, tax, stock, variations, shipping, and image rights before using a product.

The plugin does not connect to or depend on any supplier-specific service. It uses WordPress safe HTTP requests and does not automatically publish products.

## Development

Run PHP syntax checks locally:

```sh
php -l woo-com-port.php
```

GitHub Actions runs the same syntax check on pushes and pull requests.
