<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Cache\TransientRateCache;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Http\WpHttpClient;
use Hardcastle\LedgerDirect\Log\WcLoggerAdapter;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Port\WpdbXrplTransactionRepository;
use Hardcastle\LedgerDirect\Storage\LegacyPaymentIntentMapper;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Wires the core's services to this plugin's port implementations.
 *
 * Hand-wired and memoised per request rather than a DI container: the
 * object graph is small, and one less bundled library in a WordPress
 * process is one less autoloader collision waiting to happen.
 */
final class ServiceFactory
{
    private static ?self $instance = null;

    private ?WpConfigProvider $configProvider = null;
    private ?WpdbXrplTransactionRepository $transactionRepository = null;
    private ?LoggerInterface $logger = null;
    private ?ClientInterface $httpClient = null;
    private ?Psr17Factory $httpFactory = null;
    private ?CacheInterface $rateCache = null;
    private ?PriceService $priceService = null;
    private ?PaymentIntentService $paymentIntentService = null;
    private ?SyncService $syncService = null;
    private ?SettlementPolicy $settlementPolicy = null;
    private ?OrderTransactionService $orderTransactionService = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Tests swap in a factory with mocked services, then reset.
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Builds a factory with pre-wired services (tests).
     */
    public static function withServices(
        ?PaymentIntentService $paymentIntentService = null,
        ?SyncService $syncService = null,
        ?SettlementPolicy $settlementPolicy = null,
        ?WpConfigProvider $configProvider = null
    ): self {
        $factory = new self();
        $factory->paymentIntentService = $paymentIntentService;
        $factory->syncService = $syncService;
        $factory->settlementPolicy = $settlementPolicy;
        $factory->configProvider = $configProvider;

        return $factory;
    }

    public function getConfigProvider(): WpConfigProvider
    {
        return $this->configProvider ??= new WpConfigProvider();
    }

    public function getTransactionRepository(): WpdbXrplTransactionRepository
    {
        return $this->transactionRepository ??= new WpdbXrplTransactionRepository();
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger ??= new WcLoggerAdapter();
    }

    public function getHttpClient(): ClientInterface
    {
        return $this->httpClient ??= new WpHttpClient($this->getHttpFactory());
    }

    /**
     * The rate cache lets the core skip the oracle set while a quote is
     * fresh - every checkout render otherwise pays for three oracle calls -
     * and fall back to a recent rate when no oracle answers, instead of the
     * payment method vanishing from a checkout the customer is standing in.
     */
    public function getRateCache(): CacheInterface
    {
        return $this->rateCache ??= new TransientRateCache();
    }

    public function getPriceService(): PriceService
    {
        return $this->priceService ??= new PriceService(
            $this->getHttpClient(),
            $this->getHttpFactory(),
            $this->getLogger(),
            $this->getRateCache()
        );
    }

    public function getPaymentIntentService(): PaymentIntentService
    {
        return $this->paymentIntentService ??= new PaymentIntentService(
            $this->getPriceService(),
            new DestinationTagService($this->getTransactionRepository()),
            $this->getConfigProvider()
        );
    }

    /**
     * The decision whether a delivered amount pays for a quote is the
     * core's, so every LedgerDirect plugin calls an order paid under the
     * same conditions (INVARIANTS.md, "Settlement").
     */
    public function getSettlementPolicy(): SettlementPolicy
    {
        return $this->settlementPolicy ??= new SettlementPolicy();
    }

    public function getSyncService(): SyncService
    {
        return $this->syncService ??= new SyncService(
            new XrplClient($this->getHttpClient(), $this->getHttpFactory(), $this->getHttpFactory()),
            $this->getTransactionRepository(),
            $this->getLogger()
        );
    }

    public function getOrderTransactionService(): OrderTransactionService
    {
        return $this->orderTransactionService ??= new OrderTransactionService(
            $this->getPaymentIntentService(),
            $this->getSyncService(),
            $this->getSettlementPolicy(),
            new LegacyPaymentIntentMapper(),
            $this->getTransactionRepository(),
            $this->getLogger()
        );
    }

    /** Nyholm's Psr17Factory is both the PSR-17 request and stream factory. */
    private function getHttpFactory(): Psr17Factory
    {
        return $this->httpFactory ??= new Psr17Factory();
    }
}
