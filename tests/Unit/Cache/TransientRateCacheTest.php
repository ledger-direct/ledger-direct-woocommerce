<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Cache;

use DateInterval;
use Hardcastle\LedgerDirect\Cache\TransientRateCache;
use PHPUnit\Framework\TestCase;

class TransientRateCacheTest extends TestCase
{
    private const KEY = 'ledger-direct.rate.v1.testnet.XRP.EUR';

    private TransientRateCache $cache;

    protected function setUp(): void
    {
        $this->cache = new TransientRateCache();
        $this->cache->delete(self::KEY);
    }

    public function testMissReturnsTheDefault(): void
    {
        $this->assertNull($this->cache->get(self::KEY));
        $this->assertSame('fallback', $this->cache->get(self::KEY, 'fallback'));
        $this->assertFalse($this->cache->has(self::KEY));
    }

    public function testRoundTripsTheCoreEntryShape(): void
    {
        $entry = ['rate' => 0.4321, 'fetched_at' => 1700000000];

        $this->assertTrue($this->cache->set(self::KEY, $entry, 300));
        $this->assertSame($entry, $this->cache->get(self::KEY));
        $this->assertTrue($this->cache->has(self::KEY));

        $this->assertTrue($this->cache->delete(self::KEY));
        $this->assertNull($this->cache->get(self::KEY));
    }

    public function testAcceptsADateIntervalTtl(): void
    {
        $this->assertTrue($this->cache->set(self::KEY, ['rate' => 1.0], new DateInterval('PT5M')));
        $this->assertSame(['rate' => 1.0], $this->cache->get(self::KEY));
    }

    public function testANonPositiveTtlExpiresTheEntry(): void
    {
        $this->cache->set(self::KEY, ['rate' => 1.0], 300);
        $this->cache->set(self::KEY, ['rate' => 2.0], 0);

        $this->assertNull($this->cache->get(self::KEY));
    }

    public function testMultipleOperations(): void
    {
        $this->assertTrue($this->cache->setMultiple(['a.key' => 1, 'b.key' => 2], 60));
        $this->assertSame(['a.key' => 1, 'b.key' => 2, 'c.key' => null], $this->cache->getMultiple(['a.key', 'b.key', 'c.key']));
        $this->assertTrue($this->cache->deleteMultiple(['a.key', 'b.key']));
        $this->assertNull($this->cache->get('a.key'));
    }
}
