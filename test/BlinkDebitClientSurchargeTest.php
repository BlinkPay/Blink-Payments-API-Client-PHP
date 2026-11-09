<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\InMemoryTokenCache;
use BlinkPay\BlinkDebit\Pcr;
use BlinkPay\BlinkDebit\Request\Flow;
use PHPUnit\Framework\TestCase;

/**
 * Responses carry read-only surcharge fields that must reach the caller
 * unchanged; request bodies must never send them back.
 */
class BlinkDebitClientSurchargeTest extends TestCase
{
    private const CONSENT_ID = '11111111-1111-4111-8111-000000000001';
    private const QUICK_PAYMENT_ID = '11111111-1111-4111-8111-000000000005';
    private const PAYMENT_ID = '11111111-1111-4111-8111-000000000008';
    private const IDEM = 'ddc0315c-fba7-4926-ba24-b7700ed389e7';
    private const RETURN_URL = 'https://shop.example/return';

    private const PCR = '"pcr":{"particulars":"particulars","code":"code","reference":"reference"}';
    private const GATEWAY_FLOW = '"flow":{"detail":{"type":"gateway","redirect_uri":"https://shop.example/return"}}';
    private const REDIRECT_FLOW = '"flow":{"detail":{"type":"redirect","bank":"PNZ",'
        . '"redirect_uri":"https://shop.example/return"}}';

    private const REDIRECT_SINGLE_SURCHARGED = '{"consent_id":"11111111-1111-4111-8111-000000000001",'
        . '"status":"AwaitingAuthorisation","detail":{"type":"single",' . self::REDIRECT_FLOW . ',' . self::PCR . ','
        . '"amount":{"currency":"NZD","total":"100.00","surcharge":"0.68","total_charge":"100.68"}},"payments":[]}';

    private const GATEWAY_SINGLE_OPTIONS = '{"consent_id":"11111111-1111-4111-8111-000000000001",'
        . '"status":"GatewayAwaitingSubmission","detail":{"type":"single",' . self::GATEWAY_FLOW . ',' . self::PCR . ','
        . '"amount":{"currency":"NZD","total":"100.00"}},"payments":[],'
        . '"surcharge_options":{"bank":{"surcharge":"0.68","total_charge":"100.68"},'
        . '"card":{"surcharge":"1.49","total_charge":"101.49"}}}';

    private const GATEWAY_QUICK_OPTIONS = '{"quick_payment_id":"11111111-1111-4111-8111-000000000005",'
        . '"consent":{"consent_id":"11111111-1111-4111-8111-000000000005","status":"GatewayAwaitingSubmission",'
        . '"detail":{"type":"single",' . self::GATEWAY_FLOW . ',' . self::PCR . ','
        . '"amount":{"currency":"NZD","total":"100.00"}},"payments":[],'
        . '"surcharge_options":{"bank":{"surcharge":"0.68","total_charge":"100.68"}}}}';

    private const GATEWAY_ENDURING_BOTH_MAXIMA = '{"consent_id":"11111111-1111-4111-8111-000000000001",'
        . '"status":"GatewayAwaitingSubmission","detail":{"type":"enduring",' . self::GATEWAY_FLOW . ','
        . '"from_timestamp":"2026-11-10T09:00:00+13:00","period":"monthly",'
        . '"maximum_amount_period":{"currency":"NZD","total":"400.00","surcharge":"2.72","total_charge":"402.72"},'
        . '"maximum_amount_payment":{"currency":"NZD","total":"100.00","surcharge":"0.68","total_charge":"100.68"}},'
        . '"payments":[],'
        . '"surcharge_options":{"bank":{"maximum_amount_payment":{"surcharge":"0.68","total_charge":"100.68"},'
        . '"maximum_amount_period":{"surcharge":"2.72","total_charge":"402.72"}}}}';

    private const GATEWAY_ENDURING_PERIOD_ONLY = '{"consent_id":"11111111-1111-4111-8111-000000000001",'
        . '"status":"GatewayAwaitingSubmission","detail":{"type":"enduring",' . self::GATEWAY_FLOW . ','
        . '"from_timestamp":"2026-11-10T09:00:00+13:00","period":"monthly",'
        . '"maximum_amount_period":{"currency":"NZD","total":"400.00","surcharge":"2.72","total_charge":"402.72"}},'
        . '"payments":[],'
        . '"surcharge_options":{"bank":{"maximum_amount_period":{"surcharge":"2.72","total_charge":"402.72"}}}}';

    private const PAYMENT_SURCHARGED = '{"payment_id":"11111111-1111-4111-8111-000000000008","type":"single",'
        . '"status":"AcceptedSettlementCompleted","accepted_reason":"source_bank_payment_sent",'
        . '"detail":{"consent_id":"11111111-1111-4111-8111-000000000001",' . self::PCR . ','
        . '"amount":{"currency":"NZD","total":"100.00","surcharge":"0.68","total_charge":"100.68"}},"refunds":[]}';

    private const UNSURCHARGED_SINGLE = '{"consent_id":"11111111-1111-4111-8111-000000000001",'
        . '"status":"AwaitingAuthorisation","detail":{"type":"single",' . self::REDIRECT_FLOW . ',' . self::PCR . ','
        . '"amount":{"currency":"NZD","total":"100.00"}},"payments":[]}';

    private const EXPLICIT_NULL_SINGLE = '{"consent_id":"11111111-1111-4111-8111-000000000001",'
        . '"status":"AwaitingAuthorisation","detail":{"type":"single",' . self::REDIRECT_FLOW . ',' . self::PCR . ','
        . '"amount":{"currency":"NZD","total":"100.00","surcharge":null,"total_charge":null}},"payments":[],'
        . '"surcharge_options":null}';

    private const UNSURCHARGED_AMOUNT = ['currency' => 'NZD', 'total' => '100.00'];

    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    private function client(): BlinkDebitClient
    {
        $client = new BlinkDebitClient(
            'client-id',
            'client-secret',
            true,
            new InMemoryTokenCache(),
            $this->transport
        );
        $client->setSleep(static function (int $milliseconds): void {
        });
        $this->transport->queue(200, ['access_token' => 'token-1', 'expires_in' => 3600]);

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        return self::decode((string) $this->transport->lastRequest()['body']);
    }

    // ------------------------------------------------------------------
    // Responses: the fields reach the caller unchanged
    // ------------------------------------------------------------------

    public function testRedirectSingleConsentReturnsTheAmountSurcharge(): void
    {
        $client = $this->client();
        $this->transport->queueRaw(200, self::REDIRECT_SINGLE_SURCHARGED);

        $consent = $client->getSingleConsent(self::CONSENT_ID);

        $this->assertSame(
            ['currency' => 'NZD', 'total' => '100.00', 'surcharge' => '0.68', 'total_charge' => '100.68'],
            $consent['detail']['amount']
        );
        $this->assertSame(self::decode(self::REDIRECT_SINGLE_SURCHARGED), $consent);
    }

    public function testGatewaySingleConsentReturnsBankAndCardSurchargeOptions(): void
    {
        $client = $this->client();
        $this->transport->queueRaw(200, self::GATEWAY_SINGLE_OPTIONS);

        $consent = $client->getSingleConsent(self::CONSENT_ID);

        $this->assertSame(
            [
                'bank' => ['surcharge' => '0.68', 'total_charge' => '100.68'],
                'card' => ['surcharge' => '1.49', 'total_charge' => '101.49'],
            ],
            $consent['surcharge_options']
        );
        $this->assertSame(self::UNSURCHARGED_AMOUNT, $consent['detail']['amount']);
    }

    public function testQuickPaymentReturnsTheConsentSurchargeOptions(): void
    {
        $client = $this->client();
        $this->transport->queueRaw(200, self::GATEWAY_QUICK_OPTIONS);

        $quickPayment = $client->getQuickPayment(self::QUICK_PAYMENT_ID);

        $this->assertSame(
            ['bank' => ['surcharge' => '0.68', 'total_charge' => '100.68']],
            $quickPayment['consent']['surcharge_options']
        );
        $this->assertSame(self::decode(self::GATEWAY_QUICK_OPTIONS), $quickPayment);
    }

    public function testEnduringConsentReturnsRaisedMaximaAndTheirSurchargeOptions(): void
    {
        $client = $this->client();
        $this->transport->queueRaw(200, self::GATEWAY_ENDURING_BOTH_MAXIMA);

        $consent = $client->getEnduringConsent(self::CONSENT_ID);

        $this->assertSame(
            ['currency' => 'NZD', 'total' => '400.00', 'surcharge' => '2.72', 'total_charge' => '402.72'],
            $consent['detail']['maximum_amount_period']
        );
        $this->assertSame(
            ['currency' => 'NZD', 'total' => '100.00', 'surcharge' => '0.68', 'total_charge' => '100.68'],
            $consent['detail']['maximum_amount_payment']
        );
        $this->assertSame(
            [
                'bank' => [
                    'maximum_amount_payment' => ['surcharge' => '0.68', 'total_charge' => '100.68'],
                    'maximum_amount_period' => ['surcharge' => '2.72', 'total_charge' => '402.72'],
                ],
            ],
            $consent['surcharge_options']
        );
    }

    public function testEnduringConsentWithoutAPaymentMaximumReturnsThePeriodOptionOnly(): void
    {
        $client = $this->client();
        $this->transport->queueRaw(200, self::GATEWAY_ENDURING_PERIOD_ONLY);

        $consent = $client->getEnduringConsent(self::CONSENT_ID);

        $this->assertArrayNotHasKey('maximum_amount_payment', $consent['detail']);
        $this->assertSame(
            ['bank' => ['maximum_amount_period' => ['surcharge' => '2.72', 'total_charge' => '402.72']]],
            $consent['surcharge_options']
        );
        $this->assertSame(self::decode(self::GATEWAY_ENDURING_PERIOD_ONLY), $consent);
    }

    public function testPaymentReturnsTheAmountSurcharge(): void
    {
        $client = $this->client();
        $this->transport->queueRaw(200, self::PAYMENT_SURCHARGED);

        $payment = $client->getPayment(self::PAYMENT_ID);

        $this->assertSame(
            ['currency' => 'NZD', 'total' => '100.00', 'surcharge' => '0.68', 'total_charge' => '100.68'],
            $payment['detail']['amount']
        );
    }

    public function testUnsurchargedConsentHasNoSurchargeFields(): void
    {
        $client = $this->client();
        $this->transport->queueRaw(200, self::UNSURCHARGED_SINGLE);

        $consent = $client->getSingleConsent(self::CONSENT_ID);

        $this->assertSame(self::UNSURCHARGED_AMOUNT, $consent['detail']['amount']);
        $this->assertArrayNotHasKey('surcharge_options', $consent);
        $this->assertNull($consent['detail']['amount']['surcharge'] ?? null);
    }

    public function testExplicitNullSurchargeFieldsAreReturnedAsNull(): void
    {
        $client = $this->client();
        $this->transport->queueRaw(200, self::EXPLICIT_NULL_SINGLE);

        $consent = $client->getSingleConsent(self::CONSENT_ID);

        $amount = $consent['detail']['amount'];
        $this->assertArrayHasKey('surcharge', $amount);
        $this->assertNull($amount['surcharge']);
        $this->assertArrayHasKey('total_charge', $amount);
        $this->assertNull($amount['total_charge']);
        $this->assertArrayHasKey('surcharge_options', $consent);
        $this->assertNull($consent['surcharge_options']);
        $this->assertSame('100.00', $amount['total']);
    }

    // ------------------------------------------------------------------
    // Requests: an amount copied from a response is sent without them
    // ------------------------------------------------------------------

    public function testCreateSingleConsentStripsSurchargeFieldsFromAReusedResponse(): void
    {
        $client = $this->client();
        $this->transport->queue(201, ['consent_id' => self::CONSENT_ID]);
        $previous = self::decode(self::GATEWAY_SINGLE_OPTIONS);
        $payload = [
            'flow' => Flow::gateway(self::RETURN_URL),
            'amount' => self::decode(self::REDIRECT_SINGLE_SURCHARGED)['detail']['amount'],
            'pcr' => Pcr::build('particulars'),
            'surcharge_options' => $previous['surcharge_options'],
        ];
        $original = $payload;

        $client->createSingleConsent($payload, self::IDEM);

        $body = $this->sentBody();
        $this->assertStringEndsWith('/payments/v1/single-consents', $this->transport->lastRequest()['url']);
        $this->assertSame(self::UNSURCHARGED_AMOUNT, $body['amount']);
        $this->assertArrayNotHasKey('surcharge_options', $body);
        $this->assertSame($payload['flow'], $body['flow']);
        $this->assertSame($original, $payload);
    }

    public function testCreateQuickPaymentStripsSurchargeFieldsFromAReusedResponse(): void
    {
        $client = $this->client();
        $this->transport->queue(201, ['quick_payment_id' => self::QUICK_PAYMENT_ID]);
        $consent = self::decode(self::GATEWAY_QUICK_OPTIONS)['consent'];
        $consent['detail']['amount'] = self::decode(self::PAYMENT_SURCHARGED)['detail']['amount'];
        $payload = [
            'flow' => Flow::gateway(self::RETURN_URL),
            'amount' => $consent['detail']['amount'],
            'pcr' => Pcr::build('particulars'),
            'surcharge_options' => $consent['surcharge_options'],
        ];
        $original = $payload;

        $client->createQuickPayment($payload, self::IDEM);

        $body = $this->sentBody();
        $this->assertStringEndsWith('/payments/v1/quick-payments', $this->transport->lastRequest()['url']);
        $this->assertSame(self::UNSURCHARGED_AMOUNT, $body['amount']);
        $this->assertArrayNotHasKey('surcharge_options', $body);
        $this->assertSame($original, $payload);
    }

    public function testCreateEnduringConsentStripsSurchargeFieldsFromBothMaxima(): void
    {
        $client = $this->client();
        $this->transport->queue(201, ['consent_id' => self::CONSENT_ID]);
        $previous = self::decode(self::GATEWAY_ENDURING_BOTH_MAXIMA);
        $payload = [
            'flow' => Flow::gateway(self::RETURN_URL),
            'from_timestamp' => '2026-11-10T09:00:00+13:00',
            'period' => 'monthly',
            'maximum_amount_period' => $previous['detail']['maximum_amount_period'],
            'maximum_amount_payment' => $previous['detail']['maximum_amount_payment'],
            'surcharge_options' => $previous['surcharge_options'],
        ];
        $original = $payload;

        $client->createEnduringConsent($payload, self::IDEM);

        $body = $this->sentBody();
        $this->assertStringEndsWith('/payments/v1/enduring-consents', $this->transport->lastRequest()['url']);
        $this->assertSame(['currency' => 'NZD', 'total' => '400.00'], $body['maximum_amount_period']);
        $this->assertSame(self::UNSURCHARGED_AMOUNT, $body['maximum_amount_payment']);
        $this->assertArrayNotHasKey('surcharge_options', $body);
        $this->assertSame('monthly', $body['period']);
        $this->assertSame($original, $payload);
    }

    public function testCreateFixedRecurringPaymentStripsSurchargeFieldsFromTheAmount(): void
    {
        $client = $this->client();
        $this->transport->queue(201, ['fixed_recurring_payment_id' => self::PAYMENT_ID]);
        $payload = [
            'consent_id' => self::CONSENT_ID,
            'amount' => self::decode(self::PAYMENT_SURCHARGED)['detail']['amount'],
            'pcr' => Pcr::build('particulars'),
        ];
        $original = $payload;

        $client->createFixedRecurringPayment($payload, self::IDEM);

        $body = $this->sentBody();
        $this->assertStringEndsWith('/payments/v1/fixed-recurring-payments', $this->transport->lastRequest()['url']);
        $this->assertSame(self::UNSURCHARGED_AMOUNT, $body['amount']);
        $this->assertSame(self::CONSENT_ID, $body['consent_id']);
        $this->assertSame($original, $payload);
    }

    public function testCreatePaymentStripsSurchargeFieldsFromTheAmount(): void
    {
        $client = $this->client();
        $this->transport->queue(201, ['payment_id' => self::PAYMENT_ID]);
        $payload = [
            'consent_id' => self::CONSENT_ID,
            'amount' => self::decode(self::PAYMENT_SURCHARGED)['detail']['amount'],
            'pcr' => Pcr::build('particulars'),
        ];
        $original = $payload;

        $client->createPayment($payload, self::IDEM);

        $body = $this->sentBody();
        $this->assertStringEndsWith('/payments/v1/payments', $this->transport->lastRequest()['url']);
        $this->assertSame(self::UNSURCHARGED_AMOUNT, $body['amount']);
        $this->assertSame($original, $payload);
    }

    public function testCreateRefundStripsSurchargeFieldsFromTheAmount(): void
    {
        $client = $this->client();
        $this->transport->queue(201, ['refund_id' => self::PAYMENT_ID]);
        $payload = [
            'type' => 'partial_refund',
            'payment_id' => self::PAYMENT_ID,
            'pcr' => Pcr::build('particulars'),
            'amount' => self::decode(self::PAYMENT_SURCHARGED)['detail']['amount'],
        ];
        $original = $payload;

        $client->createRefund($payload, self::IDEM);

        $body = $this->sentBody();
        $this->assertStringEndsWith('/payments/v1/refunds', $this->transport->lastRequest()['url']);
        $this->assertSame(self::UNSURCHARGED_AMOUNT, $body['amount']);
        $this->assertSame('partial_refund', $body['type']);
        $this->assertSame($original, $payload);
    }

    public function testPartialRefundOfAResponseTotalSendsOnlyCurrencyAndTotal(): void
    {
        $client = $this->client();
        $this->transport->queue(201, ['refund_id' => self::PAYMENT_ID]);
        $amount = self::decode(self::PAYMENT_SURCHARGED)['detail']['amount'];

        $client->createPartialRefund(self::PAYMENT_ID, Pcr::build('particulars'), $amount['total'], self::IDEM);

        $this->assertSame(self::UNSURCHARGED_AMOUNT, $this->sentBody()['amount']);
    }

    public function testOnlyAmountSurchargeFieldsAreStripped(): void
    {
        $client = $this->client();
        $this->transport->queue(201, ['consent_id' => self::CONSENT_ID]);
        $flow = Flow::gateway(self::RETURN_URL);
        $flow['detail']['surcharge'] = 'kept';
        $payload = [
            'flow' => $flow,
            'amount' => self::decode(self::REDIRECT_SINGLE_SURCHARGED)['detail']['amount'],
            'pcr' => ['particulars' => 'particulars', 'surcharge' => 'kept', 'total_charge' => 'kept'],
        ];

        $client->createSingleConsent($payload, self::IDEM);

        $body = $this->sentBody();
        $this->assertSame(self::UNSURCHARGED_AMOUNT, $body['amount']);
        $this->assertSame('kept', $body['flow']['detail']['surcharge']);
        $this->assertSame(['particulars' => 'particulars', 'surcharge' => 'kept', 'total_charge' => 'kept'], $body['pcr']);
    }
}
