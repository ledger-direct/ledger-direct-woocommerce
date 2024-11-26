<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\XrplClientService;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Hardcastle\XRPL_PHP\Core\Networks;
use PHPUnit\Framework\TestCase;

class XrplClientServiceTest extends TestCase
{
    private XrplClientService $xrplClientService;

    public function setUp(): void
    {
        $configurationService = ConfigurationServiceMock::createInstance();
        $this->xrplClientService = new XrplClientService($configurationService);
    }
    public function testFetchAccountTransactions(): void
    {
        $transactions = $this->xrplClientService->fetchAccountTransactions('rDB6HTYVdcMcKWTViGgqfnYfQKb7CFxiVR', null);
        $this->assertCount(1, $transactions);
    }

    public function testGetNetwork(): void
    {
        $network = $this->xrplClientService->getNetwork();
        $this->assertEquals(Networks::getNetwork('testnet'), $network);
    }
}
