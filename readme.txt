=== LedgerDirect ===
Contributors: ledgerdirect, alexanderbuzz
Tags: xrpl, xrp, rlusd, usdc, eurc, cryptocurrency, woocommerce
Stable tag: 0.7.0
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.1
License: MIT
License URI: https://opensource.org/license/mit/

Accept XRP, EUR, USD directly on the XRP Ledger, using LedgerDirect!

== Description ==
LedgerDirect is a WordPress plugin that allows you to accept direct payments in XRP, EURC, USDC, and RLUSD on the XRP Ledger. It provides a seamless integration with WooCommerce, enabling merchants to receive payments directly in their XRP Ledger accounts without the need for intermediaries.

== Features ==
- Accept payments in XRP, EURC, USDC, and RLUSD.
- Receive payments directly to your wallet without any intermediaries.

= How do I know if my XRP account is setup correctly? =

The best way is to configure the plugin to use the testnet and make a test payment.

== Installation ==

= Minimum Requirements =

* PHP version 8.1 or greater
* WordPress 6.3 or greater
* WooCommerce 8.6.1 or greater

= Automatic installation =

1. Search for LedgerDirect plugin in the plugin section of your admin panel.
2. Activate the plugin.
3. Go to WooCommerce -> Settings -> Payments and enable the LedgerDirect gateway to manage the plugin settings.
4. Configure the plugin settings, including your XRP Ledger account details and the currencies you want to

= Manual installation =
1. Download the zip file from https://github.com/ledger-direct/ledger-direct-woocommerce.
2. Upload the zip file inside the WordPress plugin section.
3. Activate the plugin.
4. Go to WooCommerce -> Settings -> Payments and enable the LedgerDirect gateway to manage the plugin settings.
5. Configure the plugin settings, including your XRP Ledger account details and the currencies you want to accept.

== Test Payments ==

To test the plugin, you can configure it to use the XRP Ledger Testnet. This allows you to simulate transactions without using real funds. Follow these steps:
1. Go to the plugin settings in WooCommerce.
2. Enable the Testnet mode.
3. Use a test XRP Ledger account to make test payments.
5. You can create test account from https://xrpl.org/xrp-testnet-faucet.html for XRP or https://tryrlusd.com/ for RLUSD.

== External services ==

LedgerDirect uses public APIs from Coinbase, Coingecko, Binance, and Kraken to retrieve current cryptocurrency exchange rates. These rates are needed to correctly calculate and display payments.

No personal or payment data is sent to these services. Only requests for current rates are made when a payment is processed or displayed.

For more information about each service, see:
- Coinbase API: [Terms of Service](https://www.coinbase.com/legal/user_agreement), [Privacy Policy](https://www.coinbase.com/legal/privacy)
- Coingecko API: [Terms of Service](https://www.coingecko.com/en/terms), [Privacy Policy](https://www.coingecko.com/en/privacy)
- Binance API: [Terms of Use](https://www.binance.com/en/terms), [Privacy Policy](https://www.binance.com/en/privacy)
- Kraken API: [Terms of Service](https://www.kraken.com/legal), [Privacy Policy](https://www.kraken.com/privacy)

== Frequently Asked Questions ==

== Changelog ==