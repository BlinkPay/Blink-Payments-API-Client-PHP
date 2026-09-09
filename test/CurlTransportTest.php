<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\CurlTransport;
use BlinkPay\BlinkDebit\Exception\TransportException;
use PHPUnit\Framework\TestCase;

/**
 * Drives the real ext-curl transport against a closed local port, so the
 * whole send path runs under each PHP version in the CI matrix and a curl
 * deprecation surfaces in the test output rather than in production logs.
 */
class CurlTransportTest extends TestCase
{
    public function testUnreachableHostRaisesTransportException(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The Blink Debit API could not be reached: ');

        // Port 9 (discard) is closed on a developer machine and on the CI runners.
        (new CurlTransport())->send('GET', 'http://127.0.0.1:9/meta', [], null, 2);
    }
}
