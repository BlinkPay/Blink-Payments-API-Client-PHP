<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\InMemoryTokenCache;
use PHPUnit\Framework\TestCase;

class InMemoryTokenCacheTest extends TestCase
{
    public function testStoresAndReturnsValues(): void
    {
        $cache = new InMemoryTokenCache();
        $cache->set('key', 'value', 60);

        $this->assertSame('value', $cache->get('key'));
    }

    public function testExpiredValuesAreNotReturned(): void
    {
        $cache = new InMemoryTokenCache();
        $cache->set('key', 'value', -1);

        $this->assertNull($cache->get('key'));
    }

    public function testNullTtlNeverExpires(): void
    {
        $cache = new InMemoryTokenCache();
        $cache->set('key', 'value', null);

        $this->assertSame('value', $cache->get('key'));
    }

    public function testDeleteRemovesTheValue(): void
    {
        $cache = new InMemoryTokenCache();
        $cache->set('key', 'value', 60);
        $cache->delete('key');

        $this->assertNull($cache->get('key'));
    }
}
