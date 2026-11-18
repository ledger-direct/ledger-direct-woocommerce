<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

use Hardcastle\LedgerDirect\Core\Account\Finding;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Service\ServiceFactory;

/**
 * The receiving-account notices above the settings page, as class and text.
 *
 * Lives in src/ and not in includes/admin/: the release build prefixes the
 * core's namespace (PHP-Scoper) only here, and a core class named under
 * includes/ fails the scoped build's check.
 */
final class AccountCheckNotices
{
    /**
     * What stands in a customer's way, per chain with a saved receiving account (core 0.12,
     * INVARIANTS.md "Receiving account"): a token without a trustline, an account that is not
     * activated, deposit authorization. Shown above the settings, which stay as they are — the
     * checkout leaves out what the account cannot take. Read afresh from the ledger.
     *
     * @return list<array{class: string, text: string}>
     */
    public static function forSettings(ServiceFactory $services): array
    {
        $config = $services->getConfigProvider();
        $notices = [];

        foreach ([WpConfigProvider::CHAIN, WpConfigProvider::CHAIN_XAHAU] as $chain) {
            if (!$config->hasDestinationAccount($chain)) {
                continue;
            }
            $descriptor = ChainCatalog::chain($chain);
            $assets = [];
            foreach ($descriptor->assets as $asset) {
                if ($config->isAssetEnabled($chain, $asset->code)) {
                    $assets[] = $asset->code;
                }
            }
            if ($assets === []) {
                continue;
            }

            $account = $config->getDestinationAccount($chain);
            $network = $config->getNetwork($chain);
            $assessment = $services->getAccountInspector($chain)->assess($account, $network, $assets);

            if ($assessment->isClean()) {
                /* translators: 1: chain name, e.g. XRP Ledger, 2: account address, 3: network (mainnet or testnet) */
                $notices[] = ['class' => 'notice-success', 'text' => sprintf(__('%1$s: the receiving account %2$s on the %3$s can take every asset that is switched on.', 'ledger-direct'), $descriptor->label, $account, $network)];
                continue;
            }

            foreach ($assessment->findings as $finding) {
                $blocking = $finding->blocksOffer || $finding->code === Finding::ACCOUNT_MISSING;
                $notices[] = ['class' => $blocking ? 'notice-warning' : 'notice-info', 'text' => $descriptor->label . ': ' . self::findingText($finding, $descriptor->nativeAsset)];
            }
        }

        return $notices;
    }

    public static function findingText(Finding $finding, string $native): string
    {
        $asset = (string) $finding->asset;
        $issuer = (string) ($finding->context['issuer'] ?? '');

        switch ($finding->code) {
            case Finding::ACCOUNT_MISSING:
                /* translators: 1: base reserve, e.g. 1, 2: native asset, e.g. XRP */
                return sprintf(__('The account is not activated yet. A first payment of at least %1$s %2$s activates it; until then smaller orders in %2$s fail, and tokens are not offered.', 'ledger-direct'), (string) ($finding->context['reserve'] ?? ''), $native);
            case Finding::DEPOSIT_AUTH:
                return __('Deposit authorization is set on the account: only senders you authorized can pay. The payment methods stay in the checkout.', 'ledger-direct');
            case Finding::TRUSTLINE_MISSING:
                /* translators: 1: asset, e.g. RLUSD, 2: issuer address */
                return sprintf(__('%1$s is not offered in the checkout: the account has no trustline to the issuer %2$s.', 'ledger-direct'), $asset, $issuer);
            case Finding::TRUSTLINE_NOT_AUTHORIZED:
                /* translators: 1: asset, e.g. RLUSD, 2: issuer address */
                return sprintf(__('%1$s is not offered in the checkout: the issuer %2$s has not authorized the trustline.', 'ledger-direct'), $asset, $issuer);
            case Finding::TRUSTLINE_FROZEN:
                /* translators: %s: asset, e.g. RLUSD */
                return sprintf(__('%s is not offered in the checkout: the trustline is frozen.', 'ledger-direct'), $asset);
            case Finding::TRUSTLINE_FULL:
                /* translators: %s: asset, e.g. RLUSD */
                return sprintf(__('%s is not offered in the checkout: the trustline has reached its limit.', 'ledger-direct'), $asset);
            case Finding::ISSUER_FROZEN:
                /* translators: 1: asset, e.g. RLUSD, 2: issuer address */
                return sprintf(__('%1$s is not offered in the checkout: the issuer %2$s has frozen all its trustlines.', 'ledger-direct'), $asset, $issuer);
            case Finding::NOT_CHECKED:
                /* translators: %s: native asset, e.g. XRP */
                return sprintf(__('The ledger could not be asked just now. Until it can, tokens are not offered; %s is.', 'ledger-direct'), $native);
            default:
                return $finding->code;
        }
    }
}
