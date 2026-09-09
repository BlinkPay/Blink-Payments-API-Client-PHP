<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

/**
 * Client for the Blink Debit API (Blink PayNow and Blink AutoPay): OAuth token
 * handling, bank metadata, quick payments, single and enduring consents,
 * fixed recurring payments, payments, refunds, transaction reporting and
 * webhook subscriptions.
 *
 * Framework-agnostic by design: no runtime Composer dependencies, a pluggable
 * token cache and a pluggable HTTP transport, so the same client serves plain
 * PHP, Laravel, Symfony, CakePHP and e-commerce platform modules (Magento,
 * PrestaShop, WooCommerce). Credentials and tokens are never included in
 * exception messages.
 *
 * Every endpoint method takes an optional {@see RequestOptions} carrying the
 * per-request tracing and customer-context headers the API defines.
 */
class BlinkDebitClient
{
    public const PRODUCTION_BASE_URL = 'https://debit.blinkpay.co.nz';
    public const SANDBOX_BASE_URL = 'https://sandbox.debit.blinkpay.co.nz';

    public const SCOPE_CREATE_SINGLE_CONSENT = 'create:single_consent';
    public const SCOPE_VIEW_SINGLE_CONSENT = 'view:single_consent';
    public const SCOPE_REVOKE_SINGLE_CONSENT = 'revoke:single_consent';
    public const SCOPE_CREATE_ENDURING_CONSENT = 'create:enduring_consent';
    public const SCOPE_VIEW_ENDURING_CONSENT = 'view:enduring_consent';
    public const SCOPE_REVOKE_ENDURING_CONSENT = 'revoke:enduring_consent';
    public const SCOPE_CREATE_PAYMENT = 'create:payment';
    public const SCOPE_VIEW_PAYMENT = 'view:payment';
    public const SCOPE_VIEW_METADATA = 'view:metadata';
    public const SCOPE_VIEW_TRANSACTION = 'view:transaction';
    public const SCOPE_CREATE_QUICK_PAYMENT = 'create:quick_payment';
    public const SCOPE_VIEW_QUICK_PAYMENT = 'view:quick_payment';
    public const SCOPE_CREATE_REFUND = 'create:refund';
    public const SCOPE_VIEW_REFUND = 'view:refund';
    public const SCOPE_CREATE_SUBSCRIPTION = 'create:subscription';
    public const SCOPE_VIEW_SUBSCRIPTION = 'view:subscription';
    public const SCOPE_DELETE_SUBSCRIPTION = 'delete:subscription';

    /** Webhook event types a subscription can receive. */
    public const EVENT_FIXED_RECURRING_PAYMENT_COMPLETED = 'urn:nz:co:blinkpay:debit:events:fixed-recurring-payment-completed';
    public const EVENT_FIXED_RECURRING_PAYMENT_FAILED = 'urn:nz:co:blinkpay:debit:events:fixed-recurring-payment-failed';
    public const EVENT_FIXED_RECURRING_PAYMENT_CANCELLED = 'urn:nz:co:blinkpay:debit:events:fixed-recurring-payment-cancelled';

    // Access tokens last one hour; refresh five minutes early so an in-flight
    // checkout never crosses the expiry boundary with a stale token.
    private const TOKEN_EXPIRY_BUFFER_SECONDS = 300;
    private const MINIMUM_TOKEN_TTL_SECONDS = 60;

    private const DEFAULT_TIMEOUT_SECONDS = 30;

    private const API_PATH_PREFIX = '/payments/v1';

    private string $clientId;

    private string $clientSecret;

    private bool $sandbox;

    private TokenCacheInterface $tokenCache;

    private HttpTransportInterface $transport;

    private int $requestTimeout = self::DEFAULT_TIMEOUT_SECONDS;

    public function __construct(
        string $clientId,
        string $clientSecret,
        bool $sandbox = true,
        ?TokenCacheInterface $tokenCache = null,
        ?HttpTransportInterface $transport = null
    ) {
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->sandbox = $sandbox;
        $this->tokenCache = $tokenCache ?? new InMemoryTokenCache();
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * Overrides the request timeout for this client instance, covering the
     * token fetch too. The default suits checkout and background jobs, where
     * correctness beats latency; an admin screen rendering inline should fail
     * fast instead of holding the page open for the full budget.
     */
    public function setRequestTimeout(int $seconds): void
    {
        $this->requestTimeout = max(1, $seconds);
    }

    /**
     * Whether both credentials are present.
     */
    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * Returns a cached access token, fetching a new one when missing or forced.
     *
     * @throws BlinkDebitApiException
     */
    public function getAccessToken(bool $forceRefresh = false): string
    {
        if (!$this->isConfigured()) {
            throw new BlinkDebitApiException(
                'BlinkPay is not configured: provide both the client ID and the client secret.'
            );
        }

        if ($forceRefresh) {
            $this->tokenCache->delete($this->tokenCacheKey());
        } else {
            $cached = $this->tokenCache->get($this->tokenCacheKey());
            if ($cached !== null && $cached !== '') {
                return $cached;
            }
        }

        // The documented token contract is OAuth 2.0 form encoding. The server
        // happens to accept a JSON body too, but that is undocumented behaviour
        // a hardening change could withdraw without notice.
        $response = $this->transport->send(
            'POST',
            $this->baseUrl() . '/oauth2/token',
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            http_build_query(
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
                '',
                '&'
            ),
            $this->requestTimeout
        );

        $body = json_decode($response['body'], true);
        $body = is_array($body) ? $body : [];

        if ($response['status'] !== 200 || empty($body['access_token'])) {
            throw new BlinkDebitApiException(
                $this->tokenErrorMessage($response['status'], $body),
                $response['status'],
                $body
            );
        }

        $expiresIn = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;
        $this->tokenCache->set(
            $this->tokenCacheKey(),
            (string) $body['access_token'],
            max(self::MINIMUM_TOKEN_TTL_SECONDS, $expiresIn - self::TOKEN_EXPIRY_BUFFER_SECONDS)
        );

        // The granted scope decides which features an integration may offer,
        // so it is retained beyond the token's own lifetime.
        if (isset($body['scope']) && is_string($body['scope']) && $body['scope'] !== '') {
            $this->tokenCache->set($this->scopeCacheKey(), $body['scope'], null);
        } else {
            $this->tokenCache->delete($this->scopeCacheKey());
        }

        return (string) $body['access_token'];
    }

    /**
     * The scopes granted at the last token fetch, or null when they are
     * unknown — no token has been fetched yet, or the token response carried
     * no scope. Reads only the cache; never makes a request.
     *
     * @return string[]|null
     */
    public function getGrantedScopes(): ?array
    {
        $scope = $this->tokenCache->get($this->scopeCacheKey());
        if (!is_string($scope) || trim($scope) === '') {
            return null;
        }

        return array_values(array_filter(explode(' ', trim($scope)), static function (string $part): bool {
            return $part !== '';
        }));
    }

    /**
     * Whether every given scope was granted at the last token fetch, or null
     * when the grant is unknown (no token fetched yet). Resolve an unknown
     * grant by calling getAccessToken() once rather than treating it as
     * granted; the API enforces scopes server-side regardless, so this is a
     * hint for what to offer in a UI, not an access control.
     */
    public function hasScopes(string ...$scopes): ?bool
    {
        $granted = $this->getGrantedScopes();
        if ($granted === null) {
            return null;
        }

        foreach ($scopes as $scope) {
            if (!in_array($scope, $granted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this client may create and view refunds, or null when the grant
     * is unknown.
     */
    public function hasRefundScopes(): ?bool
    {
        return $this->hasScopes(self::SCOPE_CREATE_REFUND, self::SCOPE_VIEW_REFUND);
    }

    // ------------------------------------------------------------------
    // Bank metadata
    // ------------------------------------------------------------------

    /**
     * Lists the available banks and the features (enduring consent, decoupled
     * flow, card payment, payment limits) each supports.
     *
     * @return array<mixed> List of bank metadata objects, as decoded JSON.
     *
     * @throws BlinkDebitApiException
     */
    public function getMeta(?RequestOptions $options = null): array
    {
        return $this->request('GET', '/meta', null, $this->tracingHeadersFor($options));
    }

    // ------------------------------------------------------------------
    // Quick payments
    // ------------------------------------------------------------------

    /**
     * Creates a quick payment (single consent + one-off debit).
     *
     * @param array<string, mixed> $payload        The quick payment request body.
     * @param string               $idempotencyKey Idempotency key so a checkout retry cannot double-create.
     *
     * @return array<string, mixed> Includes `quick_payment_id` and `redirect_uri`.
     *
     * @throws BlinkDebitApiException
     */
    public function createQuickPayment(array $payload, string $idempotencyKey, ?RequestOptions $options = null): array
    {
        return $this->request('POST', '/quick-payments', $payload, $this->headersFor($options, $idempotencyKey));
    }

    /**
     * Builds and creates a gateway-flow quick payment: the customer is sent to
     * the Blink hosted gateway, which offers bank (A2A) payment and — where
     * enabled for the merchant — card.
     *
     * @param string      $totalNzd                 Decimal amount as a string, e.g. "12.50".
     * @param string      $redirectUri              Absolute URL the customer returns to after paying.
     * @param array<string, string> $pcr            Statement particulars/code/reference; see Pcr::build().
     * @param string      $idempotencyKey           Idempotency key for this checkout attempt.
     * @param string|null $hashedCustomerIdentifier SHA-256 of a per-customer identifier, or null to omit.
     *                                              Send only a genuinely per-customer value: hashing a blank
     *                                              one would make every such order look like one customer to
     *                                              Blink's risk and velocity checks.
     *
     * @return array<string, mixed> Includes `quick_payment_id` and `redirect_uri`.
     *
     * @throws BlinkDebitApiException
     */
    public function createGatewayQuickPayment(
        string $totalNzd,
        string $redirectUri,
        array $pcr,
        string $idempotencyKey,
        ?string $hashedCustomerIdentifier = null,
        ?RequestOptions $options = null
    ): array {
        return $this->createQuickPayment(
            $this->gatewayConsentPayload($totalNzd, $redirectUri, $pcr, $hashedCustomerIdentifier),
            $idempotencyKey,
            $options
        );
    }

    /**
     * Retrieves a quick payment, including its consent status and payments.
     * The first call after authorisation initiates the debit, so callers must
     * treat errors as "outcome not yet known", never as a failed payment.
     *
     * @return array<string, mixed>
     *
     * @throws BlinkDebitApiException
     */
    public function getQuickPayment(string $quickPaymentId, ?RequestOptions $options = null): array
    {
        $this->assertUuid($quickPaymentId, 'quick payment ID');

        return $this->request(
            'GET',
            '/quick-payments/' . rawurlencode($quickPaymentId),
            null,
            $this->headersFor($options)
        );
    }

    /**
     * Revokes an unpaid quick payment. Fails with 409 once the payment has
     * been made.
     *
     * @throws BlinkDebitApiException
     */
    public function revokeQuickPayment(string $quickPaymentId, ?RequestOptions $options = null): void
    {
        $this->assertUuid($quickPaymentId, 'quick payment ID');

        $this->request(
            'DELETE',
            '/quick-payments/' . rawurlencode($quickPaymentId),
            null,
            $this->headersFor($options)
        );
    }

    // ------------------------------------------------------------------
    // Single consents
    // ------------------------------------------------------------------

    /**
     * Creates a single (one-off) payment consent. A successful response does
     * not mean the consent is authorised: poll getSingleConsent() for status,
     * then debit it with createSingleConsentPayment().
     *
     * @param array<string, mixed> $payload The single consent request body (flow, pcr, amount).
     *
     * @return array<string, mixed> Includes `consent_id` and, for gateway/redirect flows, `redirect_uri`.
     *
     * @throws BlinkDebitApiException
     */
    public function createSingleConsent(array $payload, string $idempotencyKey, ?RequestOptions $options = null): array
    {
        return $this->request('POST', '/single-consents', $payload, $this->headersFor($options, $idempotencyKey));
    }

    /**
     * Builds and creates a gateway-flow single consent. Unlike a quick payment,
     * the money is not taken until createSingleConsentPayment() is called; for
     * a card consent the gateway only places a hold.
     *
     * @param array<string, string> $pcr Statement particulars/code/reference; see Pcr::build().
     *
     * @return array<string, mixed> Includes `consent_id` and `redirect_uri`.
     *
     * @throws BlinkDebitApiException
     */
    public function createGatewaySingleConsent(
        string $totalNzd,
        string $redirectUri,
        array $pcr,
        string $idempotencyKey,
        ?string $hashedCustomerIdentifier = null,
        ?RequestOptions $options = null
    ): array {
        return $this->createSingleConsent(
            $this->gatewayConsentPayload($totalNzd, $redirectUri, $pcr, $hashedCustomerIdentifier),
            $idempotencyKey,
            $options
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BlinkDebitApiException
     */
    public function getSingleConsent(string $consentId, ?RequestOptions $options = null): array
    {
        $this->assertUuid($consentId, 'consent ID');

        return $this->request('GET', '/single-consents/' . rawurlencode($consentId), null, $this->headersFor($options));
    }

    /**
     * @throws BlinkDebitApiException
     */
    public function revokeSingleConsent(string $consentId, ?RequestOptions $options = null): void
    {
        $this->assertUuid($consentId, 'consent ID');

        $this->request('DELETE', '/single-consents/' . rawurlencode($consentId), null, $this->headersFor($options));
    }

    // ------------------------------------------------------------------
    // Enduring consents
    // ------------------------------------------------------------------

    /**
     * Creates an enduring (recurring) payment consent. Required payload keys
     * are `flow`, `from_timestamp`, `period` and `maximum_amount_period`;
     * `expiry_timestamp` may be omitted for an indefinite consent.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> Includes `consent_id` and, for gateway/redirect flows, `redirect_uri`.
     *
     * @throws BlinkDebitApiException
     */
    public function createEnduringConsent(array $payload, string $idempotencyKey, ?RequestOptions $options = null): array
    {
        return $this->request('POST', '/enduring-consents', $payload, $this->headersFor($options, $idempotencyKey));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BlinkDebitApiException
     */
    public function getEnduringConsent(string $consentId, ?RequestOptions $options = null): array
    {
        $this->assertUuid($consentId, 'consent ID');

        return $this->request('GET', '/enduring-consents/' . rawurlencode($consentId), null, $this->headersFor($options));
    }

    /**
     * @throws BlinkDebitApiException
     */
    public function revokeEnduringConsent(string $consentId, ?RequestOptions $options = null): void
    {
        $this->assertUuid($consentId, 'consent ID');

        $this->request('DELETE', '/enduring-consents/' . rawurlencode($consentId), null, $this->headersFor($options));
    }

    // ------------------------------------------------------------------
    // Fixed recurring payments
    // ------------------------------------------------------------------

    /**
     * Creates a fixed recurring payment schedule against an authorised
     * enduring consent. Required payload keys are `consent_id`, `amount` and
     * `pcr`; `start_date` (NZ date, today or later) and `retry_strategy`
     * (`none` or `same_day`) are optional. Only one active schedule is
     * allowed per consent (409 otherwise).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> Includes `fixed_recurring_payment_id`.
     *
     * @throws BlinkDebitApiException
     */
    public function createFixedRecurringPayment(
        array $payload,
        string $idempotencyKey,
        ?RequestOptions $options = null
    ): array {
        return $this->request(
            'POST',
            '/fixed-recurring-payments',
            $payload,
            $this->tracingHeadersFor($options, $idempotencyKey)
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BlinkDebitApiException
     */
    public function getFixedRecurringPayment(string $fixedRecurringPaymentId, ?RequestOptions $options = null): array
    {
        $this->assertUuid($fixedRecurringPaymentId, 'fixed recurring payment ID');

        return $this->request(
            'GET',
            '/fixed-recurring-payments/' . rawurlencode($fixedRecurringPaymentId),
            null,
            $this->tracingHeadersFor($options)
        );
    }

    /**
     * Cancels a schedule so no further payments are executed. The underlying
     * enduring consent stays in place; revoke it separately if required.
     *
     * @throws BlinkDebitApiException
     */
    public function cancelFixedRecurringPayment(string $fixedRecurringPaymentId, ?RequestOptions $options = null): void
    {
        $this->assertUuid($fixedRecurringPaymentId, 'fixed recurring payment ID');

        $this->request(
            'DELETE',
            '/fixed-recurring-payments/' . rawurlencode($fixedRecurringPaymentId),
            null,
            $this->tracingHeadersFor($options)
        );
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    /**
     * Creates a payment from a caller-built payload. Prefer the typed
     * helpers; this exists for payload shapes they do not cover.
     *
     * A 201 does not mean the debit succeeded: poll getPayment(). A 409 with
     * code BP712 carries no payment ID because a concurrent request on the
     * same consent claimed the bank submission — read the consent's payments
     * before retrying.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> Includes `payment_id`.
     *
     * @throws BlinkDebitApiException
     */
    public function createPayment(array $payload, string $idempotencyKey, ?RequestOptions $options = null): array
    {
        return $this->request('POST', '/payments', $payload, $this->headersFor($options, $idempotencyKey));
    }

    /**
     * Debits an authorised single consent for the amount and PCR fixed when
     * the consent was created. For a card consent this captures the hold the
     * gateway placed; call it promptly, as an unclaimed hold is released and
     * the consent revoked.
     *
     * @return array<string, mixed> Includes `payment_id`.
     *
     * @throws BlinkDebitApiException
     */
    public function createSingleConsentPayment(
        string $consentId,
        string $idempotencyKey,
        ?RequestOptions $options = null
    ): array {
        $this->assertUuid($consentId, 'consent ID');

        return $this->createPayment(['consent_id' => $consentId], $idempotencyKey, $options);
    }

    /**
     * Debits an authorised enduring consent. The amount must be within the
     * consent's period cap (and per-payment cap, where set).
     *
     * @param string $totalNzd Decimal amount as a string, e.g. "12.50".
     * @param array<string, string> $pcr      Statement particulars/code/reference; see Pcr::build().
     *
     * @return array<string, mixed> Includes `payment_id`.
     *
     * @throws BlinkDebitApiException
     */
    public function createEnduringConsentPayment(
        string $consentId,
        string $totalNzd,
        array $pcr,
        string $idempotencyKey,
        ?RequestOptions $options = null
    ): array {
        $this->assertUuid($consentId, 'consent ID');

        return $this->createPayment(
            [
                'consent_id' => $consentId,
                'amount' => $this->nzdAmount($totalNzd),
                'pcr' => $pcr,
            ],
            $idempotencyKey,
            $options
        );
    }

    /**
     * Retrieves a payment and its status.
     *
     * @return array<string, mixed>
     *
     * @throws BlinkDebitApiException
     */
    public function getPayment(string $paymentId, ?RequestOptions $options = null): array
    {
        $this->assertUuid($paymentId, 'payment ID');

        return $this->request('GET', '/payments/' . rawurlencode($paymentId), null, $this->headersFor($options));
    }

    // ------------------------------------------------------------------
    // Refunds
    // ------------------------------------------------------------------

    /**
     * Refunds a card-settled payment in full through the card network. Use
     * only when the whole payment is being refunded and no surcharge was
     * applied; otherwise use createPartialRefund() with the exact amount.
     *
     * @param array<string, string> $pcr Statement particulars/code/reference; see Pcr::build().
     *
     * @return array<string, mixed> Includes `refund_id`.
     *
     * @throws BlinkDebitApiException
     */
    public function createFullRefund(string $paymentId, array $pcr, ?RequestOptions $options = null): array
    {
        $this->assertUuid($paymentId, 'payment ID');

        return $this->createRefund([
            'type' => 'full_refund',
            'payment_id' => $paymentId,
            'pcr' => $pcr,
        ], $options);
    }

    /**
     * Refunds part of a card-settled payment through the card network.
     *
     * @param array<string, string> $pcr      Statement particulars/code/reference; see Pcr::build().
     * @param string $totalNzd Decimal amount as a string, e.g. "12.50".
     *
     * @return array<string, mixed> Includes `refund_id`.
     *
     * @throws BlinkDebitApiException
     */
    public function createPartialRefund(
        string $paymentId,
        array $pcr,
        string $totalNzd,
        ?RequestOptions $options = null
    ): array {
        $this->assertUuid($paymentId, 'payment ID');

        return $this->createRefund([
            'type' => 'partial_refund',
            'payment_id' => $paymentId,
            'pcr' => $pcr,
            'amount' => $this->nzdAmount($totalNzd),
        ], $options);
    }

    /**
     * Requests the customer's bank account number for a bank-settled (A2A)
     * payment so the merchant can transfer the refund from their own bank.
     * This refund type moves no money, and the account number in the refund
     * response is sensitive: display it to the merchant when needed, never
     * persist it into the platform's own storage.
     *
     * @return array<string, mixed> Includes `refund_id`; poll getRefund() until
     *                              `account_number` is present or status is `failed`.
     *
     * @throws BlinkDebitApiException
     */
    public function createAccountNumberRefund(string $paymentId, ?RequestOptions $options = null): array
    {
        $this->assertUuid($paymentId, 'payment ID');

        return $this->createRefund([
            'type' => 'account_number',
            'payment_id' => $paymentId,
        ], $options);
    }

    /**
     * Creates a refund from a caller-built payload. Prefer the typed helpers;
     * this exists for payload shapes the helpers do not cover. Note the
     * refunds API accepts no idempotency key and allows multiple money-moving
     * refunds against one payment, so callers must guard against double
     * submission themselves.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws BlinkDebitApiException
     */
    public function createRefund(array $payload, ?RequestOptions $options = null): array
    {
        if (isset($payload['payment_id']) && is_string($payload['payment_id'])) {
            $this->assertUuid($payload['payment_id'], 'payment ID');
        }

        return $this->request('POST', '/refunds', $payload, $this->headersFor($options));
    }

    /**
     * Retrieves a refund. A created refund is not necessarily processed:
     * check `status` (e.g. `completed`, `failed`), `detail.consent_redirect`
     * for a refund the merchant must authorise at their bank, and
     * `account_number` for the account_number type.
     *
     * @return array<string, mixed>
     *
     * @throws BlinkDebitApiException
     */
    public function getRefund(string $refundId, ?RequestOptions $options = null): array
    {
        $this->assertUuid($refundId, 'refund ID');

        return $this->request('GET', '/refunds/' . rawurlencode($refundId), null, $this->headersFor($options));
    }

    // ------------------------------------------------------------------
    // Transactions
    // ------------------------------------------------------------------

    /**
     * Lists consent, payment and refund transactions in a window, most recent
     * first, for reconciliation.
     *
     * @param string               $startDateTime ISO 8601 date-time, e.g. "2024-04-18T00:00:00+12:00".
     * @param string               $endDateTime   ISO 8601 date-time.
     * @param array<string, mixed> $filters       Optional `merchant_id`, `bank`, `card_network`,
     *                                            `payment_status`, `consent_status`, `page` (from 1)
     *                                            and `size` (1-1000, default 100).
     *
     * @return array<mixed> List of transaction objects, as decoded JSON.
     *
     * @throws BlinkDebitApiException
     */
    public function getTransactions(
        string $startDateTime,
        string $endDateTime,
        array $filters = [],
        ?RequestOptions $options = null
    ): array {
        $query = array_merge($filters, [
            'start_date_time' => $startDateTime,
            'end_date_time' => $endDateTime,
        ]);

        return $this->request('GET', $this->withQuery('/transactions', $query), null, $this->tracingHeadersFor($options));
    }

    /**
     * Aggregated totals of successful payments per NZ date in a range, most
     * recent date first.
     *
     * @param string      $startDate  ISO 8601 date, e.g. "2024-04-17".
     * @param string      $endDate    ISO 8601 date.
     * @param string|null $merchantId Restrict to one merchant.
     *
     * @return array<string, mixed>
     *
     * @throws BlinkDebitApiException
     */
    public function getTransactionTotals(
        string $startDate,
        string $endDate,
        ?string $merchantId = null,
        ?RequestOptions $options = null
    ): array {
        $query = [
            'merchant_id' => $merchantId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        return $this->request(
            'GET',
            $this->withQuery('/transactions/totals', $query),
            null,
            $this->tracingHeadersFor($options)
        );
    }

    // ------------------------------------------------------------------
    // Subscriptions (webhooks)
    // ------------------------------------------------------------------

    /**
     * Registers an HTTPS callback for fixed recurring payment lifecycle
     * events. The response carries the signing `secret` exactly once — store
     * it immediately and verify deliveries with {@see WebhookSignature}.
     *
     * @param string   $callbackUrl Absolute https:// URL BlinkPay POSTs events to; rejected locally otherwise.
     * @param string[] $eventTypes  One or more of the EVENT_* constants.
     *
     * @return array<string, mixed> Includes `subscription_id` and `secret`.
     *
     * @throws BlinkDebitApiException
     */
    public function createSubscription(string $callbackUrl, array $eventTypes, ?RequestOptions $options = null): array
    {
        return $this->request(
            'POST',
            '/subscriptions',
            [
                'callback_url' => Validation::httpsUrl($callbackUrl, 'callback URL'),
                'event_types' => array_values($eventTypes),
            ],
            $this->tracingHeadersFor($options)
        );
    }

    /**
     * @return array<mixed> List of subscription objects, as decoded JSON.
     *
     * @throws BlinkDebitApiException
     */
    public function getSubscriptions(?RequestOptions $options = null): array
    {
        return $this->request('GET', '/subscriptions', null, $this->tracingHeadersFor($options));
    }

    /**
     * @throws BlinkDebitApiException
     */
    public function deleteSubscription(string $subscriptionId, ?RequestOptions $options = null): void
    {
        $this->assertUuid($subscriptionId, 'subscription ID');

        $this->request('DELETE', '/subscriptions/' . rawurlencode($subscriptionId), null, $this->tracingHeadersFor($options));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
    }

    /**
     * Cache key scoped to the environment and client, so switching either
     * never reuses a token issued for the other.
     */
    private function tokenCacheKey(): string
    {
        return 'blinkpay_token_' . $this->cacheKeySuffix();
    }

    private function scopeCacheKey(): string
    {
        return 'blinkpay_scopes_' . $this->cacheKeySuffix();
    }

    private function cacheKeySuffix(): string
    {
        return hash('sha256', ($this->sandbox ? 'sandbox' : 'production') . '|' . $this->clientId);
    }

    /**
     * @return array{currency: string, total: string}
     */
    private function nzdAmount(string $totalNzd): array
    {
        return [
            'currency' => 'NZD',
            'total' => Validation::amount($totalNzd, 'amount'),
        ];
    }

    /**
     * The request body shared by gateway-flow quick payments and single
     * consents.
     *
     * @param array<string, string> $pcr
     *
     * @return array<string, mixed>
     */
    private function gatewayConsentPayload(
        string $totalNzd,
        string $redirectUri,
        array $pcr,
        ?string $hashedCustomerIdentifier
    ): array {
        $payload = [
            'flow' => [
                'detail' => [
                    'type' => 'gateway',
                    'redirect_uri' => $redirectUri,
                ],
            ],
            'amount' => $this->nzdAmount($totalNzd),
            'pcr' => $pcr,
        ];
        if ($hashedCustomerIdentifier !== null && $hashedCustomerIdentifier !== '') {
            $payload['hashed_customer_identifier'] = $hashedCustomerIdentifier;
        }

        return $payload;
    }

    /**
     * Extra header lines for a customer-facing operation: the caller's tracing
     * and customer-context options plus, for create endpoints, the idempotency
     * key. The API defines the customer IP and User-Agent headers on quick
     * payments, consents, payments and refunds.
     *
     * @return list<string>
     *
     * @throws BlinkDebitApiException When the idempotency key is not a UUID.
     */
    private function headersFor(?RequestOptions $options, ?string $idempotencyKey = null): array
    {
        return $this->withIdempotencyKey($options === null ? [] : $options->toHeaders(true), $idempotencyKey);
    }

    /**
     * Extra header lines for reporting and administrative operations (bank
     * metadata, fixed recurring payments, transactions, subscriptions), which
     * the API defines without customer-context headers. Sending a customer IP
     * there would be needless personal-data transfer.
     *
     * @return list<string>
     *
     * @throws BlinkDebitApiException When the idempotency key is not a UUID.
     */
    private function tracingHeadersFor(?RequestOptions $options, ?string $idempotencyKey = null): array
    {
        return $this->withIdempotencyKey($options === null ? [] : $options->toHeaders(false), $idempotencyKey);
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     *
     * @throws BlinkDebitApiException
     */
    private function withIdempotencyKey(array $headers, ?string $idempotencyKey): array
    {
        if ($idempotencyKey !== null) {
            $headers[] = 'idempotency-key: ' . Validation::uuid($idempotencyKey, 'idempotency key');
        }

        return $headers;
    }

    /**
     * Appends RFC 3986 encoded query parameters, dropping null values so an
     * unset filter is omitted rather than sent as an empty string.
     *
     * @param array<string, mixed> $params
     */
    private function withQuery(string $path, array $params): string
    {
        $params = array_filter($params, static function ($value): bool {
            return $value !== null;
        });

        if ($params === []) {
            return $path;
        }

        return $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @throws BlinkDebitApiException
     */
    private function assertUuid(string $value, string $label): void
    {
        Validation::uuid($value, $label);
    }

    /**
     * Sends an authenticated request to the Blink Debit API.
     *
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $body
     * @param list<string>              $extraHeaders
     *
     * @return array<string, mixed> Decoded JSON body (empty array for empty responses).
     *
     * @throws BlinkDebitApiException
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $extraHeaders = [],
        bool $retrying = false
    ): array {
        $headers = array_merge(
            [
                'Authorization: Bearer ' . $this->getAccessToken($retrying),
                'Accept: application/json',
            ],
            $extraHeaders
        );

        $encodedBody = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            try {
                $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new BlinkDebitApiException(
                    sprintf('The request body for %s %s could not be encoded as JSON: %s', $method, $path, $exception->getMessage())
                );
            }
        }

        $response = $this->transport->send(
            $method,
            $this->baseUrl() . self::API_PATH_PREFIX . $path,
            $headers,
            $encodedBody,
            $this->requestTimeout
        );

        $status = $response['status'];
        $decoded = json_decode($response['body'], true);
        $decoded = is_array($decoded) ? $decoded : [];

        // A cached token can outlive a credential rotation; refresh once and retry.
        if ($status === 401 && !$retrying) {
            return $this->request($method, $path, $body, $extraHeaders, true);
        }

        if ($status >= 400) {
            throw new BlinkDebitApiException(
                $this->extractErrorMessage($decoded, $status, $method, $path),
                $status,
                $decoded
            );
        }

        return $decoded;
    }

    /**
     * Distinguishes a credential problem from an unavailable token endpoint so
     * a developer is not sent to rotate secrets during a rate limit or outage.
     *
     * @param array<string, mixed> $body
     */
    private function tokenErrorMessage(int $status, array $body): string
    {
        if ($status === 400 || $status === 401 || $status === 403) {
            return 'Could not authenticate with BlinkPay: check the client ID and client secret.';
        }

        if ($status === 200) {
            return 'The BlinkPay token endpoint returned HTTP 200 without an access token.';
        }

        $detail = '';
        foreach (['error_description', 'message', 'error'] as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) {
                $detail = ': ' . $body[$key];
                break;
            }
        }

        return sprintf('The BlinkPay token endpoint returned HTTP %d%s', $status, $detail);
    }

    /**
     * Builds a developer-facing message from a Blink Debit error response.
     *
     * @param array<string, mixed> $data
     */
    private function extractErrorMessage(array $data, int $status, string $method, string $path): string
    {
        $message = '';
        if (!empty($data['message']) && is_string($data['message'])) {
            $message = $data['message'];
        } elseif (!empty($data['error']) && is_string($data['error'])) {
            $message = $data['error'];
        }
        if (!empty($data['code']) && is_scalar($data['code'])) {
            $message .= ' (' . $data['code'] . ')';
        }

        if ($message === '') {
            return sprintf('The Blink Debit API returned HTTP %d for %s %s.', $status, $method, $path);
        }

        return sprintf('The Blink Debit API returned HTTP %d for %s %s: %s', $status, $method, $path, $message);
    }
}
