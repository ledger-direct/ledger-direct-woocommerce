<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Woocommerce;

use Hardcastle\LedgerDirect\Woocommerce\LedgerDirectPaymentGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * is_token_payment_valid() is private and the gateway's constructor pulls in
 * the full WooCommerce/DI wiring, so we build a bare instance via reflection
 * and invoke the validation logic directly.
 */
class LedgerDirectPaymentGatewayTest extends TestCase
{
    private function invokeIsTokenPaymentValid(array $meta): bool
    {
        $reflection = new ReflectionClass(LedgerDirectPaymentGateway::class);
        $gateway = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('is_token_payment_valid');
        $method->setAccessible(true);

        return $method->invoke($gateway, $meta);
    }

    public function testValidWhenDeliveredAmountMatchesRequestedAmount(): void
    {
        $this->assertTrue($this->invokeIsTokenPaymentValid([
            'amount_requested' => '10.00',
            'delivered_amount' => '10.00',
        ]));
    }

    public function testInvalidWhenDeliveredAmountDiffersFromRequestedAmount(): void
    {
        $this->assertFalse($this->invokeIsTokenPaymentValid([
            'amount_requested' => '10.00',
            'delivered_amount' => '9.99',
        ]));
    }

    public function testInvalidWhenDeliveredAmountIsMissing(): void
    {
        $this->assertFalse($this->invokeIsTokenPaymentValid([
            'amount_requested' => '10.00',
        ]));
    }

    public function testInvalidWhenRequestedAmountIsMissing(): void
    {
        $this->assertFalse($this->invokeIsTokenPaymentValid([
            'delivered_amount' => '10.00',
        ]));
    }
}
