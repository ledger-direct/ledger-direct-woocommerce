<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Cache\TransientRateCache;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
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
    /** @var array<string, WpdbXrplTransactionRepository> by chain */
    private array $transactionRepositories = [];
    private ?LoggerInterface $logger = null;
    private ?ClientInterface $httpClient = null;
    private ?Psr17Factory $httpFactory = null;
    private ?CacheInterface $rateCache = null;
    private ?PriceService $priceService = null;
    /** @var array<string, PaymentIntentService> by chain */
    private array $paymentIntentServices = [];
    /** @var array<string, SyncService> by chain */
    private array $syncServices = [];
    /** @var array<string, SyncThrottle> by chain */
    private array $syncThrottles = [];
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
        if ($paymentIntentService !== null) {
            $factory->paymentIntentServices[ChainCatalog::XRPL] = $paymentIntentService;
        }
        if ($syncService !== null) {
            $factory->syncServices[ChainCatalog::XRPL] = $syncService;
        }
        $factory->settlementPolicy = $settlementPolicy;
        $factory->configProvider = $configProvider;

        return $factory;
    }

    public function getConfigProvider(): WpConfigProvider
    {
        return $this->configProvider ??= new WpConfigProvider();
    }

    /**
     * The core's services come per chain. Xahau speaks the XRP Ledger's protocol
     * on a network of its own, so the same classes serve it, built for the chain:
     * another node, another transaction table and tag counter, another throttle
     * mark. Without a chain the XRP Ledger's set is meant, as before.
     */
    public function getTransactionRepository(string $chain = ChainCatalog::XRPL): WpdbXrplTransactionRepository
    {
        return $this->transactionRepositories[$chain] ??= new WpdbXrplTransactionRepository($chain);
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

    public function getPaymentIntentService(string $chain = ChainCatalog::XRPL): PaymentIntentService
    {
        return $this->paymentIntentServices[$chain] ??= new PaymentIntentService(
            $this->getPriceService(),
            new DestinationTagService($this->getTransactionRepository($chain)),
            $this->getConfigProvider(),
            chain: $chain
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

    /**
     * The sync remembers a testnet reset in the rate cache, so after one only
     * the first sync per account sends the request the node rejects, not every
     * sync. The chain keeps the note apart where the same account exists on
     * the XRP Ledger and on Xahau.
     */
    public function getSyncService(string $chain = ChainCatalog::XRPL): SyncService
    {
        return $this->syncServices[$chain] ??= new SyncService(
            $this->getXrplClient($chain),
            $this->getTransactionRepository($chain),
            $this->getLogger(),
            $this->getRateCache(),
            $chain
        );
    }

    /** The node client of a chain: the XRP Ledger's or Xahau's nodes, fixed in the core. */
    public function getXrplClient(string $chain = ChainCatalog::XRPL): XrplClient
    {
        return new XrplClient($this->getHttpClient(), $this->getHttpFactory(), $this->getHttpFactory(), $chain);
    }

    /**
     * Keeps the status endpoint and the payment page from syncing the same
     * receiving account more often than once per interval
     * (PaymentStatus::MIN_SYNC_INTERVAL_SECONDS). The mark lives in the same
     * transient store as the rate cache; an object cache that evicts it
     * degrades to "always sync", never to "never sync".
     */
    public function getSyncThrottle(string $chain = ChainCatalog::XRPL): SyncThrottle
    {
        return $this->syncThrottles[$chain] ??= new SyncThrottle($this->getRateCache(), $this->getLogger(), chain: $chain);
    }

    public function getOrderTransactionService(): OrderTransactionService
    {
        return $this->orderTransactionService ??= new OrderTransactionService(
            $this,
            $this->getSettlementPolicy(),
            new LegacyPaymentIntentMapper(),
            $this->getLogger()
        );
    }

    /** Nyholm's Psr17Factory is both the PSR-17 request and stream factory. */
    private function getHttpFactory(): Psr17Factory
    {
        return $this->httpFactory ??= new Psr17Factory();
    }
}
