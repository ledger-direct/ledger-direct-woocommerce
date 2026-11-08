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

    /**
     * The same r-address is a different account on each chain (core INVARIANTS.md, "Xahau"): a Xahau
     * account that is the XRP Ledger's on the same network is kept, with a message for the merchant.
     */
    public function testAXahauAccountThatIsTheXrpLedgersIsKeptWithAMessage(): void
    {
        $gateway = new LedgerDirectPaymentGateway();

        $kept = $gateway->validate_xahau_testnet_destination_account_field('xahau_testnet_destination_account', ' ' . XrplNetworkStub::ACCOUNT . ' ');

        self::assertSame(XrplNetworkStub::ACCOUNT, $kept);
        self::assertNotEmpty(
            array_filter($this->adminMessages(), static fn (string $m): bool => str_contains($m, XrplNetworkStub::ACCOUNT)),
            'a message that names the account'
        );
    }

    public function testADifferentXahauAccountPassesWithoutAMessage(): void
    {
        $gateway = new LedgerDirectPaymentGateway();
        $before = count($this->adminMessages());

        self::assertSame('rDF9bEuYAmkEfeQgf7C64BsJWJoWqS5NaV', $gateway->validate_xahau_mainnet_destination_account_field('xahau_mainnet_destination_account', 'rDF9bEuYAmkEfeQgf7C64BsJWJoWqS5NaV'));
        self::assertCount($before, $this->adminMessages());
    }

    /**
     * WC_Admin_Settings keeps its messages in a private static and renders them only when no
     * error is queued, which other tests in this process have done; the array is the observable.
     *
     * @return string[]
     */
    private function adminMessages(): array
    {
        return (new \ReflectionClass(\WC_Admin_Settings::class))->getStaticPropertyValue('messages');
    }

    public function testEmptyClearsTheAccount(): void
    {
        $gateway = new LedgerDirectPaymentGateway();

        self::assertSame('', $gateway->validate_xrpl_testnet_destination_account_field(WpConfigProvider::KEY_TESTNET_ACCOUNT, ''));
    }
}
