<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_a1b2c3d4e5f6';
    private const BODY = '{"event_type":"urn:nz:co:blinkpay:debit:events:fixed-recurring-payment-completed","event_id":"1"}';
    private const NOW = 1739545800;

    private function header(int $timestamp = self::NOW, string $body = self::BODY, string $secret = self::SECRET): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, WebhookSignature::sign($body, $secret, $timestamp));
    }

    public function testSignatureMatchesTheDocumentedScheme(): void
    {
        $expected = hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET);

        $this->assertSame($expected, WebhookSignature::sign(self::BODY, self::SECRET, self::NOW));
    }

    public function testValidSignatureIsAccepted(): void
    {
        $this->assertTrue(WebhookSignature::verify(self::BODY, $this->header(), self::SECRET, 300, self::NOW));
    }

    public function testUppercaseHexAndSurroundingWhitespaceAreTolerated(): void
    {
        $header = ' t=' . self::NOW . ' , v1=' . strtoupper(WebhookSignature::sign(self::BODY, self::SECRET, self::NOW));

        $this->assertTrue(WebhookSignature::verify(self::BODY, $header, self::SECRET, 300, self::NOW));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $this->assertFalse(WebhookSignature::verify(self::BODY . ' ', $this->header(), self::SECRET, 300, self::NOW));
    }

    public function testWrongSecretIsRejected(): void
    {
        $this->assertFalse(WebhookSignature::verify(self::BODY, $this->header(), 'whsec_other', 300, self::NOW));
    }

    public function testStaleTimestampIsRejectedInsideToleranceWindowOnly(): void
    {
        $header = $this->header(self::NOW - 301);

        $this->assertFalse(WebhookSignature::verify(self::BODY, $header, self::SECRET, 300, self::NOW));
        $this->assertTrue(WebhookSignature::verify(self::BODY, $header, self::SECRET, 0, self::NOW));
    }

    public function testMalformedHeadersAreRejected(): void
    {
        foreach (['', 'v1=abc', 't=123', 't=abc,v1=' . str_repeat('0', 64), 't=123,v1=nothex'] as $header) {
            $this->assertFalse(WebhookSignature::verify(self::BODY, $header, self::SECRET, 0, self::NOW), $header);
        }
    }

    public function testEmptySecretNeverVerifies(): void
    {
        $this->assertFalse(WebhookSignature::verify(self::BODY, $this->header(self::NOW, self::BODY, ''), '', 0, self::NOW));
    }
}
