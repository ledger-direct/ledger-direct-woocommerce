<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;
use Hardcastle\LedgerDirect\Provider\Oracle\BinanceOracle;
use Hardcastle\LedgerDirect\Provider\Oracle\CoingeckoOracle;
use Hardcastle\LedgerDirect\Provider\Oracle\KrakenOracle;

class XrpPriceProvider implements CryptoPriceProviderInterface
{
    public const CRYPTO_CODE = 'XRP';

    public const DEFAULT_ALLOWED_DIVERGENCE = 0.05;

    public const XRP_ROUND_PLACES = 5;

    /**
     * Gets the current XRP price by querying averaging multiple oracles
     *
     * @param string $code
     * @param bool|null $round
     * @return float|false
     */
    public function getCurrentExchangeRate(string $code, ?bool $round = false): float|false
    {
        $oracleResults = [];
        $filteredPrices = [];

        $oracles = [
            new BinanceOracle(),
            new CoingeckoOracle(),
            new KrakenOracle(),
        ];

        foreach ($oracles as $oracle) {
            try {
                $price = $oracle->getCurrentPriceForPair(self::CRYPTO_CODE, $code);
                if ($price > 0.0) {
                    $oracleResults[] = $price;
                }
            } catch (Exception $exception) {
                wc_get_logger()->warning('LedgerDirect: price oracle failed', [
                    'source'    => 'ledger-direct',
                    'oracle'    => $oracle::class,
                    'base'      => self::CRYPTO_CODE,
                    'quote'     => $code,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if(count($oracleResults) === 0) {
            return false;
        }

        $avg = array_sum($oracleResults) / count($oracleResults);
        foreach ($oracleResults as $price) {
            if (abs($avg-$price) < $avg * self::DEFAULT_ALLOWED_DIVERGENCE) {
                $filteredPrices[] = $price;
            }
        }

        if(count($filteredPrices) > 0) {
            $avg = array_sum($filteredPrices) / count($filteredPrices);
            return $round ? $this->roundPrice($avg) : $avg;
        }

        return false;
    }

    /**
     * Checks if the given price is plausible for XRP.
     *
     * @param float $price
     * @return bool
     */
    public function checkPricePlausibility(float $price): bool
    {
        if ($price > 0.0) {
            return true;
        }

        return false ;
    }

    /**
     * Rounds the price to the defined XRP round places.
     *
     * @param float $price
     * @return float
     */
    private function roundPrice(float $price): float
    {
        return round($price, self::XRP_ROUND_PLACES, PHP_ROUND_HALF_UP);
    }
}