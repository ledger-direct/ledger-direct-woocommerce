<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Manual\ClassHelper;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use PHPUnit\Framework\TestCase;

class ConfigurationServiceTest extends TestCase
{
    private ConfigurationService $configurationService;

    protected function setUp(): void
    {
        $this->configurationService = ConfigurationServiceMock::createInstance();
    }

    public function testIsTest(): void
    {
        $result = $this->configurationService->isTest();
        $this->assertTrue($result);
    }

    public function testGetDestinationAccount(): void
    {
        $result = $this->configurationService->getDestinationAccount();
        $this->assertEquals('rL7DjHoSvkn8TXYPcv6sBsJRwqdzAc6VxK', $result);
    }

    public function testGetTokenName(): void
    {
        $result = $this->configurationService->getTokenName();
        $this->assertEquals('LPT', $result);
    }

    public function testGetIssuer(): void
    {
        $result = $this->configurationService->getIssuer();
        $this->assertEquals('rpEvFBbceea6Ze4xEcMiL9smCT4pprXPU2', $result);
    }
}