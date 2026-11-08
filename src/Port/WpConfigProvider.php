<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Chain\SupportedChains;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;

/**
 * Platform side of {@see ConfigProviderInterface}: reads the merchant's
 * LedgerDirect settings out of the WooCommerce gateway option.
 *
 * Reads the option directly rather than through the gateway instance so
 * the core services can be built without WooCommerce's payment gateway
 * bootstrap being finished (and without the gateway needing the services
 * first - it does).
 *
 * Note what is *not* here: issuer addresses and currency codes. Those live
 * in the core's StablecoinRegistry and are never merchant-configurable.
 */
class WpConfigProvider implements ConfigProviderInterface
{
    public const CHAIN = ChainCatalog::XRPL;
    public const CHAIN_XAHAU = ChainCatalog::XAHAU;

    public const OPTION_NAME = 'woocommerce_ledger-direct_settings';

    public const NETWORK_MAINNET = 'mainnet';
    public const NETWORK_TESTNET = 'testnet';

    public const KEY_NETWORK = 'xrpl_network';
    public const KEY_MAINNET_ACCOUNT = 'xrpl_mainnet_destination_account';
    public const KEY_TESTNET_ACCOUNT = 'xrpl_testnet_destination_account';
    public const KEY_RLUSD_ENABLED = 'xrpl_is_rlusd_enabled';
    public const KEY_USDC_ENABLED = 'xrpl_is_usdc_enabled';

    /**
     * Xahau's keys follow the pattern every chain after the XRP Ledger takes:
     * <chain>_enabled, <chain>_network, one account per network. The XRPL keys
     * above predate it and stay as they are; the XRP Ledger has no "enabled"
     * key of its own - the gateway's switch is its switch.
     */
    public const KEY_XAHAU_ENABLED = 'xahau_enabled';
    public const KEY_XAHAU_NETWORK = 'xahau_network';
    public const KEY_XAHAU_MAINNET_ACCOUNT = 'xahau_mainnet_destination_account';
    public const KEY_XAHAU_TESTNET_ACCOUNT = 'xahau_testnet_destination_account';

    /** Admin field, in minutes (that is how it is labelled and stored). */
    public const KEY_QUOTE_EXPIRY = 'xrpl_quote_expiry';

    public const DEFAULT_QUOTE_EXPIRY_MINUTES = 15;
    public const MIN_QUOTE_EXPIRY_MINUTES = 1;
    public const MAX_QUOTE_EXPIRY_MINUTES = 60;

    /** @var array<string, mixed>|null */
    private ?array $settings;

    /**
     * @param array<string, mixed>|null $settings Injected for tests; null reads the option.
     */
    public function __construct(?array $settings = null)
    {
        $this->settings = $settings;
    }

    /**
     * A chain the plugin carries nothing on is answered with its testnet, no
     * account and no asset - never with another chain's settings.
     */
    public function getNetwork(string $chain): string
    {
        if (!SupportedChains::supports($chain)) {
            return self::NETWORK_TESTNET;
        }

        return $this->setting(self::networkKey($chain)) === self::NETWORK_MAINNET
            ? self::NETWORK_MAINNET
            : self::NETWORK_TESTNET;
    }

    public function getDestinationAccount(string $chain): string
    {
        if (!SupportedChains::supports($chain)) {
            return '';
        }

        return trim((string) $this->setting(self::accountKey($chain, $this->getNetwork($chain)), ''));
    }

    /**
     * Whether a chain's receiving account for its network is set and well-formed.
     * The chain's payment types are offered only then.
     */
    public function hasDestinationAccount(string $chain = self::CHAIN): bool
    {
        $account = $this->getDestinationAccount($chain);

        return $account !== '' && ChainCatalog::chain($chain)->isValidAccount($account);
    }

    /** Whether the chain is switched on: Xahau by its own switch, the XRP Ledger always (the gateway's switch is its switch). */
    public function isChainEnabled(string $chain): bool
    {
        if (!SupportedChains::supports($chain)) {
            return false;
        }

        return $chain === self::CHAIN || $this->setting(self::KEY_XAHAU_ENABLED, 'no') === 'yes';
    }

    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        if (!$this->isChainEnabled($chain) || !SupportedChains::offers($chain, $baseAsset)) {
            return false;
        }

        return match (strtoupper($baseAsset)) {
            'RLUSD' => $this->setting(self::KEY_RLUSD_ENABLED, 'no') === 'yes',
            'USDC' => $this->setting(self::KEY_USDC_ENABLED, 'no') === 'yes',
            default => true, // the native asset is on with its chain
        };
    }

    /** The key of a chain's network setting. */
    public static function networkKey(string $chain): string
    {
        return $chain === self::CHAIN ? self::KEY_NETWORK : strtolower($chain) . '_network';
    }

    /** The key of a chain's receiving account on a network. */
    public static function accountKey(string $chain, string $network): string
    {
        $prefix = $chain === self::CHAIN ? 'xrpl' : strtolower($chain);

        return $prefix . '_' . ($network === self::NETWORK_MAINNET ? 'mainnet' : 'testnet') . '_destination_account';
    }

    public function getQuoteExpirySeconds(): int
    {
        $minutes = $this->setting(self::KEY_QUOTE_EXPIRY);

        if (!is_numeric($minutes)) {
            $minutes = self::DEFAULT_QUOTE_EXPIRY_MINUTES;
        }

        $minutes = max(self::MIN_QUOTE_EXPIRY_MINUTES, min(self::MAX_QUOTE_EXPIRY_MINUTES, (int) $minutes));

        return $minutes * MINUTE_IN_SECONDS;
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        if ($this->settings === null) {
            $option = get_option(self::OPTION_NAME, []);
            $this->settings = is_array($option) ? $option : [];
        }

        return $this->settings[$key] ?? $default;
    }
}
