<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Cache;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 store for the core's exchange-rate cache, on WordPress transients.
 * With a persistent object cache (Redis, Memcached) that is memory; without
 * one it is a row in wp_options. Either way it is shared across requests,
 * which is the whole point: the next checkout render reuses the rate.
 *
 * **The method signatures are deliberately untyped in their parameters.**
 * WordPress loads every plugin into one process, and another plugin may
 * bundle psr/simple-cache 1.x (untyped methods) while the core requires
 * ^3.0 (fully typed). Which `Psr\SimpleCache\CacheInterface` wins depends on
 * autoloader order. A class typed for 3.0 is a fatal error under 1.0 and the
 * other way round. Omitting the parameter types satisfies both: PHP allows a
 * child to widen a parameter type, and declaring a return type where the
 * parent declares none is legal. Do not "tidy" the types back in.
 *
 * A broken cache must never take checkout down: nothing here throws.
 */
class TransientRateCache implements CacheInterface
{
    private const PREFIX = 'ledger_direct_';

    public function get($key, $default = null): mixed
    {
        $value = get_transient(self::transientName((string) $key));

        // A miss is `false`; the core never caches a boolean.
        return $value === false ? $default : $value;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $expiration = self::expirationFor($ttl);

        if ($expiration !== null && $expiration <= 0) {
            // PSR-16: a non-positive TTL means "already expired".
            return $this->delete($key);
        }

        return (bool) set_transient(self::transientName((string) $key), $value, $expiration ?? 0);
    }

    public function delete($key): bool
    {
        delete_transient(self::transientName((string) $key));

        return true;
    }

    public function clear(): bool
    {
        // Transients carry no namespace to clear by; the core's keys are
        // versioned instead (`ledger-direct.rate.v1.…`), which is how an
        // invalidation of every entry is done.
        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple($values, $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has($key): bool
    {
        return get_transient(self::transientName((string) $key)) !== false;
    }

    /**
     * Seconds until expiry, or null for "no expiry".
     */
    private static function expirationFor($ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return (int) $ttl;
    }

    /**
     * Transient names are limited to 172 characters and the core's keys
     * contain dots, which are fine, but anything else is normalised so an
     * odd key can never produce an invalid option name.
     */
    private static function transientName(string $key): string
    {
        $name = self::PREFIX . preg_replace('/[^A-Za-z0-9_.\-]/', '_', $key);

        return strlen($name) > 172 ? self::PREFIX . md5($key) : $name;
    }
}
