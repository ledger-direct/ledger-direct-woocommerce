<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Service\ServiceFactory;

/**
 * Syncs a freshly funded XRPL testnet faucet account through the real
 * network stack (WpHttpClient → core XrplClient → SyncService → $wpdb).
 *
 * Opt-in only - it needs network access and the testnet faucet:
 *   vendor/bin/phpunit -c phpunit.xml --group external-http
 *
 * A fresh faucet account has exactly one transaction of history, so the
 * sync is fast; never point this at an issuer address.
 *
 * @group external-http
 */
class RealTestnetSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_tx");
        ServiceFactory::setInstance(null);
    }

    protected function tearDown(): void
    {
        ServiceFactory::setInstance(null);

        parent::tearDown();
    }

    public function testSyncsAFaucetAccountFromTheRealTestnet(): void
    {
        $faucet = wp_remote_post('https://faucet.altnet.rippletest.net/accounts', ['timeout' => 30]);

        if (is_wp_error($faucet) || wp_remote_retrieve_response_code($faucet) !== 200) {
            $this->markTestSkipped('Testnet faucet not reachable.');
        }

        $account = json_decode((string) wp_remote_retrieve_body($faucet), true)['account']['address'] ?? null;
        $this->assertIsString($account);

        // The funding payment needs a validated ledger before account_tx sees it.
        sleep(6);

        $factory = ServiceFactory::getInstance();
        $factory->getSyncService()->syncTransactions($account, 'testnet');

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}ledger_direct_xrpl_tx WHERE destination = %s", $account),
            ARRAY_A
        );

        $this->assertNotEmpty($rows, 'The faucet funding payment should have been synced.');
        $this->assertSame(64, strlen($rows[0]['hash']));
        $this->assertNotEmpty($rows[0]['ctid']);
        $this->assertGreaterThan(0, (int) $rows[0]['ledger_index']);
        $this->assertIsString($factory->getTransactionRepository()->getLastSyncedLedgerIndex());

        $transaction = $factory->getTransactionRepository()->findTransaction($account, (int) ($rows[0]['destination_tag'] ?? 0));
        if ($transaction !== null) {
            $this->assertIsFloat($transaction->getDeliveredAmount());
        }
    }
}
