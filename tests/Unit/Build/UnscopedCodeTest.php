<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;

/**
 * The release build (scripts/deploy-wp-org.sh) prefixes the bundled
 * dependencies and the core's namespace with PHP-Scoper, in src/ only.
 * includes/ and the main file stay as they are, so a vendor or core name
 * there points at a class that does not exist in the released plugin. The
 * build checks that and refuses; this test says so before a release does
 * (1.7.0 named the core's Finding and ChainCatalog in the admin class).
 */
class UnscopedCodeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** The deploy script's rule: a vendor or core namespace, outside a comment. */
    private const VENDOR_NAME = '/(^|[^\\\\A-Za-z])(Psr|Nyholm|Brick)\\\\|Hardcastle\\\\LedgerDirect\\\\Core\\\\/';

    public function testIncludesAndTheMainFileNameNoVendorOrCoreClass(): void
    {
        $files = [self::ROOT . '/ledger-direct.php'];
        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/includes', \FilesystemIterator::SKIP_DOTS));
        foreach ($tree as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        $found = [];
        foreach ($files as $path) {
            foreach (file($path) ?: [] as $number => $line) {
                if (preg_match('#^\s*(\*|//|\#|/\*)#', $line) === 1) {
                    continue;
                }
                if (preg_match(self::VENDOR_NAME, $line) === 1) {
                    $found[] = substr($path, strlen(self::ROOT) + 1) . ':' . ($number + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertGreaterThan(5, count($files));
        $this->assertSame([], $found, "Names the release build does not prefix:\n" . implode("\n", $found));
    }
}
