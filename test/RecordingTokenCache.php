<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\InMemoryTokenCache;
use BlinkPay\BlinkDebit\TokenCacheInterface;
use LogicException;

/**
 * Records the TTL of every write and otherwise behaves as the in-memory cache,
 * so tests can assert on the lifetime the client derived from expires_in
 * without waiting for a token to age out.
 */
class RecordingTokenCache implements TokenCacheInterface
{
    /** @var list<array{key: string, ttlSeconds: ?int}> */
    public array $writes = [];

    private InMemoryTokenCache $delegate;

    public function __construct()
    {
        $this->delegate = new InMemoryTokenCache();
    }

    public function get(string $key): ?string
    {
        return $this->delegate->get($key);
    }

    public function set(string $key, string $value, ?int $ttlSeconds): void
    {
        $this->writes[] = ['key' => $key, 'ttlSeconds' => $ttlSeconds];
        $this->delegate->set($key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        $this->delegate->delete($key);
    }

    /**
     * The most recent write whose key carries the given prefix. Returning the
     * whole record keeps a null TTL, which is meaningful, distinguishable from
     * no write at all.
     *
     * @param string $keyPrefix Cache key prefix, e.g. "blinkpay_token_".
     *
     * @return array{key: string, ttlSeconds: ?int}
     */
    public function lastWriteFor(string $keyPrefix): array
    {
        foreach (array_reverse($this->writes) as $write) {
            if (strpos($write['key'], $keyPrefix) === 0) {
                return $write;
            }
        }

        throw new LogicException('No cache write for a key starting with ' . $keyPrefix . '.');
    }
}
