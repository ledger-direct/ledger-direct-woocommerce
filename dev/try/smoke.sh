#!/usr/bin/env bash
#
# Smoke test of a running try stack (dev/try/compose.yaml), the way a visitor would use it: the
# shop answers, the demo article and the miniatures are in their categories, a guest order with XRP
# through WooCommerce's checkout (the Store API the block checkout uses) gets a payment page that
# renders the markup contract of @ledger-direct/payment-ui, and the status endpoint answers the
# payment-status contract with `waiting` (case PS-01 of the core's catalogue).
#
#   dev/try/smoke.sh [base url]          default http://localhost:8092
#
# Needs curl and jq. Exits non-zero on the first failure.
set -euo pipefail
BASE=${1:-http://localhost:${LD_TRY_PORT:-8092}}
BASE=${BASE%/}
SKU=ledger-direct-demo

pass() { printf 'ok    %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*" >&2; exit 1; }

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")
[ "$code" = 200 ] || fail "storefront answered $code"
pass "storefront answers"

# Read the page first: `curl | grep -q` under pipefail fails whenever grep exits before curl has
# written everything (SIGPIPE), even though the text is there.
category=$(curl -fsS "$BASE/product-category/ledger-direct-demo/")
grep -q 'LedgerDirect demo article' <<<"$category" || fail "the demo category does not list the demo article"
pass "demo category lists the demo article"

# The miniatures from dev/try/catalog/catalog.json, in their category and on the home page.
catalog="$(dirname "$0")/catalog/catalog.json"
miniatures=$(curl -fsS "$BASE/product-category/miniatures/")
home=$(curl -fsS "$BASE/")
while IFS= read -r name; do
  html_name=$(sed 's/&/\&amp;/g' <<<"$name")
  grep -qF "$html_name" <<<"$miniatures" || fail "the Miniatures category lacks \"$name\""
  grep -qF "$html_name" <<<"$home" || fail "the home page lacks \"$name\""
done < <(jq -r '.products[]."en-GB".name' "$catalog")
pass "home page and the Miniatures category show $(jq '.products | length' "$catalog") miniatures"

# A guest checkout through the Store API, as the block checkout does it: a cart session (cookie and
# nonce), the demo article, the address, LedgerDirect with XRP.
jar=$(mktemp)
headers=$(mktemp)
trap 'rm -f "$jar" "$headers"' EXIT
curl -fsS -c "$jar" -b "$jar" -D "$headers" -o /dev/null "$BASE/wp-json/wc/store/v1/cart"
nonce=$(tr -d '\r' <"$headers" | awk -F': ' 'tolower($1) == "nonce" {print $2}')
[ -n "$nonce" ] || fail "the Store API gave no nonce"
store() { curl -fsS -c "$jar" -b "$jar" -H 'Content-Type: application/json' -H 'Accept: application/json' -H "Nonce: $nonce" "$@"; }

product=$(store "$BASE/wp-json/wc/store/v1/products?sku=$SKU" | jq -r '.[0].id // empty')
[ -n "$product" ] || fail "no product with SKU $SKU"
store -X POST "$BASE/wp-json/wc/store/v1/cart/add-item" -d "{\"id\":$product,\"quantity\":1}" >/dev/null

address='{"first_name":"Try","last_name":"Visitor","address_1":"1 Testnet Way","city":"Berlin","postcode":"10115","country":"DE"}'
billing=$(jq -c '. + {"email":"visitor@example.invalid"}' <<<"$address")
checkout=$(store -X POST "$BASE/wp-json/wc/store/v1/checkout" -d "{\"billing_address\":$billing,\"shipping_address\":$address,\"payment_method\":\"ledger-direct\",\"payment_data\":[{\"key\":\"ledger_direct_payment_type\",\"value\":\"xrp\"}]}") \
  || fail "the checkout failed"
order=$(jq -r '.order_id' <<<"$checkout")
key=$(jq -r '.order_key' <<<"$checkout")
redirect=$(jq -r '.payment_result.redirect_url' <<<"$checkout")
[[ "$order" =~ ^[0-9]+$ ]] || fail "placing the order failed: $checkout"
[[ "$redirect" == */ledger-direct-payment/"$key"/ ]] || fail "the checkout leads to $redirect, not the payment page"
pass "guest order $order placed with XRP, on to the payment page"

page=$(mktemp)
code=$(curl -s -o "$page" -w '%{http_code}' "$redirect")
[ "$code" = 200 ] || fail "payment page answered $code"
for attribute in 'data-ld-page' 'data-ld-state="waiting"' 'data-ld-amount-requested="' 'data-ld-asset="XRP"' 'data-ld-poll-url="' 'data-ld-account' 'data-ld-tag'; do
  grep -q -- "$attribute" "$page" || fail "payment page lacks $attribute"
done
amount=$(grep -o 'data-ld-amount-requested="[^"]*"' "$page" | head -1 | cut -d'"' -f2)
rm -f "$page"
pass "payment page renders the markup contract (amount $amount XRP)"

status=$(curl -fsS -H 'Accept: application/json' "$BASE/wp-json/ledger-direct/v1/payment-status/$key")
[ "$(jq -r .state <<<"$status")" = waiting ] || fail "status endpoint: $status"
[ "$(jq -r '.seconds_left > 0' <<<"$status")" = true ] || fail "status endpoint without seconds_left: $status"
pass "status endpoint: waiting, $(jq -r .seconds_left <<<"$status") s left (PS-01)"

# A key of the right shape that is not the order's: the route accepts it, the plugin refuses it.
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/wp-json/ledger-direct/v1/payment-status/wc_order_NotTheKey000")
[ "$code" = 403 ] || fail "a wrong key got $code instead of 403"
pass "a wrong key is refused with 403 (PS-07)"

printf '\npayment page: %s\n' "$redirect"
