<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\Uuid;
use BlinkPay\BlinkDebit\Validation;
use PHPUnit\Framework\TestCase;

class UuidTest extends TestCase
{
    public function testV4ProducesDistinctRfc4122Version4Values(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $uuid = Uuid::v4();
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $uuid
            );
            $this->assertSame($uuid, Validation::uuid($uuid, 'uuid'));
            $seen[$uuid] = true;
        }
        $this->assertCount(200, $seen);
    }
}
