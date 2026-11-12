<?php
/**
 * The try-it shop's LedgerDirect setup. Both parts are safe to run again.
 *
 *   install.php config    every start: the receiving account and what goes with it
 *     - LD_TESTNET_ACCOUNT if set (yours: it needs the RLUSD and USDC trust lines itself)
 *     - otherwise the shop's own testnet wallet: created from the faucet on the first start, with
 *       trust lines to the RLUSD and USDC issuers of the core's StablecoinRegistry, and created anew
 *       when the testnet was reset (see wallet.php)
 *     - XRP always; RLUSD and USDC when the account has the trust lines
 *     - XAH on the Xahau testnet, to the shop's own Xahau account (one account per chain), made on the
 *       first start and funded from the Xahau faucet; when the Xahau testnet does not answer, XAH stays
 *       as it was and the next start tries again
 *     - free shipping, so an order costs exactly the article; the other payment methods off; the
 *       store in EUR, open to guests, without WooCommerce's "coming soon" page and onboarding
 *
 *   install.php catalog   when the image brings a new catalogue: the category "LedgerDirect demo" with
 *     the demo article for 1 EUR (the smoke test orders it), the category "Miniatures" with the
 *     articles of catalog/catalog.json, and the home page that explains the demo and shows the
 *     miniatures. Articles are found again by SKU: an existing one gets its texts and price updated,
 *     never a second copy of its pictures.
 */
declare(strict_types=1);

use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry;

const STATE = '/var/lib/ledger-direct-try';
const ACCOUNT_FILE = STATE . '/account';
const WALLET_FILE = STATE . '/wallet.json';
const XAHAU_WALLET_FILE = STATE . '/wallet-xahau.json';
const WALLET = '/usr/local/share/ledger-direct/wallet.php';
const ADDRESS = '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/';
const SKU = 'ledger-direct-demo';
const CATALOG = '/usr/local/share/ledger-direct/catalog';
const SETTINGS = 'woocommerce_ledger-direct_settings';
const HOME_PAGE = 'ledger_direct_try_home_page';
const MENU = 'ledger_direct_try_menu';

$_SERVER['HTTP_HOST'] = (string) parse_url((string) getenv('LD_BASE_URL') ?: 'http://localhost', PHP_URL_HOST);
define('WP_USE_THEMES', false);
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

function out(string $message): void
{
    fwrite(STDOUT, '[ledger-direct-try] ' . $message . "\n");
}

/** Runs the wallet tool; returns its exit code, passes its own log lines through. */
function wallet(string ...$args): int
{
    $command = 'php ' . escapeshellarg(WALLET) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    passthru($command, $code);

    return $code;
}

/**
 * The shop's own testnet account with its trust lines; [address, whether RLUSD and USDC can be paid].
 * The trust-line limits come from the core's StablecoinRegistry, the one source of issuer addresses.
 */
function ownWallet(): array
{
    if (is_file(WALLET_FILE) && wallet('exists', WALLET_FILE) === 3) {
        out('the testnet was reset since the last start; creating a new receiving account');
        unlink(WALLET_FILE);
    }
    if (!is_file(WALLET_FILE)) {
        ob_start();
        $code = wallet('create', WALLET_FILE);
        ob_end_clean();
        if ($code !== 0) {
            throw new RuntimeException('could not create a testnet account; set LD_TESTNET_ACCOUNT to an address of your own');
        }
    }
    $address = json_decode((string) file_get_contents(WALLET_FILE), true)['address'];

    $registry = new StablecoinRegistry();
    $lines = [$registry->getRLUSDAmount('testnet', '1000000'), $registry->getUSDCAmount('testnet', '1000000')];
    $trusted = wallet('trust', WALLET_FILE, json_encode($lines)) === 0;
    if (!$trusted) {
        out('the trust lines could not be set; RLUSD and USDC stay off for now, the next start tries again');
    }

    return [$address, $trusted];
}

/**
 * The shop's own Xahau testnet account, separate from the XRP Ledger one: the same r-address is a
 * different account on each chain (one account per chain, the core's INVARIANTS.md "Xahau"). null while
 * it is not on the Xahau testnet; the next start tries again.
 */
function xahauAccount(): ?string
{
    if (wallet('xahau', XAHAU_WALLET_FILE) !== 0) {
        return null;
    }

    return json_decode((string) file_get_contents(XAHAU_WALLET_FILE), true)['address'];
}

function configure(): void
{
    $own = trim((string) getenv('LD_TESTNET_ACCOUNT'));
    if ($own !== '' && !preg_match(ADDRESS, $own)) {
        throw new RuntimeException("LD_TESTNET_ACCOUNT is not an XRPL address: {$own}");
    }
    if ($own !== '') {
        [$account, $stablecoins] = [$own, getenv('LD_STABLECOINS') !== '0'];
    } else {
        [$account, $stablecoins] = ownWallet();
    }
    file_put_contents(ACCOUNT_FILE, $account);

    $settings = get_option(SETTINGS, []);
    $settings = is_array($settings) ? $settings : [];

    // XAH out of the box, so a visitor pays with their wallet without configuring anything.
    $xahau = xahauAccount();
    if ($xahau === null) {
        out('the Xahau testnet account is not ready; XAH stays as it was, the next start tries again');
    }

    update_option(SETTINGS, array_merge($settings, [
        'enabled' => 'yes',
        'xrpl_network' => 'testnet',
        'xrpl_testnet_destination_account' => $account,
        'xrpl_is_rlusd_enabled' => $stablecoins ? 'yes' : 'no',
        'xrpl_is_usdc_enabled' => $stablecoins ? 'yes' : 'no',
        'xahau_network' => 'testnet',
    ], $xahau === null ? [] : [
        'xahau_enabled' => 'yes',
        'xahau_testnet_destination_account' => $xahau,
    ], [
        'xrpl_quote_expiry' => 5,
    ]));

    // Only LedgerDirect at the checkout.
    foreach (['bacs', 'cheque', 'cod'] as $gateway) {
        $other = get_option("woocommerce_{$gateway}_settings", []);
        update_option("woocommerce_{$gateway}_settings", array_merge(is_array($other) ? $other : [], ['enabled' => 'no']));
    }

    foreach ([
        'blogname' => 'LedgerDirect demo shop',
        'blogdescription' => 'LedgerDirect demo shop on the XRP Ledger testnet',
        'timezone_string' => 'Europe/Berlin',
        'permalink_structure' => '/%postname%/',
        'woocommerce_currency' => 'EUR',
        'woocommerce_default_country' => 'DE',
        'woocommerce_store_address' => '1 Testnet Way',
        'woocommerce_store_city' => 'Berlin',
        'woocommerce_store_postcode' => '10115',
        'woocommerce_allowed_countries' => 'all',
        'woocommerce_ship_to_countries' => '',
        'woocommerce_default_customer_address' => 'base',
        'woocommerce_calc_taxes' => 'no',
        'woocommerce_enable_guest_checkout' => 'yes',
        'woocommerce_enable_checkout_login_reminder' => 'no',
        // A new store starts as "coming soon" for everyone but its admin; a try shop is open.
        'woocommerce_coming_soon' => 'no',
        // WooCommerce's own demo notice stays off: the payment page leaves the theme out, and the home
        // page and the payment page say "testnet" themselves.
        'woocommerce_demo_store' => 'no',
        'woocommerce_onboarding_profile' => ['skipped' => true],
        'woocommerce_task_list_hidden' => 'yes',
        'woocommerce_show_marketplace_suggestions' => 'no',
        'woocommerce_allow_tracking' => 'no',
    ] as $option => $value) {
        update_option($option, $value);
    }
    flush_rewrite_rules(false);

    freeShipping();

    out("receiving account {$account} (" . ($own !== '' ? 'yours' : "the shop's own testnet wallet") . '); XRP'
        . ($stablecoins ? ', RLUSD and USDC' : ' only')
        . ($xahau !== null ? "; XAH to the shop's Xahau testnet account {$xahau}" : ''));
}

/** Free shipping everywhere: the zone "everywhere else" offers it, and nothing else. */
function freeShipping(): void
{
    $zone = new WC_Shipping_Zone(0);
    $free = false;
    foreach ($zone->get_shipping_methods() as $instance => $method) {
        if ($method->id === 'free_shipping') {
            $free = true;
            continue;
        }
        $zone->delete_shipping_method($instance);
    }
    if (!$free) {
        $zone->add_shipping_method('free_shipping');
    }
}

function catalog(): void
{
    $demo = categoryBySlug('ledger-direct-demo', 'LedgerDirect demo');
    $id = wc_get_product_id_by_sku(SKU);
    $product = $id ? wc_get_product($id) : new WC_Product_Simple();
    if (!$id) {
        $product->set_sku(SKU);
        $product->set_name('LedgerDirect demo article');
        $product->set_slug('ledger-direct-demo-article');
        $product->set_regular_price('1');
        $product->set_status('publish');
        $product->set_description('A demo article for 1 EUR. Pay it with XRP on the XRP Ledger testnet.');
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->set_category_ids([$demo]);
        $product->save();
        $product->set_image_id(attach(articlePicture(), $product->get_id(), 'LedgerDirect demo article'));
    }
    $product->set_category_ids(array_unique([...$product->get_category_ids(), $demo]));
    $product->save();

    $miniatures = miniatures();
    homePage($miniatures);
    menu($demo, $miniatures);
    out('home page, category "LedgerDirect demo" with the demo article (SKU ' . SKU . ', 1 EUR)');
}

/** A product category found by its slug, or created. */
function categoryBySlug(string $slug, string $name): int
{
    $term = get_term_by('slug', $slug, 'product_cat');
    if ($term instanceof WP_Term) {
        return $term->term_id;
    }
    $created = wp_insert_term($name, 'product_cat', ['slug' => $slug]);
    if (is_wp_error($created)) {
        throw new RuntimeException("category {$slug}: " . $created->get_error_message());
    }

    return (int) $created['term_id'];
}

/**
 * The miniatures from catalog/catalog.json, in the category "Miniatures". Found again by SKU; an
 * existing article gets its name, texts and price from the catalogue, its pictures stay as they are.
 *
 * @return string the category's slug
 */
function miniatures(): string
{
    $catalog = json_decode((string) file_get_contents(CATALOG . '/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    $category = categoryBySlug($catalog['category']['url_key'], $catalog['category']['en-GB']['name']);
    $closing = $catalog['closing']['en-GB'];

    foreach ($catalog['products'] as $position => $item) {
        $text = $item['en-GB'];
        $paragraphs = array_map(
            static fn (string $line): string => '<p>' . esc_html($line) . '</p>',
            array_values(array_filter(array_map('trim', explode("\n", $text['description']))))
        );
        $facts = array_map(
            static fn (string $key, string $value): string => '<li>' . ucfirst($key) . ': ' . esc_html($value) . '</li>',
            array_keys($item['facts']),
            array_values($item['facts'])
        );
        $description = implode("\n", $paragraphs)
            . ($facts ? "\n<ul>" . implode('', $facts) . '</ul>' : '')
            . "\n<p><em>" . esc_html($closing) . '</em></p>';

        $id = wc_get_product_id_by_sku($item['number']);
        $product = $id ? wc_get_product($id) : new WC_Product_Simple();
        if (!$id) {
            $product->set_sku($item['number']);
            $product->set_slug($item['slug']);
            $product->set_status('publish');
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        }
        $product->set_name($text['name']);
        $product->set_regular_price(number_format((float) $item['price'], 2, '.', ''));
        $product->set_short_description('<p>' . esc_html($text['tagline']) . '</p>');
        $product->set_description($description);
        $product->set_menu_order($position);
        $product->set_category_ids(array_unique([...$product->get_category_ids(), $category]));
        $product->save();

        if (!$id) {
            $pictures = [];
            foreach ($item['images'] as $image) {
                $pictures[] = attach(CATALOG . '/images/' . $image['file'], $product->get_id(), $image['alt'] ?? $text['name']);
            }
            $product->set_image_id(array_shift($pictures));
            $product->set_gallery_image_ids($pictures);
            $product->save();
        }
        out(sprintf('%s "%s", %.2f EUR%s', $item['number'], $text['name'], $item['price'], $id ? ' (updated)' : ''));
    }

    return $catalog['category']['url_key'];
}

/** A picture into the media library, attached to the article; the source file stays where it is. */
function attach(string $source, int $productId, string $alt): int
{
    $copy = wp_tempnam(basename($source));
    copy($source, $copy);
    $attachment = media_handle_sideload(['name' => basename($source), 'tmp_name' => $copy], $productId, $alt);
    if (is_wp_error($attachment)) {
        @unlink($copy);
        throw new RuntimeException(basename($source) . ': ' . $attachment->get_error_message());
    }
    update_post_meta($attachment, '_wp_attachment_image_alt', $alt);

    return $attachment;
}

/** A picture for the demo article, drawn here: no image file with a licence of its own in the image. */
function articlePicture(): string
{
    $size = 800;
    $image = imagecreatetruecolor($size, $size);
    $accent = imagecolorallocate($image, 0x1f, 0x5e, 0xff);
    $white = imagecolorallocate($image, 0xff, 0xff, 0xff);
    imagefilledrectangle($image, 0, 0, $size, $size, $accent);
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $lines = [['LedgerDirect', 64, 330], ['demo article', 44, 420], ['1 EUR · XRP Ledger testnet', 26, 500]];
    foreach ($lines as [$text, $points, $y]) {
        if (is_file($font) && function_exists('imagettftext')) {
            $box = imagettfbbox($points, 0, $font, $text);
            imagettftext($image, $points, 0, (int) (($size - ($box[2] - $box[0])) / 2), $y, $white, $font, $text);
        } else {
            imagestring($image, 5, (int) (($size - 9 * strlen($text)) / 2), $y - 15, $text, $white);
        }
    }
    $path = sys_get_temp_dir() . '/ledger-direct-demo-article.png';
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

/** The home page explains the demo and shows the miniatures with their cart buttons. */
function homePage(string $miniatures): void
{
    $repository = 'https://github.com/ledger-direct/ledger-direct-woocommerce';
    $content = <<<HTML
<!-- wp:group {"className":"ledger-direct-demo","layout":{"type":"constrained"}} -->
<div class="wp-block-group ledger-direct-demo">
<!-- wp:paragraph -->
<p><strong>A demo shop on the XRP Ledger testnet.</strong> It runs <a href="{$repository}">LedgerDirect for WooCommerce</a>:
customers pay with XRP, RLUSD or USDC straight into the shop's own wallet — no payment provider in between.
Nothing here costs real money, and no order is shipped.</p>
<!-- /wp:paragraph -->
<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list">
<!-- wp:list-item --><li>Put a miniature in the cart and check out as a guest — any name and address will do.</li><!-- /wp:list-item -->
<!-- wp:list-item --><li>Choose <em>LedgerDirect</em>, then XRP, RLUSD or USDC, and place the order.</li><!-- /wp:list-item -->
<!-- wp:list-item --><li>The payment page shows the amount, the shop's account and a destination tag. Pay it from a testnet wallet,
with the destination tag, or connect the wallet with the buttons on the page. The page notices the payment within seconds.</li><!-- /wp:list-item -->
</ol>
<!-- /wp:list -->
<!-- wp:paragraph -->
<p>A testnet wallet: <a href="https://xaman.app/">Xaman</a> in testnet mode or <a href="https://crossmark.io/">Crossmark</a>,
with XRP from the <a href="https://xrpl.org/resources/dev-tools/xrp-faucets">XRP testnet faucet</a>. RLUSD and USDC come from the
<a href="https://tryrlusd.com/">RLUSD faucet</a> and the <a href="https://faucet.circle.com/">Circle faucet</a>.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<!-- wp:shortcode -->
[products category="{$miniatures}" columns="3" limit="12" orderby="menu_order" order="ASC"]
<!-- /wp:shortcode -->
HTML;

    $id = (int) get_option(HOME_PAGE);
    $page = [
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Pay with LedgerDirect',
        'post_name' => 'ledger-direct-demo-shop',
        'post_content' => $content,
    ];
    if ($id && get_post($id)) {
        $page['ID'] = $id;
        wp_update_post(wp_slash($page));
    } else {
        $id = wp_insert_post(wp_slash($page), true);
        if (is_wp_error($id)) {
            throw new RuntimeException('home page: ' . $id->get_error_message());
        }
        update_option(HOME_PAGE, $id);
    }
    update_option('show_on_front', 'page');
    update_option('page_on_front', $id);
}

/**
 * The header menu: home, the two categories, the cart. Without one the theme lists every page,
 * WordPress's sample page among them, which goes too.
 */
function menu(int $demo, string $miniatures): void
{
    foreach ([['sample-page', 'page'], ['hello-world', 'post']] as [$slug, $type]) {
        $sample = get_page_by_path($slug, OBJECT, $type);
        if ($sample instanceof WP_Post) {
            wp_delete_post($sample->ID, true);
        }
    }
    $link = static fn (string $label, string $url): string => sprintf(
        '<!-- wp:navigation-link {"label":%s,"url":%s,"kind":"custom"} /-->',
        wp_json_encode($label),
        // Relative: the shop keeps working when LD_TRY_PORT changes.
        wp_json_encode(wp_make_link_relative($url))
    );
    $content = implode('', [
        $link('Home', home_url('/')),
        $link('Miniatures', (string) get_term_link(categoryBySlug($miniatures, 'Miniatures'), 'product_cat')),
        $link('LedgerDirect demo', (string) get_term_link($demo, 'product_cat')),
        $link('Cart', wc_get_cart_url()),
    ]);
    $id = (int) get_option(MENU);
    $post = ['post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Try shop', 'post_content' => $content];
    if ($id && get_post($id)) {
        $post['ID'] = $id;
        wp_update_post(wp_slash($post));
    } else {
        update_option(MENU, wp_insert_post(wp_slash($post)));
    }
}

match ($argv[1] ?? '') {
    'config' => configure(),
    'catalog' => catalog(),
    default => throw new InvalidArgumentException('usage: install.php config|catalog'),
};
