<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Core\Account\Finding;
use Hardcastle\LedgerDirect\Presentation\AccountCheckNotices;
use PHPUnit\Framework\TestCase;

/**
 * Every finding the core can report gets a sentence of its own on the
 * settings page, with the asset, issuer and reserve it is about.
 */
class AccountCheckNoticesTest extends TestCase
{
    private const ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';

    public function testEveryFindingHasItsOwnSentence(): void
    {
        $codes = (new \ReflectionClass(Finding::class))->getConstants();
        $texts = [];

        foreach ($codes as $code) {
            $text = AccountCheckNotices::findingText(new Finding($code, 'RLUSD', true, ['issuer' => self::ISSUER, 'reserve' => '1']), 'XAH');
            $this->assertNotSame($code, $text, "no sentence for {$code}");
            $texts[$code] = $text;
        }

        $this->assertSame(count($texts), count(array_unique($texts)));
    }

    public function testASentenceNamesWhatItIsAbout(): void
    {
        $missing = AccountCheckNotices::findingText(new Finding(Finding::TRUSTLINE_MISSING, 'RLUSD', true, ['issuer' => self::ISSUER]), 'XRP');
        $this->assertStringContainsString('RLUSD', $missing);
        $this->assertStringContainsString(self::ISSUER, $missing);

        $inactive = AccountCheckNotices::findingText(new Finding(Finding::ACCOUNT_MISSING, null, false, ['reserve' => '1']), 'XAH');
        $this->assertStringContainsString('1 XAH', $inactive);
    }
}
