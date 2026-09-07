<?php declare(strict_types=1);

/**
 * PHP-Scoper configuration for the WordPress.org release build.
 *
 * WordPress loads every plugin into one PHP process. Another plugin that
 * bundles psr/log 1.x, psr/simple-cache 1.x, an older brick/math or its
 * own nyholm/psr7 would fight ours for the same class names, and whoever
 * registers their autoloader first wins - a fatal error for the loser.
 * The release build therefore moves everything under vendor/ (and every
 * reference to it in the plugin's own code) into the LedgerDirect\Vendor
 * namespace, so no other plugin can see or collide with it.
 *
 * Applied by scripts/deploy-wp-org.sh on the dist copy, never on the
 * repository itself. Development, tests and CI run against the plain
 * vendor/ as installed by Composer.
 *
 * @see https://github.com/humbug/php-scoper
 */

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'LedgerDirect\\Vendor',

    // Everything in the dist copy: the plugin's own code (so its references
    // to the vendored packages are rewritten) plus vendor/.
    'finders' => [
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->ignoreDotFiles(false)
            ->in('.')
            ->exclude(['.build', 'node_modules', 'tests']),
    ],

    // The plugin's own namespaces keep their names: WooCommerce registers
    // the gateway by class name, orders reference the gateway id, and the
    // update path must find the same classes it found before.
    // WooCommerce's and WordPress' own namespaced classes are not shipped
    // by us and must be referenced as they are.
    'exclude-namespaces' => [
        '/^Hardcastle\\\\LedgerDirect(?!\\\\Core)(\\\\|$)/',
        '/^Automattic\\\\WooCommerce/',
        '/^Automattic\\\\Jetpack/',
        '/^WP_/',
    ],

    // The global namespace belongs to WordPress and WooCommerce (WC_Order,
    // wc_get_logger(), as_schedule_recurring_action(), ABSPATH, ...) and to
    // this plugin's own global classes and functions. None of the bundled
    // packages declares anything global, so every single-segment symbol is
    // left exactly as it is. The release script verifies that no
    // `LedgerDirect\Vendor\<Global>` survives.
    // The plugin's WordPress-facing files live in the global namespace
    // (the bootstrap, the LedgerDirect* classes, the views). They reference
    // no vendored symbol directly - everything goes through the plugin's own
    // namespaced services - so they are shipped byte-identical, not scoped.
    // (Resolved against the working directory, i.e. the dist copy.)
    'exclude-files' => array_merge(
        [getcwd() . '/ledger-direct.php'],
        array_map(
            static fn (SplFileInfo $file): string => $file->getRealPath(),
            iterator_to_array(Finder::create()->files()->name('*.php')->in(getcwd() . '/includes'), false)
        )
    ),

    'exclude-classes' => ['/^[A-Za-z_][A-Za-z0-9_]*$/'],
    'exclude-functions' => ['/^[A-Za-z_][A-Za-z0-9_]*$/'],
    'exclude-constants' => ['/^[A-Za-z_][A-Za-z0-9_]*$/'],

    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
];
