<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\RequestOptions;
use BlinkPay\BlinkDebit\Uuid;

class MetaIntegrationTest extends IntegrationTestCase
{
    public function testBankMetadataIsRetrieved(): void
    {
        $banks = self::client()->getMeta();

        $this->assertNotEmpty($banks);
        foreach ($banks as $bank) {
            $this->assertIsArray($bank);
            $this->assertArrayHasKey('name', $bank);
            $this->assertArrayHasKey('features', $bank);
        }
    }

    public function testBankMetadataIsRetrievedWithCallerSuppliedTracingIds(): void
    {
        $options = RequestOptions::create()
            ->withRequestId(Uuid::v4())
            ->withCorrelationId(Uuid::v4());

        $this->assertNotEmpty(self::client()->getMeta($options));
    }
}
