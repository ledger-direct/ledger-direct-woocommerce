=== Ledger Direct ===
Contributors: ledgerdirect, alexanderbuzz
Tags: xrpl, xrp, rlusd, usdc, xahau
Stable tag: 1.7.3
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
License: MIT
License URI: https://opensource.org/license/mit/

Accept XRP, EUR, USD directly on the XRP Ledger, using LedgerDirect!

== Description ==
LedgerDirect is a WordPress plugin that allows you to accept direct payments in XRP, USDC, and RLUSD on the XRP Ledger, and in XAH on Xahau. It provides a seamless integration with WooCommerce, enabling merchants to receive payments directly in their XRP Ledger accounts without the need for intermediaries.

== Features ==
- Accept payments in XRP, USDC and RLUSD on the XRP Ledger, and in XAH on Xahau.
- Receive payments directly to your wallet without any intermediaries.

= How do I know if my XRP account is setup correctly? =

The best way is to configure the plugin to use the testnet and make a test payment.

== Installation ==

= Minimum Requirements =

* PHP version 8.2 or greater
* WordPress 6.7 or greater
* WooCommerce 8.6.1 or greater

= Automatic installation =

1. Search for LedgerDirect plugin in the plugin section of your admin panel.
2. Activate the plugin.
3. Go to WooCommerce -> Settings -> Payments and enable the LedgerDirect gateway to manage the plugin settings.
4. Configure the plugin settings, including your XRP Ledger account details and the currencies you want to

= Manual installation =
1. Clone the repo from https://github.com/ledger-direct/ledger-direct-woocommerce into the 'plugins' directory.
2. Run 'composer install --no-dev --no-scripts --optimize-autoloader' to install the composer dependencies.
3. Activate the plugin.
4. Go to WooCommerce -> Settings -> Payments and enable the LedgerDirect gateway to manage the plugin settings.
5. Configure the plugin settings, including your XRP Ledger account details and the currencies you want to accept.

== Test Payments ==

To test the plugin, you can configure it to use the XRP Ledger Testnet. This allows you to simulate transactions without using real funds. Follow these steps:
1. Go to the plugin settings in WooCommerce.
2. Enable the Testnet mode.
3. Use a XRP Ledger test account to make test payments.
5. You can create test account from https://xrpl.org/xrp-testnet-faucet.html for XRP or https://tryrlusd.com/ for RLUSD.

== External services ==

LedgerDirect uses public APIs from Coinbase, Coingecko, Binance, Kraken and Bitrue to retrieve current cryptocurrency exchange rates (Bitrue for XAH alone). These rates are needed to correctly calculate and display payments.

No personal or payment data is sent to these services. Only requests for current rates are made when a payment is processed or displayed.

For more information about each service, see:
- Coinbase API: [Terms of Service](https://www.coinbase.com/legal/user_agreement), [Privacy Policy](https://www.coinbase.com/legal/privacy)
- Coingecko API: [Terms of Service](https://www.coingecko.com/en/terms), [Privacy Policy](https://www.coingecko.com/en/privacy)
- Binance API: [Terms of Use](https://www.binance.com/en/terms), [Privacy Policy](https://www.binance.com/en/privacy)
- Kraken API: [Terms of Service](https://www.kraken.com/legal), [Privacy Policy](https://www.kraken.com/privacy)
- Bitrue API: [Terms of Use](https://support.bitrue.com/hc/en-001/articles/4405473110681-Bitrue-Terms-of-Use), [Privacy Policy](https://support.bitrue.com/hc/en-001/articles/4405473185945-Bitrue-Privacy-Policy)

== Source Code of minified assets ==

The payment page's script and stylesheet (`public/js/ledger-direct-payment-ui/payment-page.js`, `wallets.js`, `public/css/payment-page.css`) are the built files of the package `@ledger-direct/payment-ui`, MIT, whose source and build are at https://github.com/ledger-direct/ledger-direct-payment-ui — the tag is in `public/js/ledger-direct-payment-ui/VERSION`. `wallets.js` bundles XRPL Connect (https://github.com/XRPL-Commons/xrpl-connect) and xrpl.js. The checkout block script `includes/assets/js/frontend/blocks.js` is built from `resources/js/frontend/index.js` with @wordpress/scripts.

== Frequently Asked Questions ==

== Changelog ==

= Unreleased =
* The payment page uses @ledger-direct/payment-ui 0.6.0 instead of 0.5.0. WalletConnect works and has its own button: with a WalletConnect project id, "Pay with a wallet app (WalletConnect)" sits above "Pay with a browser wallet", on every screen width, and opens WalletConnect's dialog with the code to scan; before, the click showed nothing to scan. The wallet app is told the shop's name and site icon instead of the host name. The phone's "Open in wallet app" button is Xaman's alone now; before, it opened WalletConnect when no Xaman key was set. The receiving address and the destination tag can be shown as QR codes of their own, for wallets whose scanner fills one field at a time (Joey Wallet reads no payment request). The QR heading reads "Scan with Xaman", and so does the start of the XRPL caption; the caption is centred under the code, the "or" between the alternatives is larger, the wallet message is centred. The wallet script is unchanged; the page script and the stylesheet change.
* The payment page uses @ledger-direct/payment-ui 0.5.0 instead of 0.4.2. Joey Wallet's browser extension is offered in the wallet list when it is installed; it connects on the page's network, pays nothing on another, and a connection Joey ends (access revoked, account gone, network switched) is forgotten, so the next click asks Joey again. A wallet error (another network, a wallet that does not respond, a failed payment) now shows as a warning box instead of grey text under the hint, where it went unseen. The wallet script is unchanged; the page script and the stylesheet change.

= 1.7.3 =
* The tag 1.7.2 was set on the commit before the release commit, so it carries version 1.7.1 and never reached WordPress.org; tags cannot be moved. 1.7.3 is the same code with its version in place.
* The payment page uses @ledger-direct/payment-ui 0.4.2 instead of 0.4.0, which fixes partial payments in the wallet flow: after a partial payment, a wallet button opens the payment for the rest instead of reloading the page again and again; an XRP payment whose amount changed while the wallet was connecting reloads instead of signing the old amount; a page that turned paid or expired meanwhile signs nothing; and an older status answer arriving late no longer turns the page back. After payment the page goes to the order confirmation once. The wallet script and the stylesheet are unchanged.

= 1.7.1 =
* The release build of 1.7.0 refused the plugin: the receiving-account notices on the settings page named two classes of the bundled core where the build does not prefix them. They are now built where it does. 1.7.0 never reached WordPress.org; its changes come with this release.

= 1.7.0 =
* Core 0.12.0: the checkout offers only what the receiving account can take, in the classic and the block checkout. A token the account cannot receive (no trust line, frozen, full) is not offered, so no order exists that nobody can pay. An account that is not activated yet keeps the native asset, and the settings page warns.
* The settings page shows what each chain's saved receiving account lacks, above the settings, which stay as they are.
* The bundled translations are loaded. Before, customer pages were English even in German shops unless a WordPress.org language pack was installed.
* Every text in six languages: English, German, French, Dutch, Spanish and European Portuguese. French, Dutch, Spanish and Portuguese are new, also for the block checkout. The terms follow the LedgerDirect glossary (German: "Herausgeber" for issuer, "Destination Tag", "an der Kasse").
* The gateway's description, the default payment page title and the title of the page created on activation are translated; they were English.
* The order panel names a native amount with the order's chain: XAH for a Xahau order. It printed XRP for every chain.

= 1.6.0 =
* Core 0.11.3: the payment request in the QR code and behind the Xaman button now names its network on the XRP Ledger too, as it already did on Xahau. Before, Xaman paid on whichever network the wallet had open, so an XRP payment scanned with a Xahau account opened a XAH payment of the same amount.
* The payment page uses @ledger-direct/payment-ui 0.4.0 instead of 0.1.1: the wallet library is xrpl-connect 1.0.0 with xrpl 5, and on Xahau and Stellar the page no longer offers the XRP Ledger's browser wallets.
* The wallet library's URL carries its file version, so a browser does not combine a copy cached before an update with a newer page script.

= 1.5.2 =
* Core 0.11.1: when the price oracles disagree, no price is quoted, instead of a rate up to five minutes old. An older rate stands in only when no oracle answers.
* Core 0.11.1: after a reset of the XRP Ledger testnet, only the first sync per receiving account sends a request the node rejects; the plugin remembers the reset, so later syncs continue without it.
* When no price is available for a new amount on an expired payment page, the page now says so and asks the customer to try again in a moment. Before, the button seemed to do nothing.

= 1.5.1 =
* An order is booked as paid exactly once. Overlapping checks of the same order could repeat the confirmation mails and the stock reduction.

= 1.5.0 =
* Xahau: XAH on the Xahau network, a chain of its own that speaks the XRP Ledger's protocol, paid by destination tag. New settings "Accept XAH on Xahau", the Xahau network and one receiving account per network, off by default; the checkout offers XAH once they are set. The payment request behind the QR code names the Xahau network, so Xaman offers to switch if it is set to another one; the browser-wallet buttons stay off on a Xahau page. Xahau has its own tables (ledger_direct_xahau_tx, ledger_direct_xahau_destination_tag, created on update) and shares nothing with the XRP Ledger, also for an account that exists on both. The background job syncs the configured account of each chain. Saving a Xahau account that is also the XRP Ledger's account on the same network shows a notice: the same address is a different account on each chain. Needs core 0.11.
* The payment types come from the core's chain catalogue: one per chain and asset, and a test that turns red when the core adds a chain the plugin has not decided on.

= 1.4.1 =
* Core 0.9: the synced transactions store the CTID in the column tx_ctid instead of ctid, the name every LedgerDirect plugin uses from now on (PostgreSQL reserves ctid for a system column). The column is renamed in place on the first request after the update; nothing changes for customers or merchants.
* Core 0.9: a transaction whose CTID is malformed is skipped during sync, and logged, instead of being stored truncated.

= 1.4.0 =
* New order status "XRPL payment incomplete": a payment that arrives but does not pay the order (too little, or another token) moves the order there, with a note saying what arrived, what was requested and what is still due. The order stays open, the page keeps polling, the background job keeps matching, and a top-up settles it. WooCommerce's hold-stock cancellation, which only cancels "Pending payment", leaves such orders alone.
* A LedgerDirect panel on the admin order page: the payment state, what was quoted (asset, amount, rate, account, destination tag, issuer, quote validity), what arrived, what is still due, the transaction, and every transaction on the order's destination tag linked to the explorer.
* The receiving accounts are validated as XRPL addresses when the settings are saved; another chain's address or a destination tag in the field is refused and the previous value kept.
* Core 0.8.1: a payment in the other asset class, a token sent for an XRP order or XRP for a token order, is reported as a wrong-token payment with the full amount still due, instead of being ignored. Before, the page stayed on "waiting" while the money sat on the ledger.

= 1.3.0 =
* The payment page is redesigned on the shared package @ledger-direct/payment-ui: the amount to send is the largest thing on the page with a copy button, the receiving address, the destination tag (marked as required) and, for tokens, the issuer are numbered fields with copy buttons, a countdown with a bar, one column on phones, dark mode follows the system.
* One QR code with the receiving address, the destination tag and the amount (for tokens also currency and issuer); a server-rendered code stays for browsers without JavaScript.
* Browser wallets over XRPL Connect (Crossmark, GemWallet, MetaMask Snap, Ledger, Otsu, Xyra, Joey) when detected; Xaman and WalletConnect when the merchant enters their public identifier in the settings. The wallet library is loaded only when the wallet list is opened.
* New settings: the logo of the payment page (site logo, a picture from the media library, or a monogram), an accent colour (refused when too light for white text), the Xaman API key and the WalletConnect project id.
* The plugin's scripts and styles load on the payment page only, no longer on every page of the site.
* Icons for XRP, RLUSD and USDC next to the payment options in the checkout.
* Requires hardcastle/ledger-direct-core 0.8.

= 1.2.0 =
* The payment page now follows the payment on its own: it polls every 8 seconds and shows one of five states - waiting (with a countdown for the quote), partial payment, payment in the wrong token, expired quote, and paid - and leaves for the order confirmation as soon as the order is paid or closed. No more reloading.
* New status endpoint `/wp-json/ledger-direct/v1/payment-status/<order key>`, the same payload as every other LedgerDirect plugin. Access is by the order key, as on the "Order received" page, so guest orders work without a login.
* The ledger is synced at most once per five seconds per receiving account, however many customers are waiting; the background job is unaffected.
* An expired quote is no longer replaced silently on reload; the page says so and offers an updated amount on request.
* Whether a payment is in the wrong token is decided by the shared library, the same rule as on every other platform.

= 1.1.1 =
* Fix: an order that received a partial payment could never be completed. The first payment was recorded and the plugin stopped looking, so a customer who then sent the remaining amount was never marked as paid, neither on the payment page nor by the background job. Payments in the quoted asset now add up, and the order is matched until it settles.
* Fix: a payment in the right stablecoin from another issuer no longer blocks a later payment from the correct issuer.
* Core 0.7: the shared library is now required at ^0.7.
* Release build: a developer document that had been copied into the bundled library was shipped with 1.1.0; it is removed, and the build now refuses to ship such files.

= 1.1.0 =
* Core 0.4: the sync cursor is now kept per receiving account and network. A mainnet transaction can no longer stall the testnet sync, and a testnet reset no longer requires cleaning the database - the plugin resyncs on its own.
* Core 0.3: when several transactions carry the same destination tag, the newest one in the quoted asset is used. A stray payment in another asset no longer blocks settlement of the real one.
* Database schema version 3: `network` column on the transactions table, backfilled from the CTID; migrates automatically on the first request after the update.

= 1.0.0 =
* The plugin is now an adapter over the shared `hardcastle/ledger-direct-core` library, so prices, exchange rates, destination tags, transaction sync and the settlement decision are identical across all LedgerDirect plugins.
* Fix: XRP payments were only accepted when the delivered amount was at or below the requested amount. Overpayment now settles, and a shortfall of up to 0.15 % is tolerated, as in every other LedgerDirect plugin.
* Fix: stablecoin payments were compared as raw arrays; a payment from another issuer is now always rejected and an overpayment accepted.
* Fix: the RLUSD and USDC switches were wired to the wrong asset depending on the network.
* Fix: the quote expiry setting was ignored (every quote lasted 15 hours). Quotes now expire as configured and are refreshed in place on the payment page, keeping the same destination tag.
* New: orders are settled in the background every five minutes via Action Scheduler, so a customer who closes the payment page still gets their order marked paid.
* New: the payment page shows how much of the requested amount has arrived and what is still missing.
* New: exchange rates are cached briefly (60 s) so a checkout render no longer waits for three price oracles.
* Database schema version 2: numeric ledger index, per-account destination-tag counter; migrates automatically on the first request after the update.
* Requires PHP 8.2.

= 0.11.0 =
* Fix: the destination-tag table existed under three different names across install.php and XrplTxService, so reserved destination tags were silently never persisted on fresh installs. Unified to `ledger_direct_xrpl_destination_tag`, with an automatic upgrade routine for existing installs.
* Fix: add a unique constraint on the transactions table's `hash` column to enforce deduplication at the database level.
* Fix: KrakenOracle only ever returned a price for the XRP/USD pair (it read a hardcoded response key); it now reads any pair generically.
* Fix: price oracle failures are now logged instead of silently discarded, to make pricing issues diagnosable.
* Fix: the "change payment method" AJAX action always failed due to a typo reading the wrong POST field.
* Add: order pricing metadata now includes `base_asset` and `quote_currency` fields alongside the existing `pairing`/`exchange_rate`/`amount_requested`.
* Remove: the unused, always-returns-zero RippleOracle.
* Internal: added automated tests, a CI pipeline (lint, coding standards, static analysis, PHPUnit, WordPress Plugin Check), and a WordPress.org SVN release workflow.