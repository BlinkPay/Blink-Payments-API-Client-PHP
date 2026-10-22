<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\InMemoryTokenCache;

class BlinkDebitClientIntegrationTest extends IntegrationTestCase
{
    public function testClientBuiltFromTheEnvironmentReachesTheSandbox(): void
    {
        $client = BlinkDebitClient::fromEnvironment(new InMemoryTokenCache());

        $this->assertTrue($client->isConfigured());
        // Checked before any request: a BLINKPAY_SANDBOX=false in the
        // environment must not send this suite to production.
        $this->assertTrue($client->isSandbox(), 'BLINKPAY_SANDBOX must be unset or true for the integration suite.');

        $this->assertNotEmpty($client->getMeta());
    }
}
