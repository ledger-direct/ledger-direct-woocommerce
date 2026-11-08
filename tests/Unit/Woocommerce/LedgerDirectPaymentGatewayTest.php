<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Woocommerce;

use Hardcastle\LedgerDirect\Chain\SupportedChains;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use PHPUnit\Framework\TestCase;

class LedgerDirectPaymentGatewayTest extends TestCase
{
    public function testCheckoutPaymentTypesMapToCoreChainsAndAssets(): void
    {
        $this->assertSame(['XRPL', 'XRP'], self::chainAndAsset(LedgerDirectPaymentGateway::XRP_PAYMENT_ID));
        $this->assertSame(['XRPL', 'RLUSD'], self::chainAndAsset(LedgerDirectPaymentGateway::RLUSD_PAYMENT_ID));
        $this->assertSame(['XRPL', 'USDC'], self::chainAndAsset(LedgerDirectPaymentGateway::USDC_PAYMENT_ID));
        $this->assertSame(['XAHAU', 'XAH'], self::chainAndAsset(LedgerDirectPaymentGateway::XAH_PAYMENT_ID));
        $this->assertNull(SupportedChains::chainAndAssetFor('eurc'));
    }

    /**
     * The XRP Ledger's types need its account, Xahau's its switch and account; the first offered
     * type is the preselected one, so XRP comes first while the XRP Ledger is configured.
     */
    public function testTheAvailablePaymentTypesFollowTheConfiguration(): void
    {
        $this->assertSame(['xrp'], LedgerDirectPaymentGateway::available_payment_types(['xrpl_available' => true]));
        $this->assertSame(
            ['xrp', 'rlusd', 'usdc', 'xah'],
            LedgerDirectPaymentGateway::available_payment_types(['xrpl_available' => true, 'rlusd_available' => true, 'usdc_available' => true, 'xah_available' => true])
        );
        $this->assertSame(['xah'], LedgerDirectPaymentGateway::available_payment_types(['xrpl_available' => false, 'rlusd_available' => true, 'xah_available' => true]), 'no XRPL account, no XRPL types');
        $this->assertSame([], LedgerDirectPaymentGateway::available_payment_types([]));
    }

    /** @return array{0: string, 1: string}|null */
    private static function chainAndAsset(string $paymentType): ?array
    {
        $found = SupportedChains::chainAndAssetFor($paymentType);

        return $found === null ? null : [$found[0]->chain, $found[1]->code];
    }

    public function testGatewayRegistersUnderItsStableId(): void
    {
        $this->assertSame('ledger-direct', LedgerDirectPaymentGateway::ID);
    }
}
