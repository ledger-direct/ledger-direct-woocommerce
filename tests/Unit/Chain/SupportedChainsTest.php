<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Chain;

use Hardcastle\LedgerDirect\Chain\SupportedChains;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The completeness rule behind the payment types: every chain the core knows is either
 * carried, with a payment type and a checkout icon for each of its assets, or stands in
 * PENDING_CHAINS. A new chain in the core turns this red until the plugin decides.
 */
class SupportedChainsTest extends TestCase
{
    public function testEveryCatalogueChainIsCarriedOrExplicitlyPending(): void
    {
        foreach (ChainCatalog::chains() as $chain) {
            $pending = in_array($chain->chain, SupportedChains::PENDING_CHAINS, true);
            $this->assertSame(!$pending, SupportedChains::supports($chain->chain), $chain->chain);

            foreach ($chain->assets as $asset) {
                $type = SupportedChains::paymentTypeFor($chain->chain, $asset->code);
                if ($pending) {
                    $this->assertNull($type, "{$chain->chain} is pending but {$asset->code} has a payment type");
                    continue;
                }

                $this->assertNotNull($type, "{$chain->chain} {$asset->code} has no payment type");
                $this->assertSame([$chain, $asset], SupportedChains::chainAndAssetFor($type), 'round trip');
                $this->assertFileExists(dirname(__DIR__, 3) . '/public/images/' . $type . '_payment.svg', 'checkout icon');
            }
        }
    }

    public function testTheXrplTypesAreTheOnesFromBeforeTheCatalogue(): void
    {
        $this->assertSame(['XRPL', 'XAHAU'], SupportedChains::chains());
        $this->assertSame('xrp', SupportedChains::paymentTypeFor('XRPL', 'XRP'));
        $this->assertSame('rlusd', SupportedChains::paymentTypeFor('XRPL', 'rlusd'));
        $this->assertSame('xah', SupportedChains::paymentTypeFor('XAHAU', 'XAH'));
    }

    public function testNothingIsOfferedOutsideTheCatalogue(): void
    {
        $this->assertNull(SupportedChains::paymentTypeFor('XRPL', 'XAH'), 'XAH is not an XRPL asset');
        $this->assertNull(SupportedChains::paymentTypeFor('XAHAU', 'RLUSD'));
        $this->assertNull(SupportedChains::paymentTypeFor('STELLAR', 'XLM'), 'pending');
        $this->assertFalse(SupportedChains::offers('DOGE', 'DOGE'));
        $this->assertNull(SupportedChains::chainAndAssetFor('checkmo'));
    }
}
