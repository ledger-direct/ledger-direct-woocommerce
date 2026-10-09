# LedgerDirect for WooCommerce

[![CI](https://github.com/ledger-direct/ledger-direct-woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/ledger-direct/ledger-direct-woocommerce/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/ledger-direct.svg)](https://wordpress.org/plugins/ledger-direct/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/ledger-direct.svg)](https://wordpress.org/plugins/ledger-direct/)
[![WordPress Plugin Rating](https://img.shields.io/wordpress/plugin/stars/ledger-direct.svg)](https://wordpress.org/plugins/ledger-direct/#reviews)
[![Tested up to WordPress](https://img.shields.io/wordpress/plugin/tested/ledger-direct.svg)](https://wordpress.org/plugins/ledger-direct/)
[![Requires PHP](https://img.shields.io/wordpress/plugin/required-php/ledger-direct.svg)](https://wordpress.org/plugins/ledger-direct/)

Accept XRP, RLUSD and USDC directly on the XRP Ledger — no payment processor, no custody, funds
land in the merchant's own wallet.

The plugin is the WooCommerce adapter over
[`hardcastle/ledger-direct-core`](https://packagist.org/packages/hardcastle/ledger-direct-core), the
shared package that holds the XRPL and pricing logic. Everything platform-specific — the payment
gateway, order storage, the payment page, the settings screen, the background job — lives here;
price conversion, the oracle set, the stablecoin registry and ledger sync live in the core and are
not reimplemented.

Project website: https://www.ledger-direct.com · Plugin directory: https://wordpress.org/plugins/ledger-direct/

![Payment Page](payment_page.png)

## How it works

A customer picks XRP, RLUSD or USDC at checkout — in the classic checkout or the block checkout,
both are supported. The order is placed straight away as **Pending payment** and the customer gets
a payment page: the exact amount, the shop's receiving address, and a **destination tag** unique to
that order. The tag is what ties an incoming ledger transaction back to the order, so one wallet
address serves every customer.

The quote is fixed for a configurable window (fifteen minutes by default). Reloading the page never
changes the amount; once the quote lapses the customer can ask for an updated one, and the
destination tag stays the same so a payment already in flight is not orphaned.

Payments are confirmed three ways, which is deliberate redundancy:

- the payment page polls while the customer is watching,
- a **check now** button for anyone who would rather not wait (and for browsers without JavaScript),
- a background job on WooCommerce's Action Scheduler that settles orders for customers who closed
  the page.

An order is credited only from the ledger's `delivered_amount`, only when what arrived covers the
requested amount, and — for stablecoins — only when the currency and issuer match. Several payments
in the quoted asset add up, so a customer who sent too little can send the rest. Anything else stays
open, and the payment page says why: it shows one of five states — waiting, expired, partial payment
(with the outstanding amount), wrong token (with the full amount still due), or paid — and updates
in place while the customer watches, without reloading.

Once a payment settles the order, WooCommerce's usual `payment_complete()` runs with the transaction
hash as the transaction id: the order moves to *Processing* (or *Completed* for virtual goods), stock
is reduced, and the order emails go out. A payment that arrives but does not pay the order leaves
it *Pending payment*; the plugin does not yet give such orders a status of their own or a panel on
the admin order view, as the PrestaShop and Magento plugins do. Keep that in mind with
WooCommerce's *Hold stock* setting: when stock management is on, WooCommerce cancels pending orders
after that many minutes (60 by default), including one with a partial payment on the ledger.

The page asks the server every 8 seconds. That endpoint syncs with the XRPL node at most once every
5 seconds per receiving account, whatever the number of customers waiting; in between it answers
from what is already stored. A guest order can poll too — the link carries the order key, and no
login is required.

## Requirements

- WordPress 6.7 or newer, WooCommerce 8.6 or newer
- PHP 8.2 or newer
- An XRP Ledger account. Accepting RLUSD or USDC additionally requires a **trustline** to the
  respective issuer on that account, or payments will fail on the ledger.

## Installation

Install the plugin from the WordPress plugin directory (Plugins → Add New → search "LedgerDirect"),
or upload the release zip from the [plugin directory](https://wordpress.org/plugins/ledger-direct/)
there. Activate it through the Plugins menu.

Activating creates two tables for ledger data (synced transactions and the destination-tag counter)
and schedules the settlement job. Deactivating removes the job; uninstalling drops the transaction
table — a re-syncable cache — but deliberately **keeps** the destination-tag counter: it cannot be
reconstructed, and reusing tags would match new orders against old payments.

Upgrading runs the plugin's schema migrations on the next request after the update; WordPress does
not re-fire the activation hook, so nothing has to be clicked.

A checkout of this repository is not installable as it is: the release build bundles and prefixes
the Composer dependencies (see Development). For a development shop, run `composer install` in the
plugin directory first.

## Configuration

WooCommerce → Settings → Payments → LedgerDirect → Manage:

| Setting | |
|---|---|
| XRPL network | `testnet` or `mainnet`. |
| Merchant account, mainnet and testnet | The shop's receiving address on each network; the one for the selected network is used. |
| Enable RLUSD payments, Enable USDC payments | Stablecoins are off by default — they need a trustline first. LedgerDirect is one payment method at checkout; the customer picks the asset inside it. |
| Payment page title | The page title shown in the browser tab. |
| XRP quote expiry | How long a quoted amount stays fixed, in minutes. Default 15. |
| Payment page: logo | What the payment page shows in its header: the site logo, a picture from the media library (its attachment ID or URL in the field below), or the first letter of the site name. |
| Payment page: accent colour | The one colour of the payment page — buttons, countdown bar, destination tag — with white text on it, so it must be dark enough (contrast 4.5:1). Default `#1f5eff`; a lighter colour is refused on save. |
| Payment page: Xaman API key, WalletConnect project id | Public identifiers, optional. With one of them, customers on a phone get an "Open in wallet app" button. Restrict them to your shop domain in the respective dashboard. |

Issuer addresses are not configurable. They are fixed in the core: a wrong issuer would send
customer funds to a dead trustline.

The settlement job runs every five minutes on the Action Scheduler, which WooCommerce drives from
WP-Cron. On a site where WP-Cron is disabled in favour of a system cron, make sure that cron calls
`wp-cron.php` every few minutes. Without the job, a customer who closes the payment page before
their transaction confirms is only settled the next time someone visits their payment page.

## Test payments

Set the network to `testnet` and enter a testnet account as the testnet merchant account. Test
accounts come from the [XRP Testnet faucet](https://xrpl.org/xrp-testnet-faucet.html) for XRP, the
[RLUSD faucet](https://tryrlusd.com/) for RLUSD and the [Circle faucet](https://faucet.circle.com/)
for USDC; the receiving account needs the trustlines, the paying wallet needs the tokens.

## The payment page

After checkout the customer lands on `/ledger-direct-payment/<order key>/`, the same key WooCommerce
uses for its own "Order received" page, so a guest can return to it. A wrong key gets a 404 without
a hint whether the order exists. The page is a document of its own: the theme's layout, header and
footer are not loaded, only WordPress's head and footer hooks run.

Behaviour and design come from [`@ledger-direct/payment-ui`](https://github.com/ledger-direct/ledger-direct-payment-ui),
the package every LedgerDirect plugin shares: the five payment states, the countdown, polling,
copy buttons, the QR code with address, destination tag and amount, and browser wallets over
XRPL Connect (Crossmark, GemWallet, MetaMask Snap, Ledger, Otsu, Xyra; Xaman and WalletConnect
with the identifiers above). The plugin has no build step for it: it ships the package's built
files under `public/`.

| File | From the package | Loaded |
|---|---|---|
| `public/css/payment-page.css` | `dist/payment-page.css` | with the page |
| `public/js/ledger-direct-payment-ui/payment-page.js` | `dist/payment-page.js` | with the page, enqueued only there |
| `public/js/ledger-direct-payment-ui/wallets.js` | `dist/wallets.js` | only when the customer opens the wallet list, by a native `import()` — never enqueued |

`public/js/ledger-direct-payment-ui/VERSION` names the package tag the files were copied from. To
update: copy the three files from the package's `dist/` at the new tag and write the tag into
`VERSION`. The view renders the package's markup contract (`src/README.md` there) from an array of
scalars built by the presenter in `src/Presentation/` — the view must not name a core class, because
the release build prefixes the core's namespace. Every sentence a customer reads is in
`includes/views/ledger-direct_html.php` and the translation catalogue; nothing is rounded or
reformatted in the browser.

The page polls `/wp-json/ledger-direct/v1/payment-status/<order key>`, which returns the same payload
as every other LedgerDirect plugin (`INVARIANTS.md` in the core, "Payment status"), plus a
`redirect` URL once the order no longer waits for payment; a wrong key is refused with 403.

Without JavaScript the page still works: the amount, address and tag are server-rendered, the QR
code is drawn on the server from the same payment request, and the "Check payment now" button is a
plain form that syncs and settles on reload.

## External services

Exchange rates come from the public APIs of Coingecko, Binance and Kraken, through the core. No
personal or payment data is sent to them; only the current rate is requested when a payment is
quoted or displayed, and rates are cached briefly so a short outage of one source does not
interrupt checkout.

- Coingecko API: [Terms of Service](https://www.coingecko.com/en/terms), [Privacy Policy](https://www.coingecko.com/en/privacy)
- Binance API: [Terms of Use](https://www.binance.com/en/terms), [Privacy Policy](https://www.binance.com/en/privacy)
- Kraken API: [Terms of Service](https://www.kraken.com/legal), [Privacy Policy](https://www.kraken.com/privacy)

## Development

How the whole of LedgerDirect is tested across the core, the shared page package and the four
plugins — the layers, what each catches, the nightly end-to-end runs and the manual cases — is in
[`docs/testing.md` of the core](https://github.com/ledger-direct/ledger-direct-core-php/blob/master/docs/testing.md).
The manual cases for this plugin, by the core's case IDs, are in `docs/manual-tests/payment-status.md`.

```
composer install                # runtime and test dependencies
composer phpcs                  # WordPress coding standards
composer phpstan                # static analysis
```

The test suite runs inside the WordPress test suite with WooCommerce present. `bin/install-wp-tests.sh`
fetches both into `/tmp` (or `bin/install-wp-tests-local.sh` against a database you already have):

```
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test
```

The suite is offline by design — it builds payment records directly rather than calling live price
oracles or the ledger. To work against a local core checkout instead of the released version, add a
path repository to the plugin's `composer.json` and require the branch; keep the committed constraint
on the released version.

Translations: `npm run i18n` regenerates `languages/ledger-direct.pot` and the JSON catalogues the
block checkout needs; the `.po`/`.mo` files are maintained from the `.pot`. German is included.

### Continuous integration and release

Every push and pull request runs `.github/workflows/ci.yml`: PHP syntax, WordPress Coding Standards
(phpcs), PHPStan, the PHPUnit suite on PHP 8.2–8.4 against the current WordPress and WooCommerce,
and the [WordPress Plugin Check](https://github.com/WordPress/plugin-check-action) on a copy of the
plugin built the way the release is.

The release goes to the WordPress.org plugin directory over SVN: `make release-dry-run` builds
everything the release would ship without touching SVN, `make release` publishes it. The build
installs the Composer dependencies without dev packages, prefixes them with
[PHP-Scoper](https://github.com/humbug/php-scoper) (`scoper.inc.php`) so that another plugin's copy
of the same package cannot clash with ours, and leaves out everything in `.distignore`. The
`readme.txt` next to this file is the plugin directory's page; this README is not shipped.

## License

MIT — see [LICENSE.md](LICENSE.md).
