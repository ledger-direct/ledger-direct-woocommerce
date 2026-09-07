<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Log;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-3 sink for the core, writing into WooCommerce's log
 * (WooCommerce → Status → Logs, source "ledger-direct"). The core logs every
 * swallowed oracle/sync failure through this port, so a merchant seeing
 * "no price available" has somewhere to find out which oracle broke.
 *
 * **Parameters are deliberately untyped** and `AbstractLogger` is not
 * extended: psr/log 1.x and 3.x differ in their signatures, another plugin
 * in the same WordPress process may bundle either, and a class written
 * against one is a fatal error under the other. Untyped parameters plus
 * explicit `void` returns are valid against both.
 */
class WcLoggerAdapter implements LoggerInterface
{
    public const SOURCE = 'ledger-direct';

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $context['source'] = self::SOURCE;

        wc_get_logger()->log((string) $level, (string) $message, $context);
    }
}
