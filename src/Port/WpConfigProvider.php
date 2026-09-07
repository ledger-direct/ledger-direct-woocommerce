<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use InvalidArgumentException;

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
    public const CHAIN = 'XRPL';

    public const OPTION_NAME = 'woocommerce_ledger-direct_settings';

    public const NETWORK_MAINNET = 'mainnet';
    public const NETWORK_TESTNET = 'testnet';

    public const KEY_NETWORK = 'xrpl_network';
    public const KEY_MAINNET_ACCOUNT = 'xrpl_mainnet_destination_account';
    public const KEY_TESTNET_ACCOUNT = 'xrpl_testnet_destination_account';
    public const KEY_RLUSD_ENABLED = 'xrpl_is_rlusd_enabled';
    public const KEY_USDC_ENABLED = 'xrpl_is_usdc_enabled';

    /** Admin field, in minutes (that is how it is labelled and stored). */
    public const KEY_QUOTE_EXPIRY = 'xrpl_quote_expiry';

    public const DEFAULT_QUOTE_EXPIRY_MINUTES = 15;
    public const MIN_QUOTE_EXPIRY_MINUTES = 1;
    public const MAX_QUOTE_EXPIRY_MINUTES = 60;

    public const XRPL_ACCOUNT_REGEX = '/^r[1-9A-HJ-NP-Za-km-z]{25,34}$/';

    /** @var array<string, mixed>|null */
    private ?array $settings;

    /**
     * @param array<string, mixed>|null $settings Injected for tests; null reads the option.
     */
    public function __construct(?array $settings = null)
    {
        $this->settings = $settings;
    }

    public function getNetwork(string $chain): string
    {
        $this->assertChain($chain);

        return $this->setting(self::KEY_NETWORK) === self::NETWORK_MAINNET
            ? self::NETWORK_MAINNET
            : self::NETWORK_TESTNET;
    }

    public function getDestinationAccount(string $chain): string
    {
        $this->assertChain($chain);

        $key = $this->getNetwork($chain) === self::NETWORK_MAINNET
            ? self::KEY_MAINNET_ACCOUNT
            : self::KEY_TESTNET_ACCOUNT;

        return trim((string) $this->setting($key, ''));
    }

    /**
     * Whether a syntactically valid receiving account is configured for
     * the active network. Not part of the core port; the gateway uses it
     * to decide whether to offer itself at checkout at all.
     */
    public function hasDestinationAccount(): bool
    {
        return (bool) preg_match(self::XRPL_ACCOUNT_REGEX, $this->getDestinationAccount(self::CHAIN));
    }

    /**
     * XRP is always accepted - it is the ledger's native asset and needs no
     * trustline. The stablecoins are opt-in via the gateway settings.
     */
    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        $this->assertChain($chain);

        return match (strtoupper($baseAsset)) {
            'XRP' => true,
            'RLUSD' => $this->setting(self::KEY_RLUSD_ENABLED, 'no') === 'yes',
            'USDC' => $this->setting(self::KEY_USDC_ENABLED, 'no') === 'yes',
            default => false,
        };
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

    /**
     * The plugin only speaks XRPL. Asking for another chain is a wiring
     * mistake, not a configuration state, so it fails loudly.
     */
    private function assertChain(string $chain): void
    {
        if ($chain !== self::CHAIN) {
            throw new InvalidArgumentException(sprintf(
                'LedgerDirect supports chain "%s" only, got "%s".',
                esc_html(self::CHAIN),
                esc_html($chain)
            ));
        }
    }
}
