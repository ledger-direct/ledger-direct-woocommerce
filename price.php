<?php declare (strict_types=1);

require_once __DIR__ . '/vendor/autoload.php'; // Adjust the path as needed

use GuzzleHttp\Client;
use Hardcastle\LedgerDirect\Provider\Oracle\CoingeckoOracle;

$client = new Client();
$oracle = (new CoingeckoOracle())->prepare($client);

$code1 = 'RLUSD';
$code2 = 'USD';

$price = $oracle->getCurrentPriceForPair($code1, $code2);
echo "Current XRP to USD price: $price";