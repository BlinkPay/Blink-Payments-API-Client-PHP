<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Token cache in APCu shared memory. Chosen automatically by the client when
 * the extension is loaded and enabled, so a plain-PHP or platform-module
 * integration under PHP-FPM or mod_php reuses one token across requests
 * instead of hitting the rate-limited token endpoint on every page load.
 *
 * APCu memory is per server process pool: tokens are not shared between
 * hosts, which is fine, since each host simply holds its own. Entries are
 * lost on restart and refetched on demand.
 */
class ApcuTokenCache implements TokenCacheInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'blinkpay.')
    {
        $this->prefix = $prefix;
    }

    /**
     * Whether APCu can be used in this process (extension loaded and enabled
     * for the current SAPI, including apc.enable_cli on the command line).
     */
    public static function isAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key): ?string
    {
        $value = apcu_fetch($this->prefix . $key, $success);

        return $success && is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): void
    {
        apcu_store($this->prefix . $key, $value, $ttlSeconds ?? 0);
    }

    public function delete(string $key): void
    {
        apcu_delete($this->prefix . $key);
    }
}
