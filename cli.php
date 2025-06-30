<?php declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Load WordPress environment
require_once __DIR__ . '/../../../wp-load.php'; // Adjust the path as needed

require_once './ledger-direct.php';

use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\RlusdPriceProvider;

$container = ld_get_dependency_injection_container();
$cryptoPriceProvider = $container->get(RlusdPriceProvider::class);
$currentXrpPrice = $cryptoPriceProvider->getCurrentExchangeRate('USD');

echo "Current XRP Price: " . ($currentXrpPrice !== false ? $currentXrpPrice : 'Unable to fetch price') . PHP_EOL;