<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test\Request;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\Enum\Bank;
use BlinkPay\BlinkDebit\Enum\IdentifierType;
use BlinkPay\BlinkDebit\Enum\Period;
use BlinkPay\BlinkDebit\Enum\RetryStrategy;
use BlinkPay\BlinkDebit\Pcr;
use BlinkPay\BlinkDebit\Request\Amount;
use BlinkPay\BlinkDebit\Request\EnduringConsentRequest;
use BlinkPay\BlinkDebit\Request\FixedRecurringPaymentRequest;
use BlinkPay\BlinkDebit\Request\Flow;
use BlinkPay\BlinkDebit\Request\QuickPaymentRequest;
use BlinkPay\BlinkDebit\Request\SingleConsentRequest;
use PHPUnit\Framework\TestCase;

class RequestBuildersTest extends TestCase
{
    private const CONSENT_ID = '035d4ea4-4037-4110-9861-183eae1408b4';

    public function testGatewayFlowWithAndWithoutHint(): void
    {
        $this->assertSame(
            ['detail' => ['type' => 'gateway', 'redirect_uri' => 'https://shop.example/return']],
            Flow::gateway('https://shop.example/return')
        );
        $this->assertSame(
            ['detail' => [
                'type' => 'gateway',
                'redirect_uri' => 'http://localhost:8080/return',
                'flow_hint' => ['type' => 'redirect', 'bank' => 'BNZ'],
            ]],
            Flow::gateway('http://localhost:8080/return', Flow::redirectHint(Bank::BNZ))
        );
        $this->assertSame(
            ['type' => 'decoupled', 'bank' => 'PNZ', 'identifier_type' => 'mobile_number', 'identifier_value' => '+64-21-1234567'],
            Flow::decoupledHint(Bank::PNZ, IdentifierType::MOBILE_NUMBER, '+64-21-1234567')
        );
    }

    public function testRedirectAndDecoupledFlows(): void
    {
        $this->assertSame(
            ['detail' => ['type' => 'redirect', 'bank' => 'ANZ', 'redirect_uri' => 'https://shop.example/return']],
            Flow::redirect(Bank::ANZ, 'https://shop.example/return')
        );
        $this->assertSame(
            ['detail' => [
                'type' => 'decoupled',
                'bank' => 'Westpac',
                'identifier_type' => 'consent_id',
                'identifier_value' => self::CONSENT_ID,
                'callback_url' => 'https://shop.example/blink/callback',
            ]],
            Flow::decoupled(Bank::WESTPAC, IdentifierType::CONSENT_ID, self::CONSENT_ID, 'https://shop.example/blink/callback')
        );
        $this->assertArrayNotHasKey(
            'callback_url',
            Flow::decoupled(Bank::WESTPAC, IdentifierType::EMAIL, 'a@example.com')['detail']
        );
    }

    public function testFlowValidationFailsLocally(): void
    {
        $attempts = [
            static fn () => Flow::gateway('shop.example/return'),
            static fn () => Flow::redirect('', 'https://shop.example/return'),
            static fn () => Flow::decoupled(Bank::BNZ, IdentifierType::EMAIL, 'a@example.com', 'http://insecure.example/cb'),
            static fn () => Flow::decoupledHint(Bank::BNZ, IdentifierType::EMAIL, ''),
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

    public function testQuickPaymentAndSingleConsentShareTheBody(): void
    {
        $flow = Flow::gateway('https://shop.example/return');
        $expected = [
            'flow' => $flow,
            'amount' => ['currency' => 'NZD', 'total' => '12.50'],
            'pcr' => ['particulars' => 'Shop', 'reference' => '1005'],
            'hashed_customer_identifier' => 'abc',
        ];

        $this->assertSame($expected, QuickPaymentRequest::build($flow, '12.50', Pcr::build('Shop', '', '1005'), 'abc'));
        $this->assertSame($expected, SingleConsentRequest::build($flow, '12.50', Pcr::build('Shop', '', '1005'), 'abc'));
        $this->assertArrayNotHasKey(
            'hashed_customer_identifier',
            SingleConsentRequest::build($flow, '12.50', Pcr::build('Shop'), '')
        );
    }

    public function testEnduringConsentRequest(): void
    {
        $flow = Flow::redirect(Bank::BNZ, 'https://shop.example/return');

        $this->assertSame(
            [
                'flow' => $flow,
                'from_timestamp' => '2026-10-01T00:00:00+13:00',
                'period' => 'monthly',
                'maximum_amount_period' => ['currency' => 'NZD', 'total' => '50.00'],
            ],
            EnduringConsentRequest::build($flow, '2026-10-01T00:00:00+13:00', Period::MONTHLY, '50.00')
        );
        $this->assertSame(
            [
                'flow' => $flow,
                'from_timestamp' => '2026-10-01T00:00:00+13:00',
                'period' => 'weekly',
                'maximum_amount_period' => ['currency' => 'NZD', 'total' => '50.00'],
                'expiry_timestamp' => '2027-10-01T00:00:00+13:00',
                'maximum_amount_payment' => ['currency' => 'NZD', 'total' => '25.00'],
                'hashed_customer_identifier' => 'abc',
            ],
            EnduringConsentRequest::build(
                $flow,
                '2026-10-01T00:00:00+13:00',
                Period::WEEKLY,
                '50.00',
                '2027-10-01T00:00:00+13:00',
                '25.00',
                'abc'
            )
        );
    }

    public function testFixedRecurringPaymentRequest(): void
    {
        $this->assertSame(
            [
                'consent_id' => self::CONSENT_ID,
                'amount' => ['currency' => 'NZD', 'total' => '25.00'],
                'pcr' => ['particulars' => 'Gym'],
            ],
            FixedRecurringPaymentRequest::build(self::CONSENT_ID, '25.00', Pcr::build('Gym'))
        );
        $this->assertSame(
            [
                'consent_id' => self::CONSENT_ID,
                'amount' => ['currency' => 'NZD', 'total' => '25.00'],
                'pcr' => ['particulars' => 'Gym'],
                'start_date' => '2026-11-01',
                'retry_strategy' => 'same_day',
            ],
            FixedRecurringPaymentRequest::build(self::CONSENT_ID, '25.00', Pcr::build('Gym'), '2026-11-01', RetryStrategy::SAME_DAY)
        );
    }

    public function testAmountsAreValidated(): void
    {
        $this->assertSame(['currency' => 'NZD', 'total' => '0.01'], Amount::nzd('0.01'));

        $this->expectException(BlinkDebitApiException::class);
        FixedRecurringPaymentRequest::build(self::CONSENT_ID, '25', Pcr::build('Gym'));
    }
}
