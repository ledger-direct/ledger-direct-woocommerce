<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Mockery;

class OrderTransactionServiceMock
{
    /**
     * @param float $exchangeRate Exchange rate returned by the mocked price provider for any currency.
     */
    public static function createInstance(float $exchangeRate = 1.0): OrderTransactionService
    {
        $xrplTxService = Mockery::mock(XrplTxService::class);
        $priceProvider = Mockery::mock(CryptoPriceProviderInterface::class);
        $priceProvider->shouldReceive('getCurrentExchangeRate')
            ->andReturn($exchangeRate);

        return new OrderTransactionService(
            $xrplTxService,
            $priceProvider
        );
    }

    public static function createMock(): OrderTransactionService
    {
        return Mockery::mock(OrderTransactionService::class);
    }
}

