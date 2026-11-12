// Walks the try shop in a real browser the way a prospect does: home page, add a miniature to the
// cart, WooCommerce's (block) checkout as a guest, LedgerDirect with one asset, place order, payment
// page in state "waiting". A screenshot of every step goes to the output folder; exits non-zero on the
// first failure.
//
//   npm install
//   CHROME=/path/to/chrome node visitor.mjs [base url] [screenshot dir] [xrp|rlusd|usdc|xah]
import puppeteer from 'puppeteer-core';
import { readFileSync } from 'node:fs';
// The article to buy: the first miniature of the catalogue, found on the home page by its slug —
// never "the first product", which changes with the catalogue.
const PRODUCT = JSON.parse(readFileSync(new URL('../catalog/catalog.json', import.meta.url))).products[0].slug;
const BASE = (process.argv[2] ?? 'http://localhost:8092').replace(/\/$/, '');
const OUT = process.argv[3] ?? '.';
const ASSET = process.argv[4] ?? 'xrp';
const browser = await puppeteer.launch({
  executablePath: process.env.CHROME ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'new', args: ['--no-sandbox', '--window-size=1280,1000'], defaultViewport: { width: 1280, height: 1000 },
});
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
const pause = (ms) => new Promise((r) => setTimeout(r, ms));
const step = async (name, fn) => {
  const t = Date.now();
  try { await fn(); } catch (e) {
    await page.screenshot({ path: `${OUT}/fail-${ASSET}-${name}.png`, fullPage: true });
    console.log(`FAIL  ${name}: ${e.message}`); console.log('console errors:', errors.slice(-5)); await browser.close(); process.exit(1);
  }
  await page.screenshot({ path: `${OUT}/${ASSET}-${name}.png`, fullPage: true });
  console.log(`ok    ${name} (${((Date.now() - t) / 1000).toFixed(1)} s)`);
};
// The block checkout recalculates the cart while an address is typed and while a payment method is
// chosen; until it is done, its loading masks are up and the place-order button is disabled. A click
// in that window is lost.
const settled = () => page.waitForFunction(() => {
  const busy = [...document.querySelectorAll('.wc-block-components-loading-mask, .wc-block-components-spinner, .is-loading')]
    .some((e) => e.offsetParent);
  const button = document.querySelector('.wc-block-components-checkout-place-order-button');
  return !busy && button && !button.disabled && !button.classList.contains('wc-block-components-button--loading');
}, { timeout: 30000 });

await step('1-home', async () => {
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle0', timeout: 60000 });
  await page.waitForSelector(`li.product a[href*="/product/${PRODUCT}/"]`, { timeout: 20000 });
});
await step('2-add-to-cart', async () => {
  const items = await page.$$('li.product');
  let target = null;
  for (const candidate of items) {
    if (await candidate.$(`a[href*="/product/${PRODUCT}/"]`)) { target = candidate; break; }
  }
  if (!target) throw new Error(`no product item for ${PRODUCT} on the home page`);
  await (await target.$('.add_to_cart_button')).click();
  // WooCommerce's AJAX add-to-cart puts a "View cart" link next to the button once the cart has it.
  await target.waitForSelector('a.added_to_cart', { timeout: 30000 });
});
await step('3-checkout-address', async () => {
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle0', timeout: 90000 });
  await page.waitForSelector('#email', { visible: true, timeout: 60000 });
  await page.type('#email', 'visitor@example.invalid');
  const country = await page.$('select#shipping-country');
  if (country) await page.select('select#shipping-country', 'DE');
  const fill = async (field, value) => page.type(`#shipping-${field}`, value);
  await fill('first_name', 'Try'); await fill('last_name', 'Visitor');
  await fill('address_1', '1 Testnet Way'); await fill('postcode', '10115'); await fill('city', 'Berlin');
  await page.keyboard.press('Tab');
  await settled();
});
await step('4-payment-method', async () => {
  // With only LedgerDirect enabled the method may show without a radio of its own.
  const method = await page.$('input[name="radio-control-wc-payment-method-options"][value="ledger-direct"]');
  if (method) await method.click();
  const assets = await page.$$eval('input[name="ledger_direct_payment_type"]', (els) => els.map((e) => e.value));
  console.log('      assets offered:', assets.join(' | '));
  const radio = `input[name="ledger_direct_payment_type"][value="${ASSET}"]`;
  await page.waitForSelector(radio, { visible: true, timeout: 30000 });
  await page.click(radio);
  await settled();
  await pause(1000);
});
await step('5-place-order', async () => {
  await settled();
  await Promise.all([
    page.waitForNavigation({ timeout: 60000 }),
    page.click('.wc-block-components-checkout-place-order-button'),
  ]);
  await page.waitForFunction(() => location.pathname.includes('/ledger-direct-payment/'), { timeout: 60000 });
  await page.waitForSelector('[data-ld-page][data-ld-state]', { timeout: 30000 });
});
const view = await page.evaluate(() => {
  const root = document.querySelector('[data-ld-page][data-ld-state]');
  return {
    url: location.href,
    state: root?.getAttribute('data-ld-state'),
    amount: root?.getAttribute('data-ld-amount-requested'),
    asset: root?.getAttribute('data-ld-asset'),
  };
});
console.log('      payment page:', JSON.stringify(view));
if (errors.length) console.log('      console errors:', errors.slice(0, 5));
await browser.close();
if (view.state !== 'waiting' || !view.amount || view.asset !== ASSET.toUpperCase() || !/\/ledger-direct-payment\/wc_order_/.test(view.url)) {
  console.log('FAIL  the payment page is not the expected one');
  process.exit(1);
}
