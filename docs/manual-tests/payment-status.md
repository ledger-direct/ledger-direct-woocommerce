# Manual test cases — payment status, WooCommerce

The WooCommerce instantiation of the core's case catalogue, `docs/manual-tests/payment-status.md` in
`hardcastle/ledger-direct-core`. The catalogue says *what* must hold on every platform; this file says how to
make it happen in this shop: which setting, which command, which status name, where the evidence shows up.
Same IDs.

**How this is used.** A pull request that touches the payment page, `Api/PaymentStatusEndpoint`,
`OrderTransactionService` or `Cron/SettlePendingOrders` lists the applicable IDs under "Manual end-to-end
tests" as checkboxes and ticks what was actually run, with the order number or hash next to it. Results are
not collected in this file; the PR is the record. Should a case get automated or become a merge gate one day,
it keeps its ID.

## Environment

- The docker stack in `wordpress-docker-compose-master/` (`docker compose up -d`; the `wp` container has
  WP-CLI: `docker compose exec wp wp <command> --allow-root`). The shop is `https://localhost`.
- **Own testnet wallet** for this shop (never the one another shop uses — shared tag space; never an issuer
  account): `tests/manual/xrpl-e2e/faucet.js` funds one, `trustset.js <seed> <currencyHex> <issuer>` adds the
  trust lines to the testnet issuers of RLUSD and USDC from the core's `StablecoinRegistry`. Seeds stay in the
  git-ignored `tests/manual/`, never in docs or commits. `pay.js <seed> <destination> <tag> <amount>` sends a
  signed XRP payment with a destination tag and waits for validation; `info.js <address>` shows balance and
  trust lines.
- Configuration: WooCommerce → Settings → Payments → LedgerDirect: network *Testnet*, the testnet account, RLUSD
  and USDC enabled, quote expiry 15 minutes unless a case says otherwise. Or by WP-CLI:

  ```
  wp option patch update woocommerce_ledger-direct_settings xrpl_testnet_destination_account <wallet> --allow-root
  wp option patch update woocommerce_ledger-direct_settings xrpl_quote_expiry 1 --allow-root
  ```

- The payment page: `/ledger-direct-payment/<order key>/` (with permalinks) or
  `/?ledger-direct-payment=<order key>`. The poll it makes: `/wp-json/ledger-direct/v1/payment-status/<order key>`.
  Watch it in the browser's network tab, or call it with `curl -sk -H 'Accept: application/json'`. The key of
  an order: `wp wc shop_order get <id> --fields=order_key --user=admin --allow-root`, or the *Order received*
  URL after checkout.
- Order status: WooCommerce → Orders, or `wp wc shop_order get <id> --fields=status,transaction_id --user=admin --allow-root`.
  An order waits for payment while it is *Pending payment* or *XRPL payment incomplete* (something arrived that
  does not pay it; status key `ld-incomplete`); *Processing* (or *Completed* for virtual goods)
  with a transaction ID means paid. The payment record: the order's `_ledger_direct` meta
  (`wp post meta get <id> _ledger_direct --allow-root` on non-HPOS stores, or the *Custom Fields* box).
- The background job: Action Scheduler, hook `ledger_direct_settle_pending_orders` every five minutes. Run it
  now with `wp action-scheduler run --hooks=ledger_direct_settle_pending_orders --allow-root`, or WooCommerce →
  Status → Scheduled Actions → *Run*. Its log lines are under WooCommerce → Status → Logs, source
  `ledger-direct`.

## Automated with ld-e2e

The `ledger-direct-e2e` harness runs PS-01 to PS-09 and PS-11 against this shop unattended (PS-10 waits
35 minutes and belongs to a nightly run). It pays real testnet transactions from its treasury to a receiving
account it creates for the run, and writes the checklist lines into the pull request:

```
ld-e2e run --target woocommerce --base-url http://localhost:8082 \
  --compose-dir /path/to/wordpress-docker-compose-master \
  --compose-files docker-compose.yml,docker-compose.ports-local.yml --cases automated
ld-e2e report pr --repo ledger-direct/ledger-direct-woocommerce --pr <n>
```

What it does, in this shop's terms — the same steps as above, without a browser:

- **Orders** are created through WP-CLI in the running `wp` container (a PHP script on stdin): a guest
  order with the 1.00 test article `LD-E2E-001` (created on first use), gateway `ledger-direct`, then
  `OrderTransactionService::prepareOrderForXrpl()` — what `process_payment()` does once WooCommerce has
  validated the checkout. Needs pretty permalinks (the page is `/ledger-direct-payment/<key>/`).
- **Configuration** is written to `woocommerce_ledger-direct_settings` (network, account, RLUSD/USDC,
  `xrpl_quote_expiry` in minutes — PS-02 sets 1).
- **The customer's side** is HTTP: the page (state, displayed amount, account, tag), the status endpoint,
  and the refresh as a POST with the `_wpnonce` the expired block renders.
- **PS-07:** the order key is the address here, so a wrong key on the page answers 404 (no data), not a
  redirect; the status endpoint answers 403 only for a key of the right shape — a malformed one is refused by
  the route pattern first. The harness probes with well-formed wrong keys.
- **PS-08** reads the core's throttle mark, the transient `ledger_direct_ledger-direct.sync.v1.testnet.<account>`
  (the time of the last sync): one status call inside the window must not change it.
- **PS-09** fires the Action Scheduler hook `ledger_direct_settle_pending_orders` directly, counting pending
  LedgerDirect orders before and after.
- **PS-11** cancels the order with `update_status('cancelled')`.

## PS-01 — Waiting

Place an order with *XRP*, send nothing, open the payment page.

Look for: `data-ld-state="waiting"` on the page wrapper; the countdown next to *This amount is guaranteed
for*; a request to `/wp-json/ledger-direct/v1/payment-status/…` every 8 s in the network tab, each answering
`"state":"waiting"` with a falling `seconds_left` and no `redirect`; the order *Pending payment*.

## PS-02 — Expired, then refreshed

Quote expiry 1 minute in the settings. Place an XRP order, send nothing, wait a minute with the page open.

Look for: the countdown block hidden, the expired notice with *Get an updated amount* visible; the poll answers
`"state":"expired"`, `"seconds_left":null`, still no `redirect`. Click the button (a POST to the payment page
with the nonce): the page reloads with a new amount and a fresh countdown, the destination tag unchanged
(`destination_tag` in the `_ledger_direct` meta). Reset the expiry afterwards.

Also: the button is refused (plain reload, no new quote) when something has arrived — try it on the PS-03
order.

## PS-03 — Partial, then topped up

Place a small XRP order. Send half the displayed amount to account and tag. Do **not** reload.

Look for, within 8 s: the `data-ld-partial` block visible with *X XRP received so far. Y XRP is still
outstanding*; the poll answers `"state":"partial"` with `amount_paid` and `shortfall`; the order moves to
*XRPL payment incomplete* with a note (*X XRP received of Z XRP requested, Y XRP still due*) and its
`_ledger_direct` meta carrying the hash and the amount; the LedgerDirect panel on the admin order page shows
*Partially paid*, the amounts and the transaction linked to the explorer. Then send `Y` to the same account
and tag: the poll answers `redirect`, the page leaves for *Order received*, the order is *Processing* with
`transaction_id` = the second transaction's hash and `amount_paid` in the meta = the sum.

## PS-04 — Wrong asset, then the right one

Place a *USDC* order. Pay the full amount in **RLUSD** to account and tag.

Look for: the `data-ld-wrong-asset` block visible naming the RLUSD amount and the full USDC request; the poll
answers `"state":"wrong_asset"` with `amount_paid.issuer` the RLUSD issuer and `shortfall` in USDC with the
full value; the order *XRPL payment incomplete* with a note that the payment was in another token and not
credited; the panel shows *Paid in the wrong token, not credited*. Then send the USDC: `redirect`, *Processing*,
and `transaction_id` is the USDC transaction.

Run it a second time **across the asset class**: an *XRP* order paid with RLUSD. Look for the same wrong-asset
block; the poll's `amount_paid` is the token object and `shortfall` the XRP number; the order *XRPL payment
incomplete* with the "another token, not credited" note; then the XRP in full settles with the XRP hash as
`transaction_id`. Core 0.8.1 — before, the payment was skipped and the page stayed on *waiting*. (Order 106 in
the dev shop is a standing example.)

## PS-05 — Settled

Place an XRP order of about 1.00 in shop currency. Send exactly the amount the page shows.

Look for: `redirect` in the next poll, the *Order received* page, the order *Processing*; exactly one
*Processing* order note from WooCommerce (`payment_complete()` ran once — not once from the poll and again
from the page or the job), one order confirmation mail.

## PS-06 — Guest, key knowledge instead of login

Place the order as a guest. After the checkout the address bar reads `/ledger-direct-payment/wc_order_…/`;
copy that URL. Open it in a private window.

Look for: the payment page renders without a login prompt; the poll URL opened in the same window answers
200 with the payload.

## PS-07 — Wrong key is refused without a hint

```
curl -sk -o /dev/null -w '%{http_code}\n' 'https://localhost/wp-json/ledger-direct/v1/payment-status/wc_order_wrong'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://localhost/wp-json/ledger-direct/v1/payment-status/<key of a non-LedgerDirect order>'
```

Look for: 403 both times, the same body `{"error":"forbidden"}`. The payment page with a wrong key answers
404. A key that is not of the form `wc_order_…` does not even match the route (404 from the REST API).

## PS-08 — Throttling

Two open orders on the testnet wallet, two payment pages open in two browsers (or one page plus `curl` on
the poll URL twice within 5 s).

Look for: both polls answer the full payload. The node request count: the core does not log each
`account_tx` call, so measure by timing — a poll that synced takes noticeably longer (about a second) than
one answered from the stored intent; only one of two calls within 5 s does. Or count the requests to
`s.altnet.rippletest.net` with `WP_DEBUG_LOG` and a `http_api_debug` filter.

## PS-09 — Safety net without a browser

Place an XRP order, close the page, send the full amount. Then run the Action Scheduler hook (see
Environment).

Look for: the order *Processing* without any page having been open. With several open orders on one account
the job makes one `account_tx` request (its warning lines, if any, are per order; the sync is per account).

## PS-10 — Late return after the checkout session is gone

WooCommerce has no payment token; what a customer loses is the checkout session and cart. Place an order,
open the key URL in a **new private window** (no session), pay the full amount there.

Look for: the poll answers `"state":"settled"` with a `redirect` to the *Order received* page for that order
key; following it shows the order, no cart redirect; the order *Processing*.

## PS-11 — Closed by the merchant

Place an XRP order, send nothing, keep the page open. In the admin, cancel the order.

Look for: the next poll carries a `redirect` while `state` is still `waiting`; the page leaves. Then send the
amount anyway and run the job: the order stays *Cancelled* — neither the job (which only looks at *Pending
payment* and *XRPL payment incomplete* orders) nor the page or the poll (which no longer sync an order that does
not need payment) touch it.

The payment reaches the transaction table, and with it the LedgerDirect panel of the cancelled order, only when
the receiving account is synced the next time — which the job does for the accounts of *open* orders, so in a
shop with no other LedgerDirect order waiting it is not visible until one is placed. The ledger has it either
way; the merchant deals with it by hand. (A sync of the configured account on every job run, open orders or
not, is noted as a core-wide follow-up.)

## PW-01 — Browser wallet

Open the payment page in a desktop browser with Crossmark or GemWallet installed, on the testnet, with a funded
account. Click "Pay with a browser wallet", pick the wallet, confirm the transaction there. Do it once for an XRP
order and once for a token order (RLUSD or USDC — the wallet's account needs a trust line to the testnet issuer).

Look for: the wallet shows the receiving address, the destination tag and the amount exactly as the page shows
them (the token order names currency and issuer); after signing, the page says "Sent – we are checking for it"
and the next poll settles the order, then the success view and the redirect to "Order received". The order
*Processing* with the transaction hash in its notes.

## PW-02 — Wallet on the wrong network

The same, but with the wallet set to the mainnet while the shop is on the testnet.

Look for: no transaction is signed; the page says the wallet is set to another network and names the one to
switch to. (Crossmark switches to the requested network at sign-in on its own — then the case passes as PW-01.)

## PW-03 — Phone

Open the page at 390 px width (device emulation is enough), once without a Xaman API key or WalletConnect
project id in the LedgerDirect settings, once with one of them.

Look for: one column, the QR code collapsed behind "Show QR code", the amount still the largest thing on the
page; without identifiers there is no wallet section at all, with one there is a single "Open in wallet app"
button and no browser-wallet list.

## PW-04 — Scanning the QR code

Scan the page's QR code with Xaman on the testnet, for an XRP order and for a token order.

Look for: Xaman takes over the receiving address, the destination tag and the amount as the page shows them —
the amount as the XRP decimal, not as drops — and, for a token, currency and issuer; nothing to type. Send, and
the page settles the order.
