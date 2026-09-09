<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\RequestOptions;
use PHPUnit\Framework\TestCase;

class RequestOptionsTest extends TestCase
{
    private const UUID_A = '9f4cb72c-7563-4be5-b76f-1a4197fbad13';
    private const UUID_B = 'f1e62d03-af1a-4c7b-aadb-d916adeb8d9d';

    public function testEmptyOptionsProduceNoHeaders(): void
    {
        $this->assertSame([], RequestOptions::create()->toHeaders());
    }

    public function testValuesAreTrimmedAndEmittedInSpecOrder(): void
    {
        $headers = RequestOptions::create()
            ->withCustomerUserAgent(' Mozilla/5.0 ')
            ->withCustomerIp('  203.0.113.9 ')
            ->withCorrelationId(self::UUID_B)
            ->withRequestId(self::UUID_A)
            ->toHeaders();

        $this->assertSame([
            'request-id: ' . self::UUID_A,
            'x-correlation-id: ' . self::UUID_B,
            'x-customer-ip: 203.0.113.9',
            'x-customer-user-agent: Mozilla/5.0',
        ], $headers);
    }

    public function testCustomerContextCanBeExcluded(): void
    {
        $headers = RequestOptions::create()
            ->withRequestId(self::UUID_A)
            ->withCustomerIp('2001:db8::1')
            ->withCustomerUserAgent('Mozilla/5.0')
            ->toHeaders(false);

        $this->assertSame(['request-id: ' . self::UUID_A], $headers);
    }

    public function testWithMethodsAreImmutable(): void
    {
        $base = RequestOptions::create()->withCorrelationId(self::UUID_B);
        $derived = $base->withRequestId(self::UUID_A);

        $this->assertSame(['x-correlation-id: ' . self::UUID_B], $base->toHeaders());
        $this->assertSame(['request-id: ' . self::UUID_A, 'x-correlation-id: ' . self::UUID_B], $derived->toHeaders());
    }

    public function testHeaderInjectionAttemptsAreRejected(): void
    {
        $attempts = [
            static fn () => RequestOptions::create()->withCustomerUserAgent("Mozilla/5.0\r\nX-Injected: yes"),
            static fn () => RequestOptions::create()->withCustomerUserAgent("Mozilla\x00/5.0"),
            static fn () => RequestOptions::create()->withCustomerUserAgent(''),
            static fn () => RequestOptions::create()->withCustomerIp("203.0.113.9\r\nX-Injected: yes"),
            static fn () => RequestOptions::create()->withCustomerIp('unknown'),
            static fn () => RequestOptions::create()->withRequestId(self::UUID_A . "\r\nX-Injected: yes"),
            static fn () => RequestOptions::create()->withRequestId('req-1'),
            static fn () => RequestOptions::create()->withCorrelationId('corr-1'),
        ];

        foreach ($attempts as $index => $attempt) {
            try {
                $attempt();
                $this->fail('Expected attempt #' . $index . ' to be rejected.');
            } catch (BlinkDebitApiException $exception) {
                $this->assertStringStartsWith('Invalid ', $exception->getMessage());
            }
        }
    }

    public function testIpv4AndIpv6AddressesAreAccepted(): void
    {
        $this->assertSame(
            ['x-customer-ip: 2001:db8::1'],
            RequestOptions::create()->withCustomerIp('2001:db8::1')->toHeaders()
        );
        $this->assertSame(
            ['x-customer-ip: 203.0.113.9'],
            RequestOptions::create()->withCustomerIp('203.0.113.9')->toHeaders()
        );
    }
}
