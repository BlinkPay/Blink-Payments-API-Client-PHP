<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

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
}
