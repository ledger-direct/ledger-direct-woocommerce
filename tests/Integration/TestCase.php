<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

/**
 * Base class for all integration tests
 */
abstract class TestCase extends \WP_UnitTestCase
{
    /**
     * @internal Workaround to allow the tests to run on PHPUnit 10.
     *
     * @link https://core.trac.wordpress.org/ticket/59486
     */
    public function expectDeprecated(): void
    {
        return;
    }
}