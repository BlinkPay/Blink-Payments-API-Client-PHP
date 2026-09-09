<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\InMemoryTokenCache;
use BlinkPay\BlinkDebit\Pcr;
use BlinkPay\BlinkDebit\RequestOptions;
use PHPUnit\Framework\TestCase;

class BlinkDebitClientTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    private function client(bool $sandbox = true): BlinkDebitClient
    {
        return new BlinkDebitClient('client-id', 'client-secret', $sandbox, new InMemoryTokenCache(), $this->transport);
    }

    private function queueToken(string $token = 'token-1', string $scope = 'create:quick_payment view:quick_payment'): void
    {
        $this->transport->queue(200, [
            'access_token' => $token,
            'expires_in' => 3600,
            'scope' => $scope,
        ]);
    }

    public function testTokenRequestIsFormEncodedAgainstTheSandboxHost(): void
    {
        $this->queueToken();

        $token = $this->client()->getAccessToken();

        $this->assertSame('token-1', $token);
        $request = $this->transport->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/oauth2/token', $request['url']);
        $this->assertContains('Content-Type: application/x-www-form-urlencoded', $request['headers']);
        $this->assertSame(
            'grant_type=client_credentials&client_id=client-id&client_secret=client-secret',
            $request['body']
        );
    }

    public function testProductionModeTargetsTheProductionHost(): void
    {
        $this->queueToken();

        $this->client(false)->getAccessToken();

        $this->assertSame('https://debit.blinkpay.co.nz/oauth2/token', $this->transport->lastRequest()['url']);
    }

    public function testTokenIsCachedAcrossRequests(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(200, ['quick_payment_id' => 'a', 'redirect_uri' => 'https://pay']);
        $this->transport->queue(200, ['quick_payment_id' => 'b', 'redirect_uri' => 'https://pay']);

        $client->createQuickPayment(['amount' => []], self::IDEM);
        $client->createQuickPayment(['amount' => []], self::IDEM_2);

        $tokenRequests = array_filter(
            $this->transport->requests,
            static function (array $request): bool {
                return strpos($request['url'], '/oauth2/token') !== false;
            }
        );
        $this->assertCount(1, $tokenRequests);
    }

    public function testUnauthorisedResponseForcesOneTokenRefreshAndRetry(): void
    {
        $client = $this->client();
        $this->queueToken('stale-token');
        $this->transport->queue(401, ['message' => 'token expired']);
        $this->queueToken('fresh-token');
        $this->transport->queue(200, ['quick_payment_id' => 'qp-1', 'redirect_uri' => 'https://pay']);

        $response = $client->createQuickPayment(['amount' => []], self::IDEM);

        $this->assertSame('qp-1', $response['quick_payment_id']);
        $this->assertCount(4, $this->transport->requests);
        $this->assertContains('Authorization: Bearer fresh-token', $this->transport->lastRequest()['headers']);
    }

    public function testCreateGatewayQuickPaymentBuildsTheCanonicalPayload(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['quick_payment_id' => 'qp-1', 'redirect_uri' => 'https://pay']);

        $client->createGatewayQuickPayment(
            '12.50',
            'https://shop.example/return',
            Pcr::build('My Shop', '1005'),
            self::IDEM,
            hash('sha256', 'customer@example.com')
        );

        $request = $this->transport->lastRequest();
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/quick-payments', $request['url']);
        $this->assertContains('idempotency-key: ' . self::IDEM, $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertSame(
            [
                'flow' => [
                    'detail' => [
                        'type' => 'gateway',
                        'redirect_uri' => 'https://shop.example/return',
                    ],
                ],
                'amount' => [
                    'currency' => 'NZD',
                    'total' => '12.50',
                ],
                'pcr' => [
                    'particulars' => 'My Shop',
                    'reference' => '1005',
                ],
                'hashed_customer_identifier' => hash('sha256', 'customer@example.com'),
            ],
            json_decode((string) $request['body'], true)
        );
    }

    public function testHashedCustomerIdentifierIsOmittedWhenNull(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['quick_payment_id' => 'qp-1', 'redirect_uri' => 'https://pay']);

        $client->createGatewayQuickPayment('5.00', 'https://shop.example/return', Pcr::build('Shop'), self::IDEM);

        $payload = json_decode((string) $this->transport->lastRequest()['body'], true);
        $this->assertArrayNotHasKey('hashed_customer_identifier', $payload);
    }

    public function testFullRefundPayload(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['refund_id' => 'rf-1']);

        $client->createFullRefund(self::PAYMENT_ID, Pcr::build('My Shop', '1005'));

        $request = $this->transport->lastRequest();
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/refunds', $request['url']);
        $this->assertSame(
            [
                'type' => 'full_refund',
                'payment_id' => self::PAYMENT_ID,
                'pcr' => ['particulars' => 'My Shop', 'reference' => '1005'],
            ],
            json_decode((string) $request['body'], true)
        );
    }

    public function testPartialRefundPayloadCarriesTheAmount(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['refund_id' => 'rf-1']);

        $client->createPartialRefund(self::PAYMENT_ID, Pcr::build('My Shop'), '4.20');

        $this->assertSame(
            [
                'type' => 'partial_refund',
                'payment_id' => self::PAYMENT_ID,
                'pcr' => ['particulars' => 'My Shop'],
                'amount' => ['currency' => 'NZD', 'total' => '4.20'],
            ],
            json_decode((string) $this->transport->lastRequest()['body'], true)
        );
    }

    public function testAccountNumberRefundPayloadCarriesNoPcrOrAmount(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['refund_id' => 'rf-1']);

        $client->createAccountNumberRefund(self::PAYMENT_ID);

        $this->assertSame(
            [
                'type' => 'account_number',
                'payment_id' => self::PAYMENT_ID,
            ],
            json_decode((string) $this->transport->lastRequest()['body'], true)
        );
    }

    public function testApiErrorsRaiseAnExceptionWithStatusAndBody(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(422, ['message' => 'Amount exceeds the payment total', 'code' => 'refund_too_large']);

        try {
            $client->createAccountNumberRefund(self::PAYMENT_ID);
            $this->fail('Expected a BlinkDebitApiException.');
        } catch (BlinkDebitApiException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('Amount exceeds the payment total', $exception->getMessage());
            $this->assertStringContainsString('refund_too_large', $exception->getMessage());
            $this->assertSame('refund_too_large', $exception->getResponseBody()['code']);
        }
    }

    public function testInvalidQuickPaymentIdIsRejectedWithoutAnyRequest(): void
    {
        $this->expectException(BlinkDebitApiException::class);

        try {
            $this->client()->getQuickPayment('../not-a-uuid');
        } finally {
            $this->assertSame([], $this->transport->requests);
        }
    }

    public function testMissingCredentialsAreRejectedWithoutAnyRequest(): void
    {
        $client = new BlinkDebitClient('', '', true, new InMemoryTokenCache(), $this->transport);

        $this->assertFalse($client->isConfigured());
        $this->expectException(BlinkDebitApiException::class);
        $client->getAccessToken();
    }

    public function testRefundScopesAreUnknownBeforeTheFirstTokenFetch(): void
    {
        $this->assertNull($this->client()->hasRefundScopes());
    }

    public function testRefundScopesAreParsedFromTheTokenResponse(): void
    {
        $client = $this->client();
        $this->queueToken('token-1', 'create:quick_payment create:refund view:refund');

        $client->getAccessToken();

        $this->assertTrue($client->hasRefundScopes());
        $this->assertContains('create:refund', $client->getGrantedScopes());
    }

    public function testMissingRefundScopeIsReportedAsFalse(): void
    {
        $client = $this->client();
        $this->queueToken('token-1', 'create:quick_payment view:refund');

        $client->getAccessToken();

        $this->assertFalse($client->hasRefundScopes());
    }

    private const UUID = '035d4ea4-4037-4110-9861-183eae1408b4';
    private const PAYMENT_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    private const IDEM = 'ddc0315c-fba7-4926-ba24-b7700ed389e7';
    private const IDEM_2 = '9f4cb72c-7563-4be5-b76f-1a4197fbad13';

    public function testGetMetaTargetsTheMetadataEndpoint(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(200, [['name' => 'BNZ'], ['name' => 'ANZ']]);

        $banks = $client->getMeta();

        $this->assertSame('GET', $this->transport->lastRequest()['method']);
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/meta', $this->transport->lastRequest()['url']);
        $this->assertSame(['BNZ', 'ANZ'], array_column($banks, 'name'));
    }

    public function testRequestOptionsAreSentAsHeaders(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(200, ['quick_payment_id' => self::UUID]);

        $client->getQuickPayment(
            self::UUID,
            RequestOptions::create()
                ->withRequestId('9f4cb72c-7563-4be5-b76f-1a4197fbad13')
                ->withCorrelationId('f1e62d03-af1a-4c7b-aadb-d916adeb8d9d')
                ->withCustomerIp('203.0.113.9')
                ->withCustomerUserAgent('Mozilla/5.0')
        );

        $headers = $this->transport->lastRequest()['headers'];
        $this->assertContains('request-id: 9f4cb72c-7563-4be5-b76f-1a4197fbad13', $headers);
        $this->assertContains('x-correlation-id: f1e62d03-af1a-4c7b-aadb-d916adeb8d9d', $headers);
        $this->assertContains('x-customer-ip: 203.0.113.9', $headers);
        $this->assertContains('x-customer-user-agent: Mozilla/5.0', $headers);
    }

    public function testRevokeQuickPaymentIssuesADeleteAndReturnsNothing(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queueRaw(204, '');

        $client->revokeQuickPayment(self::UUID);

        $request = $this->transport->lastRequest();
        $this->assertSame('DELETE', $request['method']);
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/quick-payments/' . self::UUID, $request['url']);
        $this->assertNull($request['body']);
    }

    public function testCreateGatewaySingleConsentSharesTheQuickPaymentPayloadShape(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['consent_id' => self::UUID, 'redirect_uri' => 'https://pay']);

        $client->createGatewaySingleConsent('12.50', 'https://shop.example/return', Pcr::build('My Shop', '1005'), self::IDEM);

        $request = $this->transport->lastRequest();
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/single-consents', $request['url']);
        $this->assertContains('idempotency-key: ' . self::IDEM, $request['headers']);
        $payload = json_decode((string) $request['body'], true);
        $this->assertSame('gateway', $payload['flow']['detail']['type']);
        $this->assertSame(['currency' => 'NZD', 'total' => '12.50'], $payload['amount']);
        $this->assertArrayNotHasKey('hashed_customer_identifier', $payload);
    }

    public function testSingleConsentGetAndRevokeUseTheConsentPath(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(200, ['consent_id' => self::UUID, 'status' => 'Authorised']);
        $this->transport->queueRaw(204, '');

        $consent = $client->getSingleConsent(self::UUID);
        $client->revokeSingleConsent(self::UUID);

        $this->assertSame('Authorised', $consent['status']);
        $this->assertSame('GET', $this->transport->requests[1]['method']);
        $this->assertSame('DELETE', $this->transport->requests[2]['method']);
        $this->assertSame(
            'https://sandbox.debit.blinkpay.co.nz/payments/v1/single-consents/' . self::UUID,
            $this->transport->requests[2]['url']
        );
    }

    public function testEnduringConsentLifecycle(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['consent_id' => self::UUID, 'redirect_uri' => 'https://pay']);
        $this->transport->queue(200, ['consent_id' => self::UUID, 'status' => 'AwaitingAuthorisation']);
        $this->transport->queueRaw(204, '');

        $payload = [
            'flow' => ['detail' => ['type' => 'gateway', 'redirect_uri' => 'https://shop.example/return']],
            'from_timestamp' => '2026-10-01T00:00:00+13:00',
            'period' => 'monthly',
            'maximum_amount_period' => ['currency' => 'NZD', 'total' => '50.00'],
        ];
        $client->createEnduringConsent($payload, self::IDEM);
        $client->getEnduringConsent(self::UUID);
        $client->revokeEnduringConsent(self::UUID);

        [, $create, $get, $revoke] = $this->transport->requests;
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/enduring-consents', $create['url']);
        $this->assertSame($payload, json_decode((string) $create['body'], true));
        $this->assertContains('idempotency-key: ' . self::IDEM, $create['headers']);
        $this->assertSame('GET', $get['method']);
        $this->assertSame('DELETE', $revoke['method']);
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/enduring-consents/' . self::UUID, $revoke['url']);
    }

    public function testFixedRecurringPaymentLifecycle(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['fixed_recurring_payment_id' => self::UUID]);
        $this->transport->queue(200, ['fixed_recurring_payment_id' => self::UUID, 'status' => 'active']);
        $this->transport->queueRaw(204, '');

        $payload = [
            'consent_id' => self::UUID,
            'amount' => ['currency' => 'NZD', 'total' => '25.00'],
            'pcr' => Pcr::build('Gym', 'Member 1'),
            'retry_strategy' => 'same_day',
        ];
        $created = $client->createFixedRecurringPayment($payload, self::IDEM);
        $client->getFixedRecurringPayment(self::UUID);
        $client->cancelFixedRecurringPayment(self::UUID);

        [, $create, $get, $cancel] = $this->transport->requests;
        $this->assertSame(self::UUID, $created['fixed_recurring_payment_id']);
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/fixed-recurring-payments', $create['url']);
        $this->assertSame($payload, json_decode((string) $create['body'], true));
        $this->assertSame(
            'https://sandbox.debit.blinkpay.co.nz/payments/v1/fixed-recurring-payments/' . self::UUID,
            $get['url']
        );
        $this->assertSame('DELETE', $cancel['method']);
    }

    public function testSingleConsentPaymentSendsOnlyTheConsentId(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['payment_id' => self::UUID]);

        $client->createSingleConsentPayment(self::UUID, self::IDEM);

        $request = $this->transport->lastRequest();
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/payments', $request['url']);
        $this->assertContains('idempotency-key: ' . self::IDEM, $request['headers']);
        $this->assertSame(['consent_id' => self::UUID], json_decode((string) $request['body'], true));
    }

    public function testEnduringConsentPaymentCarriesAmountAndPcr(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['payment_id' => self::UUID]);

        $client->createEnduringConsentPayment(self::UUID, '19.99', Pcr::build('Gym', 'Oct'), self::IDEM);

        $this->assertSame(
            [
                'consent_id' => self::UUID,
                'amount' => ['currency' => 'NZD', 'total' => '19.99'],
                'pcr' => ['particulars' => 'Gym', 'reference' => 'Oct'],
            ],
            json_decode((string) $this->transport->lastRequest()['body'], true)
        );
    }

    public function testGetPaymentUsesThePaymentPath(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(200, ['payment_id' => self::UUID, 'status' => 'AcceptedSettlementCompleted']);

        $payment = $client->getPayment(self::UUID);

        $this->assertSame('AcceptedSettlementCompleted', $payment['status']);
        $this->assertSame(
            'https://sandbox.debit.blinkpay.co.nz/payments/v1/payments/' . self::UUID,
            $this->transport->lastRequest()['url']
        );
    }

    public function testNonUuidIdempotencyKeyIsRejectedWithoutAnyRequest(): void
    {
        $client = $this->client();

        foreach (['', '   ', 'order-1005', self::IDEM . "\r\nX-Injected: yes"] as $key) {
            try {
                $client->createSingleConsentPayment(self::UUID, $key);
                $this->fail('Expected the idempotency key to be rejected: ' . json_encode($key));
            } catch (BlinkDebitApiException $exception) {
                $this->assertStringContainsString('idempotency key', $exception->getMessage());
            }
        }
        $this->assertSame([], $this->transport->requests);
    }

    public function testGetTransactionsEncodesRequiredWindowAndOptionalFilters(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(200, [['payment_id' => self::UUID]]);

        $client->getTransactions(
            '2024-04-18T00:00:00+12:00',
            '2024-04-18T23:59:59+12:00',
            ['bank' => 'BNZ', 'page' => 2, 'size' => 50, 'merchant_id' => null]
        );

        $url = $this->transport->lastRequest()['url'];
        $this->assertStringStartsWith('https://sandbox.debit.blinkpay.co.nz/payments/v1/transactions?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(
            [
                'bank' => 'BNZ',
                'page' => '2',
                'size' => '50',
                'start_date_time' => '2024-04-18T00:00:00+12:00',
                'end_date_time' => '2024-04-18T23:59:59+12:00',
            ],
            $query
        );
        $this->assertStringContainsString('start_date_time=2024-04-18T00%3A00%3A00%2B12%3A00', $url);
    }

    public function testGetTransactionTotalsOmitsAnAbsentMerchantId(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(200, ['total' => []]);

        $client->getTransactionTotals('2024-04-17', '2024-04-18');

        $this->assertSame(
            'https://sandbox.debit.blinkpay.co.nz/payments/v1/transactions/totals?start_date=2024-04-17&end_date=2024-04-18',
            $this->transport->lastRequest()['url']
        );
    }

    public function testGetTransactionTotalsIncludesTheMerchantIdWhenGiven(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(200, ['total' => []]);

        $client->getTransactionTotals('2024-04-17', '2024-04-18', self::UUID);

        $this->assertStringContainsString('merchant_id=' . self::UUID, $this->transport->lastRequest()['url']);
    }

    public function testSubscriptionLifecycle(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['subscription_id' => self::UUID, 'secret' => 'whsec_x']);
        $this->transport->queue(200, [['subscription_id' => self::UUID]]);
        $this->transport->queueRaw(204, '');

        $created = $client->createSubscription('https://shop.example/webhook', [
            BlinkDebitClient::EVENT_FIXED_RECURRING_PAYMENT_COMPLETED,
            BlinkDebitClient::EVENT_FIXED_RECURRING_PAYMENT_FAILED,
        ]);
        $list = $client->getSubscriptions();
        $client->deleteSubscription(self::UUID);

        [, $create, $get, $delete] = $this->transport->requests;
        $this->assertSame('whsec_x', $created['secret']);
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/subscriptions', $create['url']);
        $this->assertSame(
            [
                'callback_url' => 'https://shop.example/webhook',
                'event_types' => [
                    'urn:nz:co:blinkpay:debit:events:fixed-recurring-payment-completed',
                    'urn:nz:co:blinkpay:debit:events:fixed-recurring-payment-failed',
                ],
            ],
            json_decode((string) $create['body'], true)
        );
        $this->assertCount(1, $list);
        $this->assertSame('GET', $get['method']);
        $this->assertSame('DELETE', $delete['method']);
        $this->assertSame('https://sandbox.debit.blinkpay.co.nz/payments/v1/subscriptions/' . self::UUID, $delete['url']);
    }

    public function testInvalidIdsAreRejectedOnEveryIdEndpoint(): void
    {
        $client = $this->client();
        $calls = [
            static fn () => $client->revokeQuickPayment('nope'),
            static fn () => $client->getSingleConsent('nope'),
            static fn () => $client->revokeSingleConsent('nope'),
            static fn () => $client->getEnduringConsent('nope'),
            static fn () => $client->revokeEnduringConsent('nope'),
            static fn () => $client->getFixedRecurringPayment('nope'),
            static fn () => $client->cancelFixedRecurringPayment('nope'),
            static fn () => $client->createSingleConsentPayment('nope', self::IDEM),
            static fn () => $client->createEnduringConsentPayment('nope', '1.00', Pcr::build('x'), self::IDEM),
            static fn () => $client->getPayment('nope'),
            static fn () => $client->deleteSubscription('nope'),
            static fn () => $client->createFullRefund('nope', Pcr::build('x')),
            static fn () => $client->createPartialRefund('nope', Pcr::build('x'), '1.00'),
            static fn () => $client->createAccountNumberRefund('nope'),
            static fn () => $client->createRefund(['type' => 'account_number', 'payment_id' => 'nope']),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected an invalid ID to be rejected.');
            } catch (BlinkDebitApiException $exception) {
                $this->assertStringContainsString('Invalid', $exception->getMessage());
            }
        }
        $this->assertSame([], $this->transport->requests);
    }

    public function testHasScopesChecksEveryRequestedScope(): void
    {
        $client = $this->client();
        $this->queueToken('token-1', 'create:payment view:payment');

        $client->getAccessToken();

        $this->assertTrue($client->hasScopes(BlinkDebitClient::SCOPE_CREATE_PAYMENT, BlinkDebitClient::SCOPE_VIEW_PAYMENT));
        $this->assertFalse($client->hasScopes(BlinkDebitClient::SCOPE_CREATE_PAYMENT, BlinkDebitClient::SCOPE_VIEW_TRANSACTION));
    }

    public function testCustomerContextHeadersAreOmittedOnNonCustomerOperations(): void
    {
        $client = $this->client();
        $options = RequestOptions::create()
            ->withCorrelationId(self::IDEM_2)
            ->withCustomerIp('203.0.113.9')
            ->withCustomerUserAgent('Mozilla/5.0');
        $this->queueToken();
        for ($i = 0; $i < 9; $i++) {
            $this->transport->queueRaw(200, '[]');
        }

        $client->getMeta($options);
        $client->createFixedRecurringPayment(['consent_id' => self::UUID], self::IDEM, $options);
        $client->getFixedRecurringPayment(self::UUID, $options);
        $client->cancelFixedRecurringPayment(self::UUID, $options);
        $client->getTransactions('2024-04-18T00:00:00+12:00', '2024-04-18T23:59:59+12:00', [], $options);
        $client->getTransactionTotals('2024-04-17', '2024-04-18', null, $options);
        $client->createSubscription('https://shop.example/webhook', [], $options);
        $client->getSubscriptions($options);
        $client->deleteSubscription(self::UUID, $options);

        foreach (array_slice($this->transport->requests, 1) as $request) {
            $this->assertContains('x-correlation-id: ' . self::IDEM_2, $request['headers'], $request['url']);
            foreach ($request['headers'] as $header) {
                $this->assertStringStartsNotWith('x-customer-', $header, $request['url']);
            }
        }
        $this->assertContains('idempotency-key: ' . self::IDEM, $this->transport->requests[2]['headers']);
    }

    public function testCustomerContextHeadersAreSentOnCustomerOperations(): void
    {
        $client = $this->client();
        $options = RequestOptions::create()->withCustomerIp('203.0.113.9');
        $this->queueToken();
        for ($i = 0; $i < 4; $i++) {
            $this->transport->queue(200, ['id' => self::UUID]);
        }

        $client->getSingleConsent(self::UUID, $options);
        $client->getEnduringConsent(self::UUID, $options);
        $client->getPayment(self::UUID, $options);
        $client->getRefund(self::UUID, $options);

        foreach (array_slice($this->transport->requests, 1) as $request) {
            $this->assertContains('x-customer-ip: 203.0.113.9', $request['headers'], $request['url']);
        }
    }

    public function testGetRefundUsesTheRefundPath(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(200, ['refund_id' => self::UUID, 'status' => 'completed']);

        $refund = $client->getRefund(self::UUID);

        $this->assertSame('completed', $refund['status']);
        $this->assertSame(
            'https://sandbox.debit.blinkpay.co.nz/payments/v1/refunds/' . self::UUID,
            $this->transport->lastRequest()['url']
        );
    }

    public function testRawCreateRefundAndCreatePaymentPassThePayloadThrough(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['refund_id' => self::UUID]);
        $this->transport->queue(201, ['payment_id' => self::UUID]);

        $client->createRefund(['type' => 'account_number', 'payment_id' => self::PAYMENT_ID]);
        $client->createPayment(['consent_id' => self::UUID, 'pcr' => ['particulars' => 'x']], self::IDEM);

        [, $refund, $payment] = $this->transport->requests;
        $this->assertSame(['type' => 'account_number', 'payment_id' => self::PAYMENT_ID], json_decode((string) $refund['body'], true));
        $this->assertSame(['consent_id' => self::UUID, 'pcr' => ['particulars' => 'x']], json_decode((string) $payment['body'], true));
        $this->assertContains('idempotency-key: ' . self::IDEM, $payment['headers']);
    }

    public function testRequestTimeoutAndSandboxFlagAreExposed(): void
    {
        $client = $this->client(false);
        $client->setRequestTimeout(5);
        $this->queueToken();
        $this->transport->queue(200, []);

        $client->getMeta();

        $this->assertFalse($client->isSandbox());
        $this->assertSame(5, $this->transport->requests[0]['timeout']);
        $this->assertSame(5, $this->transport->requests[1]['timeout']);
    }

    public function testASecondUnauthorisedResponseRaisesInsteadOfLooping(): void
    {
        $client = $this->client();
        $this->queueToken('stale');
        $this->transport->queue(401, ['message' => 'nope']);
        $this->queueToken('fresh');
        $this->transport->queue(401, ['message' => 'still nope']);

        try {
            $client->getMeta();
            $this->fail('Expected a BlinkDebitApiException.');
        } catch (BlinkDebitApiException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
            $this->assertCount(4, $this->transport->requests);
        }
    }

    public function testEmptyResponseBodyDecodesToAnEmptyArray(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queueRaw(204, '');

        $client->revokeSingleConsent(self::UUID);

        $this->transport->queueRaw(200, 'not json');
        $this->assertSame([], $client->getMeta());
    }

    public function testTokenCacheKeysAreIsolatedPerEnvironmentAndClient(): void
    {
        $cache = new InMemoryTokenCache();
        $sandbox = new BlinkDebitClient('client-a', 'secret', true, $cache, $this->transport);
        $production = new BlinkDebitClient('client-a', 'secret', false, $cache, $this->transport);
        $other = new BlinkDebitClient('client-b', 'secret', true, $cache, $this->transport);

        $this->queueToken('sandbox-a');
        $this->queueToken('production-a');
        $this->queueToken('sandbox-b');

        $this->assertSame('sandbox-a', $sandbox->getAccessToken());
        $this->assertSame('production-a', $production->getAccessToken());
        $this->assertSame('sandbox-b', $other->getAccessToken());
        // Every client hits the cache it populated, never a neighbour's token.
        $this->assertSame('sandbox-a', $sandbox->getAccessToken());
        $this->assertSame('production-a', $production->getAccessToken());
        $this->assertSame('sandbox-b', $other->getAccessToken());
        $this->assertCount(3, $this->transport->requests);
    }

    public function testTokenEndpointOutagesAreNotReportedAsBadCredentials(): void
    {
        $this->transport->queue(503, ['message' => 'upstream unavailable']);

        try {
            $this->client()->getAccessToken();
            $this->fail('Expected a BlinkDebitApiException.');
        } catch (BlinkDebitApiException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
            $this->assertStringContainsString('upstream unavailable', $exception->getMessage());
            $this->assertStringNotContainsString('client secret', $exception->getMessage());
            $this->assertSame(['message' => 'upstream unavailable'], $exception->getResponseBody());
        }
    }

    public function testTokenEndpointRejectionIsReportedAsBadCredentials(): void
    {
        $this->transport->queue(401, ['error' => 'invalid_client']);

        try {
            $this->client()->getAccessToken();
            $this->fail('Expected a BlinkDebitApiException.');
        } catch (BlinkDebitApiException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
            $this->assertStringContainsString('client ID and client secret', $exception->getMessage());
        }
    }

    public function testUnencodablePayloadRaisesTheDocumentedException(): void
    {
        $client = $this->client();
        $this->queueToken();

        try {
            $client->createPayment(['consent_id' => self::UUID, 'pcr' => ['particulars' => "\xB1\x31"]], self::IDEM);
            $this->fail('Expected a BlinkDebitApiException.');
        } catch (BlinkDebitApiException $exception) {
            $this->assertSame(0, $exception->getStatusCode());
            $this->assertStringContainsString('encoded as JSON', $exception->getMessage());
        }
        $this->assertCount(1, $this->transport->requests);
    }

    public function testMalformedAmountsAreRejectedLocally(): void
    {
        $client = $this->client();

        foreach (['1000', '-1.00', '1,000.00', '12.345', ''] as $amount) {
            try {
                $client->createPartialRefund(self::PAYMENT_ID, Pcr::build('x'), $amount);
                $this->fail('Expected the amount to be rejected: ' . $amount);
            } catch (BlinkDebitApiException $exception) {
                $this->assertStringContainsString('Invalid amount', $exception->getMessage());
            }
        }
        $this->assertSame([], $this->transport->requests);
    }

    public function testAmountsWithOneOrTwoDecimalsAreAccepted(): void
    {
        $client = $this->client();
        $this->queueToken();
        $this->transport->queue(201, ['refund_id' => self::UUID]);
        $this->transport->queue(201, ['refund_id' => self::UUID]);

        $client->createPartialRefund(self::PAYMENT_ID, Pcr::build('x'), '12.5');
        $client->createPartialRefund(self::PAYMENT_ID, Pcr::build('x'), '1234567890123.99');

        $this->assertCount(3, $this->transport->requests);
    }

    public function testNonHttpsCallbackUrlIsRejectedLocally(): void
    {
        $client = $this->client();

        foreach (['http://shop.example/webhook', 'shop.example/webhook', 'HTTPS://', 'ftp://shop.example/x'] as $url) {
            try {
                $client->createSubscription($url, [BlinkDebitClient::EVENT_FIXED_RECURRING_PAYMENT_COMPLETED]);
                $this->fail('Expected the callback URL to be rejected: ' . $url);
            } catch (BlinkDebitApiException $exception) {
                $this->assertStringContainsString('callback URL', $exception->getMessage());
            }
        }
        $this->assertSame([], $this->transport->requests);
    }

    public function testDeleteSubscriptionScopeConstantMatchesTheSpec(): void
    {
        $this->assertSame('delete:subscription', BlinkDebitClient::SCOPE_DELETE_SUBSCRIPTION);
    }
}
