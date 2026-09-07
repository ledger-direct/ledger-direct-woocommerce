<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\XrplTxService;
use PHPUnit\Framework\TestCase;
use Hardcastle\LedgerDirect\Service\XrplClientService;

class XrplTxServiceTest extends TestCase
{
    private XrplTxService $xrplTxService;
    private XrplClientService $clientService;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ledger_direct_xrpl_destination_tag");

        $this->clientService = $this->createMock(XrplClientService::class);

        $this->xrplTxService = new XrplTxService($this->clientService);
    }

    public function testGenerateDestinationTag(): void
    {
        $destinationTag = $this->xrplTxService->generateDestinationTag('rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK');

        $this->assertIsInt($destinationTag);
        $this->assertGreaterThanOrEqual(XrplTxService::DESTINATION_TAG_RANGE_MIN, $destinationTag);
        $this->assertLessThanOrEqual(XrplTxService::DESTINATION_TAG_RANGE_MAX, $destinationTag);
    }

    /**
     * Regression test for W1: generateDestinationTag() used to read/write a
     * table name (`xrpl_destination_tag`) that install.php never created, so
     * the insert silently failed and no row was ever persisted.
     */
    public function testGenerateDestinationTagPersistsToDestinationTagTable(): void
    {
        global $wpdb;

        $destinationTag = $this->xrplTxService->generateDestinationTag('rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK');

        $table = $wpdb->prefix . 'ledger_direct_xrpl_destination_tag';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE destination_tag = %d", $destinationTag),
            ARRAY_A
        );

        $this->assertNotNull($row);
        $this->assertSame('rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK', $row['account']);
    }

    public function testGenerateDestinationTagDoesNotReuseAnAlreadyReservedTag(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ledger_direct_xrpl_destination_tag';
        $reservedTag = XrplTxService::DESTINATION_TAG_RANGE_MIN;
        $wpdb->insert($table, ['destination_tag' => $reservedTag, 'account' => 'rExistingAccount'], ['%d', '%s']);

        for ($i = 0; $i < 20; $i++) {
            $destinationTag = $this->xrplTxService->generateDestinationTag('rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK');
            $this->assertNotSame($reservedTag, $destinationTag);
        }
    }

    /**
     * Regression test: syncTransactions() used to call fetchAccountTransactions()
     * without ever passing the marker back in, so an account with more
     * transactions than fit in one account_tx page would refetch the same
     * first page forever instead of paginating.
     */
    public function testSyncTransactionsThreadsThePaginationMarkerThroughSubsequentFetches(): void
    {
        $capturedMarkers = [];

        $this->clientService->method('fetchAccountTransactions')
            ->willReturnCallback(function (string $address, ?int $lastLedgerIndex, ?array $marker = null) use (&$capturedMarkers) {
                $capturedMarkers[] = $marker;

                if (count($capturedMarkers) === 1) {
                    return ['transactions' => [], 'marker' => ['page' => 2]];
                }

                return ['transactions' => []];
            });

        $this->xrplTxService->syncTransactions('rSomeAccount');

        $this->assertCount(2, $capturedMarkers);
        $this->assertNull($capturedMarkers[0]);
        $this->assertSame(['page' => 2], $capturedMarkers[1]);
    }
}