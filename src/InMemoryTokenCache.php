<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Default per-process token cache. Suitable for CLI scripts and tests; web
 * integrations should provide a persistent implementation instead (see the
 * PSR-16 and PSR-6 adapters in the Psr namespace), otherwise every request
 * fetches a fresh token.
 */
class InMemoryTokenCache implements TokenCacheInterface
{
    /** @var array<string, array{value: string, expiresAt: ?int}> */
    private array $entries = [];

    public function get(string $key): ?string
    {
        if (!isset($this->entries[$key])) {
            return null;
        }

        $entry = $this->entries[$key];
        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= time()) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry['value'];
    }

    public function set(string $key, string $value, ?int $ttlSeconds): void
    {
        $this->entries[$key] = [
            'value' => $value,
            'expiresAt' => $ttlSeconds === null ? null : time() + $ttlSeconds,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }
}
