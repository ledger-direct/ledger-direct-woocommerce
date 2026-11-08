<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Chain;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Hardcastle\LedgerDirect\Core\AssetDescriptor;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\ChainDescriptor;

/**
 * The chains this gateway offers payment types on, out of the core's catalogue.
 *
 * One payment type per (chain, asset): the catalogue says which chains and assets
 * exist, the settings say which are switched on. A chain the core knows but this
 * plugin does not yet carry stands in PENDING_CHAINS, and a test keeps the two in
 * step with the catalogue: a chain the core adds turns it red until the plugin decides.
 */
final class SupportedChains
{
    public const PENDING_CHAINS = [ChainCatalog::STELLAR];

    /**
     * The gateway's payment type (the radio value, `ledger_direct_payment_type`) per asset
     * code. The three XRPL types predate the catalogue; new ones are the lower-case code.
     */
    private const PAYMENT_TYPE_BY_ASSET = [
        'XRP' => 'xrp',
        'RLUSD' => 'rlusd',
        'USDC' => 'usdc',
        'XAH' => 'xah',
    ];

    /**
     * The carried chains, in catalogue order.
     *
     * @return string[]
     */
    public static function chains(): array
    {
        $chains = [];
        foreach (ChainCatalog::chains() as $chain) {
            if (!in_array($chain->chain, self::PENDING_CHAINS, true)) {
                $chains[] = $chain->chain;
            }
        }

        return $chains;
    }

    public static function supports(string $chain): bool
    {
        return in_array($chain, self::chains(), true);
    }

    /** Whether the plugin offers the asset on the chain: a carried chain with that asset in the catalogue. */
    public static function offers(string $chain, string $asset): bool
    {
        return self::supports($chain) && ChainCatalog::chain($chain)->hasAsset(strtoupper($asset));
    }

    public static function descriptor(string $chain): ChainDescriptor
    {
        return ChainCatalog::chain($chain);
    }

    /** The payment type for an asset on a carried chain, or null. */
    public static function paymentTypeFor(string $chain, string $asset): ?string
    {
        return self::offers($chain, $asset) ? (self::PAYMENT_TYPE_BY_ASSET[strtoupper($asset)] ?? null) : null;
    }

    /**
     * The chain and asset a payment type stands for, or null for one that is not ours.
     * Asset codes are distinct across the carried chains (XRP, RLUSD, USDC on the XRP
     * Ledger, XAH on Xahau), so the type names its chain on its own.
     *
     * @return array{0: ChainDescriptor, 1: AssetDescriptor}|null
     */
    public static function chainAndAssetFor(string $paymentType): ?array
    {
        $asset = array_search(strtolower($paymentType), self::PAYMENT_TYPE_BY_ASSET, true);
        if ($asset === false) {
            return null;
        }

        foreach (self::chains() as $chain) {
            $descriptor = ChainCatalog::chain($chain);
            if ($descriptor->hasAsset($asset)) {
                return [$descriptor, $descriptor->asset($asset)];
            }
        }

        return null;
    }
}
