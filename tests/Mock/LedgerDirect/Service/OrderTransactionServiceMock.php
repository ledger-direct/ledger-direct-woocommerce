<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;

use Hardcastle\LedgerDirect\Service\XrplTxService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Mockery;

class OrderTransactionServiceMock
{
    public static function createInstance(): OrderTransactionService
    {
        $configurationService = ConfigurationServiceMock::createInstance(); // ConfigurationService
        $xrplTxService = Mockery::mock(XrplTxService::class); // XrplTxService,
        $priceProvider = Mockery::mock(CryptoPriceProviderInterface::class); // CryptoPriceProviderInterface
        $priceProvider->shouldReceive('getCurrentExchangeRate')
            ->with('EUR')
            ->andReturn(1.0);

        return new OrderTransactionService(
            $configurationService,
            $xrplTxService,
            $priceProvider
        );
    }

    public static function createMock(): OrderTransactionService
    {
        return Mockery::mock(OrderTransactionService::class);
    }
}

