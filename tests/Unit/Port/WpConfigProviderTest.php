<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Port;

use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WpConfigProviderTest extends TestCase
{
    private const MAINNET_ACCOUNT = 'rMainnetAccountXXXXXXXXXXXXXXXXXXX';
    private const TESTNET_ACCOUNT = 'rTestnetAccountXXXXXXXXXXXXXXXXXXX';

    private function provider(array $overrides = []): WpConfigProvider
    {
        return new WpConfigProvider(array_merge([
            WpConfigProvider::KEY_NETWORK => 'testnet',
            WpConfigProvider::KEY_MAINNET_ACCOUNT => self::MAINNET_ACCOUNT,
            WpConfigProvider::KEY_TESTNET_ACCOUNT => self::TESTNET_ACCOUNT,
            WpConfigProvider::KEY_RLUSD_ENABLED => 'no',
            WpConfigProvider::KEY_USDC_ENABLED => 'no',
            WpConfigProvider::KEY_QUOTE_EXPIRY => '15',
        ], $overrides));
    }

    public function testNetworkDefaultsToTestnetUnlessMainnetIsConfigured(): void
    {
        $this->assertSame('testnet', $this->provider()->getNetwork('XRPL'));
        $this->assertSame('testnet', $this->provider([WpConfigProvider::KEY_NETWORK => 'garbage'])->getNetwork('XRPL'));
        $this->assertSame('mainnet', $this->provider([WpConfigProvider::KEY_NETWORK => 'mainnet'])->getNetwork('XRPL'));
    }

    public function testDestinationAccountFollowsTheActiveNetwork(): void
    {
        $this->assertSame(self::TESTNET_ACCOUNT, $this->provider()->getDestinationAccount('XRPL'));
        $this->assertSame(
            self::MAINNET_ACCOUNT,
            $this->provider([WpConfigProvider::KEY_NETWORK => 'mainnet'])->getDestinationAccount('XRPL')
        );
    }

    public function testHasDestinationAccountValidatesTheAddressFormat(): void
    {
        $this->assertTrue($this->provider([WpConfigProvider::KEY_TESTNET_ACCOUNT => 'rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK'])->hasDestinationAccount());
        $this->assertFalse($this->provider([WpConfigProvider::KEY_TESTNET_ACCOUNT => ''])->hasDestinationAccount());
        $this->assertFalse($this->provider([WpConfigProvider::KEY_TESTNET_ACCOUNT => 'not-an-address'])->hasDestinationAccount());
    }

    public function testXrpIsAlwaysEnabledAndStablecoinsAreOptIn(): void
    {
        $provider = $this->provider();

        $this->assertTrue($provider->isAssetEnabled('XRPL', 'XRP'));
        $this->assertFalse($provider->isAssetEnabled('XRPL', 'RLUSD'));
        $this->assertFalse($provider->isAssetEnabled('XRPL', 'USDC'));
        $this->assertFalse($provider->isAssetEnabled('XRPL', 'EURC'));
    }

    /**
     * Regression test: the previous configuration helper wired the USDC
     * checkbox to RLUSD on mainnet and the RLUSD checkbox to USDC on testnet.
     */
    public function testEachStablecoinFlagControlsOnlyItsOwnAssetOnEveryNetwork(): void
    {
        foreach (['testnet', 'mainnet'] as $network) {
            $rlusdOnly = $this->provider([
                WpConfigProvider::KEY_NETWORK => $network,
                WpConfigProvider::KEY_RLUSD_ENABLED => 'yes',
            ]);
            $this->assertTrue($rlusdOnly->isAssetEnabled('XRPL', 'RLUSD'), $network);
            $this->assertFalse($rlusdOnly->isAssetEnabled('XRPL', 'USDC'), $network);

            $usdcOnly = $this->provider([
                WpConfigProvider::KEY_NETWORK => $network,
                WpConfigProvider::KEY_USDC_ENABLED => 'yes',
            ]);
            $this->assertFalse($usdcOnly->isAssetEnabled('XRPL', 'RLUSD'), $network);
            $this->assertTrue($usdcOnly->isAssetEnabled('XRPL', 'USDC'), $network);
        }
    }

    public function testQuoteExpiryIsConfiguredInMinutesAndReportedInSeconds(): void
    {
        $this->assertSame(15 * 60, $this->provider()->getQuoteExpirySeconds());
        $this->assertSame(5 * 60, $this->provider([WpConfigProvider::KEY_QUOTE_EXPIRY => '5'])->getQuoteExpirySeconds());
    }

    public function testQuoteExpiryFallsBackAndIsClamped(): void
    {
        $default = WpConfigProvider::DEFAULT_QUOTE_EXPIRY_MINUTES * 60;

        $this->assertSame($default, $this->provider([WpConfigProvider::KEY_QUOTE_EXPIRY => ''])->getQuoteExpirySeconds());
        $this->assertSame($default, $this->provider([WpConfigProvider::KEY_QUOTE_EXPIRY => 'abc'])->getQuoteExpirySeconds());
        $this->assertSame(60, $this->provider([WpConfigProvider::KEY_QUOTE_EXPIRY => '0'])->getQuoteExpirySeconds());
        $this->assertSame(60 * 60, $this->provider([WpConfigProvider::KEY_QUOTE_EXPIRY => '900'])->getQuoteExpirySeconds());
    }

    public function testRejectsAnyOtherChain(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->provider()->getNetwork('XLM');
    }
}
