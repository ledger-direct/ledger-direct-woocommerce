<?php declare(strict_types=1);

/**
 * Constants normally defined by ledger-direct.php at runtime (after WordPress
 * has loaded), declared here so PHPStan can resolve them during static analysis.
 */

if (!defined('LEDGER_DIRECT_PLUGIN_FILE_PATH')) {
    define('LEDGER_DIRECT_PLUGIN_FILE_PATH', __DIR__ . '/../');
}
