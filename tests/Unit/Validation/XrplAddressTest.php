<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Validation;

use Hardcastle\LedgerDirect\Validation\XrplAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard on the one setting that decides where customer money goes.
 */
final class XrplAddressTest extends TestCase
{
    #[DataProvider('validAddresses')]
    public function testAcceptsRealAddresses(string $address): void
    {
        self::assertTrue(XrplAddress::isValid($address));
    }

    #[DataProvider('invalidAddresses')]
    public function testRejects(string $address): void
    {
        self::assertFalse(XrplAddress::isValid($address));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validAddresses(): array
    {
        return [
            'a funded testnet account' => ['raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg'],
            'the RLUSD mainnet issuer' => ['rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De'],
            'the USDC testnet issuer' => ['rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAddresses(): array
    {
        return [
            'empty' => [''],
            'free text' => ['hello world'],
            'a Bitcoin address' => ['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'],
            'an Ethereum address' => ['0x71C7656EC7ab88b098defB751B7401B5f6d8976F'],
            'a destination tag pasted into the field' => ['1836426632'],
            'too short' => ['rABC'],
            'too long' => ['r' . str_repeat('A', 40)],
            'contains a zero' => ['r0gEbP1Lmig46X6BWjKLSQDHUrPzoy8tyA'],
            'contains a capital O' => ['rOgEbP1Lmig46X6BWjKLSQDHUrPzoy8tyA'],
            'contains a capital I' => ['rIgEbP1Lmig46X6BWjKLSQDHUrPzoy8tyA'],
            'contains a lowercase l' => ['rlgEbP1Lmig46X6BWjKLSQDHUrPzoy8tyA'],
            'leading whitespace' => [' raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg'],
            'does not start with r' => ['XaXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg'],
        ];
    }
}
