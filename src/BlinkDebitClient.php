<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

use BlinkPay\BlinkDebit\Enum\ConsentStatus;
use BlinkPay\BlinkDebit\Enum\PaymentStatus;
use BlinkPay\BlinkDebit\Enum\RefundType;
use BlinkPay\BlinkDebit\Exception\ConflictException;
use BlinkPay\BlinkDebit\Exception\ConsentRejectedException;
use BlinkPay\BlinkDebit\Exception\ConsentTimeoutException;
use BlinkPay\BlinkDebit\Exception\ForbiddenException;
use BlinkPay\BlinkDebit\Exception\PaymentRejectedException;
use BlinkPay\BlinkDebit\Exception\PaymentTimeoutException;
use BlinkPay\BlinkDebit\Exception\RateLimitExceededException;
use BlinkPay\BlinkDebit\Exception\ResourceNotFoundException;
use BlinkPay\BlinkDebit\Exception\ServerErrorException;
use BlinkPay\BlinkDebit\Exception\TransportException;
use BlinkPay\BlinkDebit\Exception\UnauthorisedException;
use BlinkPay\BlinkDebit\Request\Amount;
use BlinkPay\BlinkDebit\Request\Flow;
use BlinkPay\BlinkDebit\Request\SingleConsentRequest;

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
 * per-request tracing and customer-context headers the API defines. A
 * request-id and x-correlation-id are generated when the caller supplies
 * none, and stay the same across the client's internal retries.
 */
class BlinkDebitClient
{
    /** SDK version, sent in the User-Agent header so support can identify SDK traffic. */
    public const VERSION = '1.0.1';

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

    public const EVENT_TYPES = [
        self::EVENT_FIXED_RECURRING_PAYMENT_COMPLETED,
        self::EVENT_FIXED_RECURRING_PAYMENT_FAILED,
        self::EVENT_FIXED_RECURRING_PAYMENT_CANCELLED,
    ];

    // Access tokens last one hour; refresh five minutes early so an in-flight
    // checkout never crosses the expiry boundary with a stale token.
    private const TOKEN_EXPIRY_BUFFER_SECONDS = 300;
    private const MINIMUM_TOKEN_TTL_SECONDS = 60;

    private const DEFAULT_TIMEOUT_SECONDS = 30;

    private const API_PATH_PREFIX = '/payments/v1';

    /** Labels for the resource identifiers validated before a request is sent. */
    private const LABEL_CONSENT_ID = 'consent ID';
    private const LABEL_PAYMENT_ID = 'payment ID';
    private const LABEL_QUICK_PAYMENT_ID = 'quick payment ID';
    private const LABEL_FIXED_RECURRING_PAYMENT_ID = 'fixed recurring payment ID';
    private const LABEL_REFUND_ID = 'refund ID';
    private const LABEL_SUBSCRIPTION_ID = 'subscription ID';

    /**
     * Delays before the second and third attempt at a request that met a
     * 429, a 5xx or a transport failure: three attempts in total, the same
     * schedule as the Java and Node SDKs.
     */
    private const RETRY_DELAYS_MS = [1000, 5000];

    /**
     * A Retry-After longer than this is not worth holding a PHP request open
     * for: the retries stop and the caller receives the typed exception.
     */
    private const MAX_RETRY_AFTER_SECONDS = 30;

    /**
     * RFC 7231 HTTP-date, e.g. "Wed, 21 Oct 2026 07:28:00 GMT". Spelt out because
     * PHP 8.5 deprecates the DATE_RFC7231 constant.
     */
    private const HTTP_DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

    /** Interval between status polls in the await helpers. */
    private const POLL_INTERVAL_MS = 1000;

    private string $clientId;

    private string $clientSecret;

    private bool $sandbox;

    private TokenCacheInterface $tokenCache;

    private HttpTransportInterface $transport;

    private int $requestTimeout = self::DEFAULT_TIMEOUT_SECONDS;

    /** @var callable(int): void Sleeps for a number of milliseconds. */
    private $sleep;

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
        $this->tokenCache = $tokenCache ?? self::defaultTokenCache();
        $this->transport = $transport ?? new CurlTransport();
        $this->sleep = static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /**
     * Builds a client from the BLINKPAY_CLIENT_ID, BLINKPAY_CLIENT_SECRET,
     * BLINKPAY_SANDBOX and BLINKPAY_TIMEOUT environment variables. An unset
     * or blank BLINKPAY_SANDBOX means sandbox; production must be opted into
     * with an explicit `false`.
     *
     * @throws BlinkDebitApiException When BLINKPAY_SANDBOX or BLINKPAY_TIMEOUT is not parseable.
     */
    public static function fromEnvironment(
        ?TokenCacheInterface $tokenCache = null,
        ?HttpTransportInterface $transport = null
    ): self {
        $client = new self(
            Env::get(Env::CLIENT_ID) ?? '',
            Env::get(Env::CLIENT_SECRET) ?? '',
            Env::bool(Env::get(Env::SANDBOX), true),
            $tokenCache,
            $transport
        );

        $timeout = Env::get(Env::TIMEOUT);
        if ($timeout !== null && trim($timeout) !== '') {
            if (!ctype_digit(trim($timeout))) {
                throw new BlinkDebitApiException(
                    sprintf('Invalid %s "%s": expected a whole number of seconds.', Env::TIMEOUT, $timeout)
                );
            }
            $client->setRequestTimeout((int) $timeout);
        }

        return $client;
    }

    /**
     * APCu when it is loaded and enabled, so tokens survive between PHP-FPM
     * requests without any configuration; otherwise per-process memory. Never
     * a file cache: that would write bearer tokens to disk.
     */
    private static function defaultTokenCache(): TokenCacheInterface
    {
        return ApcuTokenCache::isAvailable() ? new ApcuTokenCache() : new InMemoryTokenCache();
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
     * Replaces the sleep used between retries and status polls. The default
     * blocks with usleep(); an async runtime (Swoole, ReactPHP, Fibers) can
     * substitute a cooperative wait, and tests can substitute none.
     *
     * @param callable(int): void $sleep Receives a duration in milliseconds.
     */
    public function setSleep(callable $sleep): void
    {
        $this->sleep = $sleep;
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
     * Concurrent workers that all miss an empty shared cache each fetch a
     * token; the extra fetches are harmless (every token is valid) and the
     * retry on 429 absorbs a burst, so no cross-process lock is taken here.
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
        $response = $this->sendWithRetry(
            'POST',
            $this->baseUrl() . '/oauth2/token',
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                $this->userAgentHeader(),
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
            true
        );

        $body = json_decode($response['body'], true);
        $body = is_array($body) ? $body : [];

        if ($response['status'] !== 200 || empty($body['access_token'])) {
            throw $this->tokenException($response['status'], $body);
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
     * Creates a quick payment (single consent + one-off debit). Build the
     * body with {@see \BlinkPay\BlinkDebit\Request\QuickPaymentRequest} and
     * {@see Flow}, or pass the API's snake_case shape directly.
     *
     * @param array<string, mixed> $payload        The quick payment request body.
     * @param string               $idempotencyKey Idempotency key so a checkout retry cannot double-create.
     *
     * @return array<string, mixed> Includes `quick_payment_id`, and `redirect_uri` for gateway and
     *                              redirect flows (absent for decoupled flow).
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
            SingleConsentRequest::build(Flow::gateway($redirectUri), $totalNzd, $pcr, $hashedCustomerIdentifier),
            $idempotencyKey,
            $options
        );
    }

    /**
     * Retrieves a quick payment, including its consent status and payments.
     * The first call after authorisation initiates the debit, so callers must
     * treat errors as "outcome not yet known", never as a failed payment.
     * {@see awaitSuccessfulQuickPayment()} handles that for you.
     *
     * @return array<string, mixed>
     *
     * @throws BlinkDebitApiException
     */
    public function getQuickPayment(string $quickPaymentId, ?RequestOptions $options = null): array
    {
        $this->assertUuid($quickPaymentId, self::LABEL_QUICK_PAYMENT_ID);

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
        $this->assertUuid($quickPaymentId, self::LABEL_QUICK_PAYMENT_ID);

        $this->request(
            'DELETE',
            '/quick-payments/' . rawurlencode($quickPaymentId),
            null,
            $this->headersFor($options)
        );
    }

    /**
     * Polls a quick payment once a second until its payment settles, for up
     * to $maxWaitSeconds attempts. Each poll is a getQuickPayment(), so the
     * first one after authorisation initiates the debit; a poll that fails
     * with a transport or server error leaves the outcome unknown and is
     * simply polled again, because the payment's own status is the authority.
     *
     * Suited to a queue job, a scheduled command or a CLI script, not to a
     * web request, which would hold a worker for the whole wait.
     *
     * @return array<string, mixed> The quick payment whose first payment reached AcceptedSettlementCompleted.
     *
     * @throws ConsentRejectedException When the customer rejected the consent or it was revoked.
     * @throws ConsentTimeoutException  When the gateway timed out, or the wait ran out before authorisation.
     *                                  In the latter case the quick payment is revoked first so it cannot
     *                                  be paid later; a failed revoke is attached as the previous exception.
     *                                  A revoke refused with 409 means the customer authorised in the gap
     *                                  since the last poll, so the quick payment is re-read and reported as
     *                                  settled or as a PaymentTimeoutException instead.
     * @throws PaymentRejectedException When the bank declined the payment; no funds moved.
     * @throws PaymentTimeoutException  When the consent was authorised but the payment had not settled in
     *                                  time. Nothing is revoked: funds may be in flight, so keep polling
     *                                  getQuickPayment() or getPayment() rather than treating it as failed.
     * @throws BlinkDebitApiException   For local validation failures.
     */
    public function awaitSuccessfulQuickPayment(
        string $quickPaymentId,
        int $maxWaitSeconds,
        ?RequestOptions $options = null
    ): array {
        $this->assertUuid($quickPaymentId, self::LABEL_QUICK_PAYMENT_ID);

        $lastConsentStatus = null;
        $isSettled = function (array $quickPayment) use ($quickPaymentId, &$lastConsentStatus): bool {
            $consent = is_array($quickPayment['consent'] ?? null) ? $quickPayment['consent'] : [];
            $lastConsentStatus = $consent['status'] ?? null;
            $this->assertConsentNotTerminal($lastConsentStatus, 'quick payment', $quickPaymentId);

            $paymentStatus = $this->firstPaymentStatus($consent);
            if ($paymentStatus === PaymentStatus::REJECTED) {
                throw new PaymentRejectedException(
                    sprintf('The payment for quick payment %s was rejected by the bank.', $quickPaymentId)
                );
            }

            return $paymentStatus === PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED;
        };

        $result = $this->poll(
            $maxWaitSeconds,
            function () use ($quickPaymentId, $options): array {
                return $this->getQuickPayment($quickPaymentId, $options);
            },
            $isSettled
        );
        if ($result !== null) {
            return $result;
        }
        $this->assertQuickPaymentNotAwaitingSettlement($lastConsentStatus, $quickPaymentId, $maxWaitSeconds);

        $revokeFailure = null;
        try {
            $this->revokeQuickPayment($quickPaymentId, $options);
        } catch (ConflictException $exception) {
            // The consent can no longer be revoked, most likely because the
            // customer authorised it between the last poll and the revoke, in
            // which case the debit has been initiated. Re-read before reporting
            // so a paid customer is never reported as abandoned.
            $quickPayment = $this->getQuickPayment($quickPaymentId, $options);
            if ($isSettled($quickPayment)) {
                return $quickPayment;
            }
            $this->assertQuickPaymentNotAwaitingSettlement($lastConsentStatus, $quickPaymentId, $maxWaitSeconds);
            $revokeFailure = $exception;
        } catch (BlinkDebitApiException $exception) {
            $revokeFailure = $exception;
        }

        throw new ConsentTimeoutException(
            sprintf(
                'Quick payment %s was not authorised within %d seconds and has been %s.',
                $quickPaymentId,
                $maxWaitSeconds,
                $revokeFailure === null ? 'revoked' : 'left unrevoked (the revoke failed; see the previous exception)'
            ),
            0,
            null,
            $revokeFailure
        );
    }

    /**
     * Throws PaymentTimeoutException when the consent is Authorised or
     * Consumed, because the payment is then in flight and the quick payment
     * must not be revoked or reported as abandoned.
     *
     * @param mixed $consentStatus
     *
     * @throws PaymentTimeoutException
     */
    private function assertQuickPaymentNotAwaitingSettlement($consentStatus, string $quickPaymentId, int $maxWaitSeconds): void
    {
        if ($consentStatus === ConsentStatus::AUTHORISED || $consentStatus === ConsentStatus::CONSUMED) {
            throw new PaymentTimeoutException(sprintf(
                'Quick payment %s was authorised but its payment had not settled after %d seconds; keep polling.',
                $quickPaymentId,
                $maxWaitSeconds
            ));
        }
    }

    // ------------------------------------------------------------------
    // Single consents
    // ------------------------------------------------------------------

    /**
     * Creates a single (one-off) payment consent. A successful response does
     * not mean the consent is authorised: poll getSingleConsent() (or call
     * awaitAuthorisedSingleConsent()) for status, then debit it with
     * createSingleConsentPayment(). Build the body with
     * {@see SingleConsentRequest} and {@see Flow}.
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
            SingleConsentRequest::build(Flow::gateway($redirectUri), $totalNzd, $pcr, $hashedCustomerIdentifier),
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
        $this->assertUuid($consentId, self::LABEL_CONSENT_ID);

        return $this->request('GET', '/single-consents/' . rawurlencode($consentId), null, $this->headersFor($options));
    }

    /**
     * @throws BlinkDebitApiException
     */
    public function revokeSingleConsent(string $consentId, ?RequestOptions $options = null): void
    {
        $this->assertUuid($consentId, self::LABEL_CONSENT_ID);

        $this->request('DELETE', '/single-consents/' . rawurlencode($consentId), null, $this->headersFor($options));
    }

    /**
     * Polls a single consent once a second until it is Authorised (or already
     * Consumed), for up to $maxWaitSeconds attempts. Nothing is revoked on
     * timeout: an unpaid single consent moves no money, and a card hold is
     * released by the gateway on its own.
     *
     * @return array<string, mixed> The authorised consent.
     *
     * @throws ConsentRejectedException When the customer rejected the consent or it was revoked.
     * @throws ConsentTimeoutException  When the gateway timed out or the wait ran out.
     * @throws BlinkDebitApiException   For local validation failures.
     */
    public function awaitAuthorisedSingleConsent(
        string $consentId,
        int $maxWaitSeconds,
        ?RequestOptions $options = null
    ): array {
        $this->assertUuid($consentId, self::LABEL_CONSENT_ID);

        $result = $this->poll(
            $maxWaitSeconds,
            function () use ($consentId, $options): array {
                return $this->getSingleConsent($consentId, $options);
            },
            function (array $consent) use ($consentId): bool {
                return $this->isConsentAuthorised($consent, 'single consent', $consentId);
            }
        );
        if ($result !== null) {
            return $result;
        }

        throw new ConsentTimeoutException(
            sprintf('Single consent %s was not authorised within %d seconds.', $consentId, $maxWaitSeconds)
        );
    }

    // ------------------------------------------------------------------
    // Enduring consents
    // ------------------------------------------------------------------

    /**
     * Creates an enduring (recurring) payment consent. Build the body with
     * {@see \BlinkPay\BlinkDebit\Request\EnduringConsentRequest} and
     * {@see Flow}; required keys are `flow`, `from_timestamp`, `period` and
     * `maximum_amount_period`, and `expiry_timestamp` may be omitted for an
     * indefinite consent.
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
        $this->assertUuid($consentId, self::LABEL_CONSENT_ID);

        return $this->request('GET', '/enduring-consents/' . rawurlencode($consentId), null, $this->headersFor($options));
    }

    /**
     * @throws BlinkDebitApiException
     */
    public function revokeEnduringConsent(string $consentId, ?RequestOptions $options = null): void
    {
        $this->assertUuid($consentId, self::LABEL_CONSENT_ID);

        $this->request('DELETE', '/enduring-consents/' . rawurlencode($consentId), null, $this->headersFor($options));
    }

    /**
     * Polls an enduring consent once a second until it is Authorised, for up
     * to $maxWaitSeconds attempts. On timeout the consent is revoked first,
     * because an enduring consent grants ongoing access and must not be left
     * open for the customer to authorise later unobserved.
     *
     * @return array<string, mixed> The authorised consent.
     *
     * @throws ConsentRejectedException When the customer rejected the consent or it was revoked.
     * @throws ConsentTimeoutException  When the gateway timed out, or the wait ran out (after revoking; a
     *                                  failed revoke is attached as the previous exception).
     * @throws BlinkDebitApiException   For local validation failures.
     */
    public function awaitAuthorisedEnduringConsent(
        string $consentId,
        int $maxWaitSeconds,
        ?RequestOptions $options = null
    ): array {
        $this->assertUuid($consentId, self::LABEL_CONSENT_ID);

        $result = $this->poll(
            $maxWaitSeconds,
            function () use ($consentId, $options): array {
                return $this->getEnduringConsent($consentId, $options);
            },
            function (array $consent) use ($consentId): bool {
                return $this->isConsentAuthorised($consent, 'enduring consent', $consentId);
            }
        );
        if ($result !== null) {
            return $result;
        }

        $revokeFailure = null;
        try {
            $this->revokeEnduringConsent($consentId, $options);
        } catch (BlinkDebitApiException $exception) {
            $revokeFailure = $exception;
        }

        throw new ConsentTimeoutException(
            sprintf(
                'Enduring consent %s was not authorised within %d seconds and has been %s.',
                $consentId,
                $maxWaitSeconds,
                $revokeFailure === null ? 'revoked' : 'left unrevoked (the revoke failed; see the previous exception)'
            ),
            0,
            null,
            $revokeFailure
        );
    }

    // ------------------------------------------------------------------
    // Fixed recurring payments
    // ------------------------------------------------------------------

    /**
     * Creates a fixed recurring payment schedule against an authorised
     * enduring consent. Build the body with
     * {@see \BlinkPay\BlinkDebit\Request\FixedRecurringPaymentRequest};
     * required keys are `consent_id`, `amount` and `pcr`, while `start_date`
     * (NZ date, today or later) and `retry_strategy` (`none` or `same_day`)
     * are optional. Only one active schedule is allowed per consent (409
     * otherwise).
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
        $this->assertUuid($fixedRecurringPaymentId, self::LABEL_FIXED_RECURRING_PAYMENT_ID);

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
        $this->assertUuid($fixedRecurringPaymentId, self::LABEL_FIXED_RECURRING_PAYMENT_ID);

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
     * A 201 does not mean the debit succeeded: poll getPayment() or call
     * awaitSuccessfulPayment(). A 409 with code BP712 carries no payment ID
     * because a concurrent request on the same consent claimed the bank
     * submission — read the consent's payments before retrying.
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
        $this->assertUuid($consentId, self::LABEL_CONSENT_ID);

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
        $this->assertUuid($consentId, self::LABEL_CONSENT_ID);

        return $this->createPayment(
            [
                'consent_id' => $consentId,
                'amount' => Amount::nzd($totalNzd),
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
        $this->assertUuid($paymentId, self::LABEL_PAYMENT_ID);

        return $this->request('GET', '/payments/' . rawurlencode($paymentId), null, $this->headersFor($options));
    }

    /**
     * Polls a payment once a second until it reaches
     * AcceptedSettlementCompleted, for up to $maxWaitSeconds attempts.
     *
     * @return array<string, mixed> The settled payment.
     *
     * @throws PaymentRejectedException When the bank declined the payment; no funds moved.
     * @throws PaymentTimeoutException  When the payment was still Pending or AcceptedSettlementInProcess
     *                                  after the wait. It may still settle: keep polling or wait for the
     *                                  webhook rather than treating it as failed.
     * @throws BlinkDebitApiException   For local validation failures.
     */
    public function awaitSuccessfulPayment(string $paymentId, int $maxWaitSeconds, ?RequestOptions $options = null): array
    {
        $this->assertUuid($paymentId, self::LABEL_PAYMENT_ID);

        $result = $this->poll(
            $maxWaitSeconds,
            function () use ($paymentId, $options): array {
                return $this->getPayment($paymentId, $options);
            },
            static function (array $payment) use ($paymentId): bool {
                $status = $payment['status'] ?? null;
                if ($status === PaymentStatus::REJECTED) {
                    throw new PaymentRejectedException(sprintf('Payment %s was rejected by the bank.', $paymentId));
                }

                return $status === PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED;
            }
        );
        if ($result !== null) {
            return $result;
        }

        throw new PaymentTimeoutException(
            sprintf('Payment %s had not settled after %d seconds; keep polling.', $paymentId, $maxWaitSeconds)
        );
    }

    // ------------------------------------------------------------------
    // Refunds
    // ------------------------------------------------------------------

    /**
     * Requests a money-transfer refund of the whole payment. Today the API
     * processes this type for card-settled payments (see the card payments
     * guide); for a bank-settled payment use createAccountNumberRefund().
     * Use it only when no surcharge was applied and no partial refund exists
     * (422 BP039); otherwise use createPartialRefund() with the exact amount.
     *
     * @param array<string, string> $pcr            Statement particulars/code/reference; see Pcr::build().
     * @param string|null           $idempotencyKey Optional; the API replays a retried request with the same
     *                                              key instead of refunding twice, so supply one and persist
     *                                              it against the refund attempt.
     *
     * @return array<string, mixed> Includes `refund_id`.
     *
     * @throws BlinkDebitApiException
     */
    public function createFullRefund(
        string $paymentId,
        array $pcr,
        ?string $idempotencyKey = null,
        ?RequestOptions $options = null
    ): array {
        $this->assertUuid($paymentId, self::LABEL_PAYMENT_ID);

        return $this->createRefund([
            'type' => RefundType::FULL_REFUND,
            'payment_id' => $paymentId,
            'pcr' => $pcr,
        ], $idempotencyKey, $options);
    }

    /**
     * Requests a money-transfer refund of part of a payment. Today the API
     * processes this type for card-settled payments; for a bank-settled
     * payment use createAccountNumberRefund(). Several partial refunds may be
     * made against one payment, up to its total.
     *
     * @param array<string, string> $pcr            Statement particulars/code/reference; see Pcr::build().
     * @param string                $totalNzd       Decimal amount as a string, e.g. "12.50".
     * @param string|null           $idempotencyKey Optional; see createFullRefund().
     *
     * @return array<string, mixed> Includes `refund_id`.
     *
     * @throws BlinkDebitApiException
     */
    public function createPartialRefund(
        string $paymentId,
        array $pcr,
        string $totalNzd,
        ?string $idempotencyKey = null,
        ?RequestOptions $options = null
    ): array {
        $this->assertUuid($paymentId, self::LABEL_PAYMENT_ID);

        return $this->createRefund([
            'type' => RefundType::PARTIAL_REFUND,
            'payment_id' => $paymentId,
            'pcr' => $pcr,
            'amount' => Amount::nzd($totalNzd),
        ], $idempotencyKey, $options);
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
    public function createAccountNumberRefund(
        string $paymentId,
        ?string $idempotencyKey = null,
        ?RequestOptions $options = null
    ): array {
        $this->assertUuid($paymentId, self::LABEL_PAYMENT_ID);

        return $this->createRefund([
            'type' => RefundType::ACCOUNT_NUMBER,
            'payment_id' => $paymentId,
        ], $idempotencyKey, $options);
    }

    /**
     * Creates a refund from a caller-built payload. Prefer the typed helpers;
     * this exists for payload shapes the helpers do not cover.
     *
     * The API allows several money-moving refunds against one payment, so a
     * retried request without an idempotency key can refund twice. With a
     * key, the API replays the original response instead; without one, the
     * request is also not retried by this client on a 5xx or transport
     * failure, since the outcome would be unknown.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws BlinkDebitApiException
     */
    public function createRefund(array $payload, ?string $idempotencyKey = null, ?RequestOptions $options = null): array
    {
        if (isset($payload['payment_id']) && is_string($payload['payment_id'])) {
            $this->assertUuid($payload['payment_id'], self::LABEL_PAYMENT_ID);
        }

        return $this->request('POST', '/refunds', $payload, $this->headersFor($options, $idempotencyKey));
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
        $this->assertUuid($refundId, self::LABEL_REFUND_ID);

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
     *                                            `payment_status`, `consent_status`, `page` (1-10000)
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
     * The subscriptions API takes no idempotency key, so this request is not
     * retried on a 5xx or transport failure; list subscriptions before
     * retrying by hand to avoid a duplicate.
     *
     * @param string   $callbackUrl Absolute https:// URL BlinkPay POSTs events to; rejected locally otherwise.
     * @param string[] $eventTypes  One or more of the EVENT_* constants; rejected locally otherwise.
     *                              The list is validated element by element, so a mistyped
     *                              constant fails here rather than on the wire.
     *
     * @return array<string, mixed> Includes `subscription_id` and `secret`.
     *
     * @throws BlinkDebitApiException
     */
    public function createSubscription(string $callbackUrl, array $eventTypes, ?RequestOptions $options = null): array
    {
        $eventTypes = array_values($eventTypes);
        if ($eventTypes === []) {
            throw new BlinkDebitApiException('Invalid event types: at least one EVENT_* constant is required.');
        }
        foreach ($eventTypes as $eventType) {
            if (!in_array($eventType, self::EVENT_TYPES, true)) {
                throw new BlinkDebitApiException(sprintf(
                    'Invalid event type "%s": expected one of %s.',
                    $eventType,
                    implode(', ', self::EVENT_TYPES)
                ));
            }
        }

        return $this->request(
            'POST',
            '/subscriptions',
            [
                'callback_url' => Validation::httpsUrl($callbackUrl, 'callback URL'),
                'event_types' => $eventTypes,
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
        $this->assertUuid($subscriptionId, self::LABEL_SUBSCRIPTION_ID);

        $this->request('DELETE', '/subscriptions/' . rawurlencode($subscriptionId), null, $this->tracingHeadersFor($options));
    }

    // ------------------------------------------------------------------
    // Polling internals
    // ------------------------------------------------------------------

    /**
     * Fetches a resource once a second until $isDone returns true, for up to
     * $maxWaitSeconds attempts. Returns null when the attempts run out.
     * Transport and server errors on a fetch leave the outcome unknown, so
     * they consume an attempt and polling continues; other API errors (a
     * 404, say) propagate at once.
     *
     * $maxWaitSeconds counts polls, not elapsed time: it is the wall-clock
     * wait only while every fetch answers promptly. A fetch that times out or
     * is retried adds its own duration, up to three request timeouts plus the
     * retry delays per poll, so bound the request timeout when the total wait
     * matters.
     *
     * @param callable(): array<string, mixed>     $fetch
     * @param callable(array<string, mixed>): bool $isDone May throw an outcome exception for terminal failures.
     *
     * @return array<string, mixed>|null
     *
     * @throws BlinkDebitApiException
     */
    private function poll(int $maxWaitSeconds, callable $fetch, callable $isDone): ?array
    {
        $attempts = max(1, $maxWaitSeconds);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $resource = $fetch();
                if ($isDone($resource)) {
                    return $resource;
                }
            } catch (TransportException | ServerErrorException | RateLimitExceededException $exception) {
                // Outcome unknown; the next poll reads the authoritative status.
            }

            if ($attempt < $attempts) {
                ($this->sleep)(self::POLL_INTERVAL_MS);
            }
        }

        return null;
    }

    /**
     * True when the consent is Authorised or Consumed; throws for the
     * terminal failure statuses; false while it is still pending.
     *
     * @param array<string, mixed> $consent
     *
     * @throws ConsentRejectedException
     * @throws ConsentTimeoutException
     */
    private function isConsentAuthorised(array $consent, string $kind, string $id): bool
    {
        $status = $consent['status'] ?? null;
        $this->assertConsentNotTerminal($status, $kind, $id);

        return $status === ConsentStatus::AUTHORISED || $status === ConsentStatus::CONSUMED;
    }

    /**
     * @param mixed $status
     *
     * @throws ConsentRejectedException
     * @throws ConsentTimeoutException
     */
    private function assertConsentNotTerminal($status, string $kind, string $id): void
    {
        if ($status === ConsentStatus::REJECTED || $status === ConsentStatus::REVOKED) {
            throw new ConsentRejectedException(sprintf('The %s %s was %s.', $kind, $id, strtolower((string) $status)));
        }
        if ($status === ConsentStatus::GATEWAY_TIMEOUT) {
            throw new ConsentTimeoutException(sprintf('The gateway timed out for %s %s.', $kind, $id));
        }
    }

    /**
     * The status of a consent's first payment, or null when none exists yet.
     *
     * @param array<string, mixed> $consent
     */
    private function firstPaymentStatus(array $consent): ?string
    {
        $payments = $consent['payments'] ?? null;
        if (!is_array($payments) || $payments === []) {
            return null;
        }
        $first = reset($payments);
        $status = is_array($first) ? ($first['status'] ?? null) : null;

        return is_string($status) ? $status : null;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
    }

    private function userAgentHeader(): string
    {
        return 'User-Agent: blink-debit-api-client-php/' . self::VERSION . ' php/' . PHP_VERSION;
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
     * Adds a generated request-id and x-correlation-id where the caller gave
     * none, so every request is traceable in BlinkPay's logs and the same IDs
     * accompany each retry of one logical request.
     *
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private function withTracingIds(array $headers): array
    {
        foreach (['request-id', 'x-correlation-id'] as $name) {
            if (!$this->hasHeader($headers, $name)) {
                $headers[] = $name . ': ' . Uuid::v4();
            }
        }

        return $headers;
    }

    /**
     * @param list<string> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return true;
            }
        }

        return false;
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
     * A 401 is answered by one token refresh and retry, outside the retry
     * budget. A 429 is retried for every method. A 5xx or transport failure
     * is retried only when the request can be replayed safely: any GET or
     * DELETE, and a POST that carries an idempotency key (the API replays
     * the original response for the same key and payload). A POST without a
     * key — a refund or subscription created without one — is not retried,
     * since the first attempt may have succeeded.
     *
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $body
     * @param list<string>              $extraHeaders
     *
     * @return array<string, mixed> Decoded JSON body (empty array for empty responses).
     *
     * @throws BlinkDebitApiException
     */
    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $extraHeaders = $this->withTracingIds($extraHeaders);
        $replayable = $method !== 'POST' || $this->hasHeader($extraHeaders, 'idempotency-key');

        $encodedBody = null;
        if ($body !== null) {
            $extraHeaders[] = 'Content-Type: application/json';
            try {
                $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new BlinkDebitApiException(
                    sprintf('The request body for %s %s could not be encoded as JSON: %s', $method, $path, $exception->getMessage())
                );
            }
        }

        $refreshedToken = false;
        while (true) {
            $headers = array_merge(
                [
                    'Authorization: Bearer ' . $this->getAccessToken($refreshedToken),
                    'Accept: application/json',
                    $this->userAgentHeader(),
                ],
                $extraHeaders
            );

            $response = $this->sendWithRetry(
                $method,
                $this->baseUrl() . self::API_PATH_PREFIX . $path,
                $headers,
                $encodedBody,
                $replayable
            );

            $status = $response['status'];
            $decoded = json_decode($response['body'], true);
            $decoded = is_array($decoded) ? $decoded : [];

            // A cached token can outlive a credential rotation; refresh once and retry.
            if ($status === 401 && !$refreshedToken) {
                $refreshedToken = true;
                continue;
            }

            if ($status >= 400) {
                throw $this->apiException($status, $decoded, $method, $path);
            }

            return $decoded;
        }
    }

    /**
     * Sends one logical request, retrying a 429 (always), and a 5xx or
     * transport failure (when $replayable), on the RETRY_DELAYS_MS schedule,
     * or after a short Retry-After when the server sent one. Returns the
     * final response, whatever its status, for the caller to interpret;
     * rethrows a transport failure only once the attempts are spent.
     *
     * @param non-empty-string $method
     * @param list<string>     $headers
     *
     * @return array{status: int, body: string, headers?: array<string, string>}
     *
     * @throws TransportException
     */
    private function sendWithRetry(string $method, string $url, array $headers, ?string $body, bool $replayable): array
    {
        $attempt = 0;
        while (true) {
            try {
                $response = $this->transport->send($method, $url, $headers, $body, $this->requestTimeout);
            } catch (TransportException $exception) {
                if (!$replayable || !isset(self::RETRY_DELAYS_MS[$attempt])) {
                    throw $exception;
                }
                ($this->sleep)(self::RETRY_DELAYS_MS[$attempt]);
                $attempt++;
                continue;
            }

            $status = $response['status'];
            $retryable = $status === 429 || ($status >= 500 && $replayable);
            if (!$retryable || !isset(self::RETRY_DELAYS_MS[$attempt])) {
                return $response;
            }

            $delayMs = $this->retryDelayMs($response, self::RETRY_DELAYS_MS[$attempt]);
            if ($delayMs === null) {
                return $response;
            }

            ($this->sleep)($delayMs);
            $attempt++;
        }
    }

    /**
     * The delay before the next attempt: the Retry-After header when present
     * and in the future, otherwise the scheduled delay. Null when the server
     * asked for a longer wait than a PHP request should hold, so the caller
     * gives up rather than retrying sooner than asked.
     *
     * @param array{status: int, body: string, headers?: array<string, string>} $response
     */
    private function retryDelayMs(array $response, int $scheduledMs): ?int
    {
        $seconds = self::retryAfterSeconds($response['headers']['retry-after'] ?? null);
        if ($seconds === null || $seconds <= 0) {
            return $scheduledMs;
        }

        return $seconds <= self::MAX_RETRY_AFTER_SECONDS ? $seconds * 1000 : null;
    }

    /**
     * Reads Retry-After in either form RFC 7231 allows: a delay in seconds, or
     * an HTTP-date, which an intermediary such as a CDN may send in place of
     * the API's own seconds. Null when absent or unparseable.
     */
    private static function retryAfterSeconds(?string $header): ?int
    {
        if ($header === null) {
            return null;
        }
        if (ctype_digit($header)) {
            return (int) $header;
        }

        $date = \DateTimeImmutable::createFromFormat(self::HTTP_DATE_FORMAT, $header, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        return $date->getTimestamp() - time();
    }

    /**
     * Maps a non-2xx API response to the exception subclass for its status.
     *
     * @param array<string, mixed> $body
     */
    private function apiException(int $status, array $body, string $method, string $path): BlinkDebitApiException
    {
        $message = $this->extractErrorMessage($body, $status, $method, $path);

        return $this->exceptionForStatus($status, $message, $body);
    }

    /**
     * Distinguishes a credential problem from an unavailable token endpoint so
     * a developer is not sent to rotate secrets during a rate limit or outage.
     *
     * @param array<string, mixed> $body
     */
    private function tokenException(int $status, array $body): BlinkDebitApiException
    {
        if ($status === 400 || $status === 401 || $status === 403) {
            return new UnauthorisedException(
                'Could not authenticate with BlinkPay: check the client ID and client secret.',
                $status,
                $body
            );
        }

        if ($status === 200) {
            return new BlinkDebitApiException(
                'The BlinkPay token endpoint returned HTTP 200 without an access token.',
                $status,
                $body
            );
        }

        $detail = '';
        foreach (['error_description', 'message', 'error'] as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) {
                $detail = ': ' . $body[$key];
                break;
            }
        }

        return $this->exceptionForStatus(
            $status,
            sprintf('The BlinkPay token endpoint returned HTTP %d%s', $status, $detail),
            $body
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function exceptionForStatus(int $status, string $message, array $body): BlinkDebitApiException
    {
        switch (true) {
            case $status === 401:
                return new UnauthorisedException($message, $status, $body);
            case $status === 403:
                return new ForbiddenException($message, $status, $body);
            case $status === 404:
                return new ResourceNotFoundException($message, $status, $body);
            case $status === 409:
                return new ConflictException($message, $status, $body);
            case $status === 429:
                return new RateLimitExceededException($message, $status, $body);
            case $status >= 500:
                return new ServerErrorException($message, $status, $body);
            default:
                return new BlinkDebitApiException($message, $status, $body);
        }
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
