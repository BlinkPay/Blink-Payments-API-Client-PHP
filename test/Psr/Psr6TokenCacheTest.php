<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test\Psr;

use BlinkPay\BlinkDebit\Psr\Psr6TokenCache;
use PHPUnit\Framework\TestCase;

class Psr6TokenCacheTest extends TestCase
{
    protected function setUp(): void
    {
        // The in-memory doubles implement the PHP 8 (v3) PSR interfaces, which
        // a PHP 7.4 install resolves to v1 instead.
        if (PHP_VERSION_ID < 80000) {
            $this->markTestSkipped('PSR cache doubles require PHP 8.');
        }
    }

    public function testValuesArePrefixedAndExpiryForwarded(): void
    {
        $pool = new ArrayCacheItemPool();
        $cache = new Psr6TokenCache($pool);

        $cache->set('blinkpay_token_abc', 'tok', 3300);
        $cache->set('blinkpay_scopes_abc', 'create:payment', null);

        $this->assertSame('tok', $pool->items['blinkpay_blinkpay_token_abc']->value);
        $this->assertSame(3300, $pool->items['blinkpay_blinkpay_token_abc']->ttl);
        $this->assertNull($pool->items['blinkpay_blinkpay_scopes_abc']->ttl);
        $this->assertSame('tok', $cache->get('blinkpay_token_abc'));
    }

    public function testMissesReadAsNull(): void
    {
        $cache = new Psr6TokenCache(new ArrayCacheItemPool());

        $this->assertNull($cache->get('missing'));
    }

    public function testDeleteRemovesTheItem(): void
    {
        $pool = new ArrayCacheItemPool();
        $cache = new Psr6TokenCache($pool);
        $cache->set('k', 'v', 10);

        $cache->delete('k');

        $this->assertNull($cache->get('k'));
        $this->assertSame([], $pool->items);
    }
}
