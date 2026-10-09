<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Port\WpConfigProvider;
use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;

/**
 * The receiving account decides where customer money goes: a string that
 * cannot be an XRPL address is refused and the stored value kept.
 */
class GatewaySettingsValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        update_option(WpConfigProvider::OPTION_NAME, [
            WpConfigProvider::KEY_TESTNET_ACCOUNT => XrplNetworkStub::ACCOUNT,
            WpConfigProvider::KEY_MAINNET_ACCOUNT => '',
        ]);
    }

    public function testAnXrplAddressIsStoredTrimmed(): void
    {
        $gateway = new LedgerDirectPaymentGateway();

        self::assertSame(
            'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
            $gateway->validate_xrpl_mainnet_destination_account_field(WpConfigProvider::KEY_MAINNET_ACCOUNT, ' rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De ')
        );
    }

    public function testSomethingElseKeepsThePreviousValue(): void
    {
        $gateway = new LedgerDirectPaymentGateway();

        self::assertSame(
            XrplNetworkStub::ACCOUNT,
            $gateway->validate_xrpl_testnet_destination_account_field(WpConfigProvider::KEY_TESTNET_ACCOUNT, '1836426632')
        );
        self::assertSame(
            '',
            $gateway->validate_xrpl_mainnet_destination_account_field(WpConfigProvider::KEY_MAINNET_ACCOUNT, '0x71C7656EC7ab88b098defB751B7401B5f6d8976F')
        );
    }

    public function testEmptyClearsTheAccount(): void
    {
        $gateway = new LedgerDirectPaymentGateway();

        self::assertSame('', $gateway->validate_xrpl_testnet_destination_account_field(WpConfigProvider::KEY_TESTNET_ACCOUNT, ''));
    }
}
