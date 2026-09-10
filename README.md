![BlinkPay](https://raw.githubusercontent.com/BlinkPay/Blink-Payments-API-Client-PHP/master/.github/assets/banner.png)

# Blink Payments API Client for PHP
[![CI](https://github.com/BlinkPay/Blink-Payments-API-Client-PHP/actions/workflows/build.yml/badge.svg)](https://github.com/BlinkPay/Blink-Payments-API-Client-PHP/actions/workflows/build.yml)
[![Packagist](https://img.shields.io/packagist/v/blinkpay-nz/blink-debit-api-client-php.svg?label=Packagist)](https://packagist.org/packages/blinkpay-nz/blink-debit-api-client-php)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=blink-debit-api-client-php&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=blink-debit-api-client-php)
[![Vulnerabilities](https://sonarcloud.io/api/project_badges/measure?project=blink-debit-api-client-php&metric=vulnerabilities)](https://sonarcloud.io/summary/new_code?id=blink-debit-api-client-php)
[![Snyk security](https://img.shields.io/badge/Snyk_security-monitored-9043C6)](https://app.snyk.io/org/blinkpay-zw9/project/99a2aaa5-fdc9-4633-acd1-0590a6e0aebf)

# Table of Contents
1. [Introduction](#introduction)
2. [Contributing](#contributing)
3. [Building and Testing Locally](#building-and-testing-locally)
4. [Minimum Requirements](#minimum-requirements)
5. [Adding the Dependency](#adding-the-dependency)
6. [Quick Start](#quick-start)
7. [Configuration](#configuration)
8. [Client Creation](#client-creation)
9. [Request ID, Correlation ID and Idempotency Key](#request-id-correlation-id-and-idempotency-key)
10. [Error Handling](#error-handling)
11. [Full Examples](#full-examples)
12. [Polling and Settlement Behaviour](#polling-and-settlement-behaviour)
13. [Individual API Call Examples](#individual-api-call-examples)
14. [Webhooks](#webhooks)
15. [PSR Interoperability](#psr-interoperability)
16. [Security](#security)
17. [Dependencies](#dependencies)

## Introduction
This SDK allows merchants with PHP-based backends to integrate with **Blink PayNow** (for one-off payments) and **Blink AutoPay** (for recurring payments).

It covers every operation in the Blink Debit API: bank metadata, quick payments, single and enduring consents, fixed recurring payments, payments, refunds, transaction reporting and webhook subscriptions, plus a verifier for signed webhook deliveries, request-body builders, status constants and blocking await helpers for background jobs.

The SDK is framework-agnostic. The same client runs unchanged in plain PHP, Laravel, Symfony, CakePHP and e-commerce platform modules (WooCommerce, PrestaShop, Magento): the core has no runtime dependencies and reaches its environment through two small interfaces, with PSR adapters and framework glue included.

## ⚠️ Security Warning

**This SDK is for SERVER-SIDE use only.**

Your OAuth2 client credentials (`client_id` and `client_secret`) must never reach a browser, a mobile app or client-side code. Your frontend should call **your** backend, which then uses this SDK to communicate with BlinkPay.

## Contributing
We welcome contributions from the community. Your pull request will be reviewed by our team.

This project is licensed under the MIT License.

## Building and Testing Locally

### Prerequisites
- PHP 7.4 or later with the `curl` and `json` extensions (check with `php -v` and `php -m`)
- [Composer 2](https://getcomposer.org/)

### Install and test
From the repository root:
```bash
composer install
composer test        # PHPUnit
composer analyse     # PHPStan, level 8
```

The suite is fully offline: HTTP is replaced by an in-memory transport and sleeps are stubbed, so no sandbox credentials are needed and the retry and polling tests run instantly. The PSR adapters are covered with in-memory PSR-6/PSR-16 doubles and `nyholm/psr7`. The APCu cache tests run wherever the extension is enabled for the CLI (`apc.enable_cli=1`) and are skipped elsewhere.

The Laravel, Symfony and CakePHP glue is tested against the real frameworks from `test-frameworks/`, a separate Composer project (PHP 8.2+) that boots each container, resolves the client and checks that the sandbox flag and token cache are wired as documented. It also runs PHPStan over the glue with the frameworks installed, which the main analysis cannot do:
```bash
cd test-frameworks
composer update
composer analyse
composer test
```
CI runs both suites on every push and pull request.

## Minimum Requirements
- PHP 7.4 or later (8.1+ recommended; the Symfony bundle needs Symfony 6.1+ and therefore PHP 8.1+)
- `ext-curl` and `ext-json`
- Composer 2

Optional, for the integrations:
- Laravel 9+ (auto-discovered service provider)
- Symfony 6.1+ (bundle), or any Symfony version with manual wiring
- CakePHP 4.2+ or 5.x (plugin)
- `ext-apcu`, for the zero-configuration token cache in plain PHP and platform modules
- Any PSR-18 HTTP client with PSR-17 factories, if you prefer not to use the bundled cURL transport

## Adding the dependency
```shell
composer require blinkpay-nz/blink-debit-api-client-php
```

## Quick Start
Append the BlinkPay environment variables to your `.env` (or export them):
```dotenv
BLINKPAY_CLIENT_ID=...
BLINKPAY_CLIENT_SECRET=...
BLINKPAY_SANDBOX=true
```

Create and use the client:
```php
<?php

require 'vendor/autoload.php';

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\Pcr;
use BlinkPay\BlinkDebit\Uuid;

// Reads BLINKPAY_CLIENT_ID, BLINKPAY_CLIENT_SECRET, BLINKPAY_SANDBOX and BLINKPAY_TIMEOUT.
// An unset or blank BLINKPAY_SANDBOX means sandbox; production must be opted into with "false".
$client = BlinkDebitClient::fromEnvironment();

$idempotencyKey = Uuid::v4();   // persist this against the order so a retry reuses it

try {
    $response = $client->createGatewayQuickPayment(
        '0.01',                                                       // NZD total as a decimal string
        'https://www.blinkpay.co.nz/sample-merchant-return-page',     // must be whitelisted for your merchant
        Pcr::build('particulars', 'code', 'reference'),               // what the customer sees on their statement
        $idempotencyKey
    );

    $redirectUri = $response['redirect_uri'];       // Redirect the consumer to this URL
    $quickPaymentId = $response['quick_payment_id'];

    // From a queue job or CLI script, wait for the money to move (see Polling and Settlement Behaviour).
    $quickPayment = $client->awaitSuccessfulQuickPayment($quickPaymentId, 300);
} catch (BlinkDebitApiException $e) {
    error_log('BlinkPay error: ' . $e->getMessage());   // safe to log: never contains credentials
}
```

## Configuration
- The BlinkPay **Sandbox** debit URL is `https://sandbox.debit.blinkpay.co.nz` and the **production** debit URL is `https://debit.blinkpay.co.nz`. The client picks one from the `sandbox` flag; sandbox is the default and production must be opted into explicitly.
- The client credentials will be provided to you by BlinkPay as part of your on-boarding process.
- Credentials belong in environment variables or your secrets manager.
> **Warning** Take care not to check in your client ID and secret to your source control.

### Configuration precedence
1. As provided directly to the client constructor
2. Framework configuration (`config/blinkpay.php`, `blink_debit:` YAML, or the `BlinkPay` key in CakePHP), which reads environment variables
3. Default values (`sandbox` = `true`, timeout 30 seconds)

### Environment variables
```shell
export BLINKPAY_CLIENT_ID=<BLINKPAY_CLIENT_ID>
export BLINKPAY_CLIENT_SECRET=<BLINKPAY_CLIENT_SECRET>
export BLINKPAY_SANDBOX=true            # true/false; unset or blank means true
export BLINKPAY_TIMEOUT=30              # seconds, optional
export BLINKPAY_CACHE_STORE=redis       # Laravel only, optional
```

`BLINKPAY_SANDBOX` is parsed by `Env::bool()` in every entry point (`fromEnvironment()`, the Laravel provider, the CakePHP plugin): unset and blank mean sandbox, `true`/`false`/`yes`/`no`/`on`/`off`/`1`/`0` are accepted, and anything else throws rather than silently picking an environment. Native casts get this wrong (`getenv()` returns `false` when unset and Laravel's `env()` returns `''` for a blank value, both of which `(bool)` turns into production), so prefer the helper over your own parsing.

### Token caching
Access tokens last about an hour and are refreshed five minutes early. The client keeps them in a `TokenCacheInterface`:

- **Default**: `ApcuTokenCache` when the APCu extension is loaded and enabled, so a plain-PHP or platform-module integration under PHP-FPM shares one token across requests with no configuration. Otherwise `InMemoryTokenCache`, which is per-process: fine for CLI scripts and tests, but under PHP-FPM without APCu every request fetches a new token, and the token endpoint is rate limited. Install `ext-apcu` or pass a persistent cache.
- **Frameworks**: the Laravel, Symfony and CakePHP integrations use the framework cache automatically. The store is whichever one the framework is configured with, and a stock install of all three points at the filesystem (Laravel's `file` store in older skeletons, Symfony's `cache.app`, Cake's `FileEngine`), which would write bearer tokens to disk. Point `BLINKPAY_CACHE_STORE`, `blink_debit.cache` or `BlinkPay.cacheConfig` at a memory store (redis, memcached, APCu) instead.
- **Plain PHP**: pass a `Psr16TokenCache`, a `Psr6TokenCache`, or any `TokenCacheInterface` implementation as the fourth constructor argument (or the first argument of `fromEnvironment()`).

Cache keys are scoped by environment and client ID, so a shared cache can never serve a sandbox token to a production client or one merchant's token to another. The SDK ships no file-backed cache of its own, which would write bearer tokens to disk; where the cache is the framework's, keeping tokens off disk is the store choice above. Concurrent workers that miss an empty cache each fetch a token; every token is valid and the retry on `429` absorbs the burst, so no cross-process lock is taken.

## Client creation

### Plain PHP
```php
use BlinkPay\BlinkDebit\BlinkDebitClient;

// From the environment variables above:
$client = BlinkDebitClient::fromEnvironment($tokenCache = null, $transport = null);

// Or explicitly:
$client = new BlinkDebitClient(
    $clientId,
    $clientSecret,
    $sandbox = true,
    $tokenCache = null,     // TokenCacheInterface; default ApcuTokenCache when available, else InMemoryTokenCache
    $transport = null       // HttpTransportInterface; default CurlTransport
);
$client->setRequestTimeout(30);
```

### Laravel (recommended for Laravel apps)
The service provider is auto-discovered. Set the environment variables shown above and, optionally, publish the config:
```bash
php artisan vendor:publish --tag=blinkpay-config
```

Then type-hint the client wherever you need it:
```php
use BlinkPay\BlinkDebit\BlinkDebitClient;

class CheckoutController extends Controller
{
    public function __construct(private BlinkDebitClient $blink)
    {
    }
}
```

Tokens are persisted in the default cache store, or the one named by `BLINKPAY_CACHE_STORE`; set it to `redis`, `memcached` or `apc` rather than `file`. To use your own HTTP stack or token store, bind `HttpTransportInterface` or `TokenCacheInterface` in a provider of your own; the package provider picks those bindings up.

### Symfony 6.1+
Register `BlinkPay\BlinkDebit\Symfony\BlinkDebitBundle` in `config/bundles.php`, then:
```yaml
# config/packages/blink_debit.yaml
blink_debit:
  client_id: '%env(BLINKPAY_CLIENT_ID)%'
  client_secret: '%env(BLINKPAY_CLIENT_SECRET)%'
  # sandbox: false                                            # omit to stay in sandbox; see note
  # cache: cache.app                                          # PSR-6 pool for tokens; prefer a memory-backed pool
  # timeout: 30
  # http_client: Symfony\Component\HttpClient\Psr18Client     # PSR-18 instead of cURL
```

Leave `sandbox` out to stay in sandbox. Symfony's `%env(bool:BLINKPAY_SANDBOX)%` processor turns an unset or blank variable into `false`, which would mean production, so drive it from the environment only through a defaulted parameter (the `test-frameworks/` suite checks that unset and blank resolve to sandbox with exactly this spelling):
```yaml
parameters:
  blink_debit.sandbox: true
blink_debit:
  sandbox: '%env(bool:default:blink_debit.sandbox:BLINKPAY_SANDBOX)%'
```

The client is autowirable by type-hint and available for direct container access as `blink_debit.client`. On Symfony versions before 6.1, wire the same services by hand:
```yaml
services:
  BlinkPay\BlinkDebit\Psr\Psr6TokenCache:
    arguments: ['@cache.app']
  BlinkPay\BlinkDebit\BlinkDebitClient:
    arguments:
      - '%env(BLINKPAY_CLIENT_ID)%'
      - '%env(BLINKPAY_CLIENT_SECRET)%'
      - true
      - '@BlinkPay\BlinkDebit\Psr\Psr6TokenCache'
```

### CakePHP 4.2+ and 5.x
```php
// config/app_local.php
'BlinkPay' => [
    'clientId' => env('BLINKPAY_CLIENT_ID', ''),
    'clientSecret' => env('BLINKPAY_CLIENT_SECRET', ''),
    'sandbox' => env('BLINKPAY_SANDBOX'),   // parsed by the plugin; unset or blank means sandbox
    // 'cacheConfig' => 'default',   // prefer a Redis, Memcached or APCu engine over FileEngine
    // 'timeout' => 30,
],

// src/Application.php, in bootstrap()
$this->addPlugin(\BlinkPay\BlinkDebit\CakePHP\BlinkDebitPlugin::class);
```

The plugin registers the client in the DI container for constructor and action injection. Register `HttpTransportInterface` or `TokenCacheInterface` in your application's `services()` to override the transport or token store.

## Request ID, Correlation ID and Idempotency Key
Every request carries a `request-id` and an `x-correlation-id`. The SDK generates both as UUIDs when you supply none, and keeps them (and the idempotency key) the same across its own retries of one logical request, so a retried call shows up in BlinkPay's logs as one interaction. Every request also carries `User-Agent: blink-debit-api-client-php/<version> php/<php version>`, so support can tell SDK traffic apart.

To supply your own IDs, or the customer-context headers the API defines, pass a trailing `RequestOptions`:
```php
use BlinkPay\BlinkDebit\RequestOptions;

$options = RequestOptions::create()
    ->withRequestId($uuid)                     // overrides the interaction ID Blink Debit would generate
    ->withCorrelationId($uuid)                 // your own UUID for log correlation
    ->withCustomerIp($request->ip())           // when the customer is logged in with you
    ->withCustomerUserAgent($request->userAgent());

$client->getQuickPayment($quickPaymentId, $options);
```

Every value is validated when set: IDs must be UUIDs, the IP must be IPv4 or IPv6, and the User-Agent may not contain control characters. A value that fails validation raises `BlinkDebitApiException` before anything is sent, so an untrusted browser value can never inject a second header into the authenticated request. Customer IP and User-Agent are sent only on the customer-facing operations the API defines them for (quick payments, consents, payments, refunds); reporting and administrative calls carry the tracing headers alone.

**Idempotency keys.** The API accepts an optional `idempotency-key` on consent, payment and refund creation and replays a retried request with the same key and payload for 24 hours instead of creating twice. This SDK makes the key **required** on every consent and payment create call (`createQuickPayment`, `createSingleConsent`, `createEnduringConsent`, `createPayment`, `createFixedRecurringPayment` and their typed helpers) and **optional but recommended** on the refund helpers. That is the SDK's choice, not the API's: a checkout that can be retried by the browser, a queue or a network blip should never be able to debit twice. Use a fresh UUID for each distinct operation, persist it with the order, and reuse it only when retrying that same request. Subscriptions take no key; list existing subscriptions before retrying a failed create by hand.

Generate keys with `Uuid::v4()`, or your framework's helper (`Str::uuid()` in Laravel, `Uuid::v4()` in Symfony, `Text::uuid()` in CakePHP); they are interchangeable.

## Error Handling
Every failure throws `BlinkDebitApiException` or a subclass in `BlinkPay\BlinkDebit\Exception`, so you can catch broadly or by kind:
```php
use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\Exception\ConflictException;
use BlinkPay\BlinkDebit\Exception\RateLimitExceededException;

try {
    $payment = $client->createSingleConsentPayment($consentId, $idempotencyKey);
} catch (ConflictException $e) {
    $e->getErrorCode();      // e.g. "BP712": read the consent's payments before retrying
} catch (RateLimitExceededException $e) {
    // already retried three times; back off and retry later with the same idempotency key
} catch (BlinkDebitApiException $e) {
    $e->getStatusCode();     // HTTP status, or 0 for transport and local validation failures
    $e->getResponseBody();   // decoded error body, when one was returned
    $e->getErrorCode();      // the BPxxx code from that body, or null
    $e->getMessage();        // safe to log: never contains credentials, tokens or request bodies
}
```

| Exception | When |
| --- | --- |
| `Exception\UnauthorisedException` | `401` after one token refresh, or the token endpoint rejected the credentials (`400`/`401`/`403`) |
| `Exception\ForbiddenException` | `403`: missing scope, or another merchant's resource |
| `Exception\ResourceNotFoundException` | `404` |
| `Exception\ConflictException` | `409`, including the idempotency conflicts below |
| `Exception\RateLimitExceededException` | `429` after retries |
| `Exception\ServerErrorException` | `5xx` after retries |
| `Exception\TransportException` | No HTTP response at all (DNS, TCP, TLS, timeout), after retries where safe |
| `Exception\ConsentRejectedException`, `ConsentTimeoutException`, `PaymentRejectedException`, `PaymentTimeoutException` | Thrown by the await helpers; see [Polling and Settlement Behaviour](#polling-and-settlement-behaviour) |
| `BlinkDebitApiException` (base) | Any other status (`400`, `422`, …) and local validation failures (status `0`) |

### Retries
- A `401` on an authenticated call is retried once with a fresh token, so a credential rotation does not strand cached tokens. A second `401` raises `UnauthorisedException`.
- A `429` is retried up to twice more (after 1 s, then 5 s), or after the `Retry-After` the server sent (in seconds or as an HTTP-date) when it is 30 s or less; a longer `Retry-After` ends the retries at once, and the typed exception is thrown, rather than retrying sooner than asked. This applies to every request, including the token fetch, because a rate-limited request was never processed.
- A `5xx` or transport failure is retried on the same schedule **only when the request can be replayed safely**: any `GET` or `DELETE`, the token fetch, and a `POST` that carries an idempotency key (the API replays the original response). A `POST` without a key, such as a refund created without one or a subscription, is not retried, since the first attempt may have succeeded; the exception tells you so.
- Invalid IDs, non-UUID idempotency keys, malformed amounts, over-long PCR text, non-HTTPS callback URLs and unsafe header values are rejected locally, before any request is sent and before any token is fetched.
- A token-endpoint failure names the HTTP status and the server's message; only `400`/`401`/`403` are reported as a credential problem, so a rate limit or outage does not send you to rotate secrets.

### Error codes you will meet
The `code` in the error body (also `getErrorCode()`) distinguishes cases that share an HTTP status:

| Status | Code | Meaning | What to do |
| --- | --- | --- | --- |
| `400` | `BP283` | Enduring consent `expiry_timestamp` falls on the same NZ date as `from_timestamp` | Move the expiry to a later date |
| `400` | — | PCR field over 12 characters or with disallowed characters | Values are never truncated by the API; use `Pcr::build()` to catch this locally |
| `409` | `BP702` | Idempotency key reused with a different payload | Use a fresh key for a different request |
| `409` | `BP703` / `BP708` | Idempotency key reused while the first request is still in flight | Poll the named payment; do not resubmit |
| `409` | `BP710` | Idempotency key already bound to a terminal payment | `Rejected`: resubmit with a fresh key. `AcceptedSettlementCompleted`: do not |
| `409` | `BP712` | A concurrent request on the same consent claimed the bank submission; no payment ID returned | Read the consent's payments before retrying |
| `409` | `BP713` | Keyless enduring payment matched consent, amount and PCR within the same minute | Supply an idempotency key |
| `409` | `BP715` | Card refund raced with settlement | Retryable; try again |
| `422` | `BP053` | Card refund of a payment that is authorised but not yet charged | Wait for the charge to settle |
| `422` | `BP039` | `full_refund` requested after a `partial_refund` exists | Use `partial_refund` for the remainder |

Transactions paging accepts `page` 1–10000 and `size` 1–1000.

## Full Examples
### Quick payment (one-off payment), using Gateway flow
A quick payment is a one-off payment that combines the API calls needed for both the consent and the payment.
```php
use BlinkPay\BlinkDebit\Enum\ConsentStatus;
use BlinkPay\BlinkDebit\Enum\PaymentStatus;

$response = $client->createGatewayQuickPayment(
    '0.01',
    'https://www.blinkpay.co.nz/sample-merchant-return-page',
    Pcr::build('particulars', 'code', 'reference'),
    $idempotencyKey,
    hash('sha256', $customerId)     // optional; omit when no per-customer value exists
);
$redirectUri = $response['redirect_uri'];       // Redirect the consumer to this URL
$quickPaymentId = $response['quick_payment_id'];

// After the consumer returns: confirm server-side, never from query parameters.
$quickPayment = $client->getQuickPayment($quickPaymentId);
$consentStatus = $quickPayment['consent']['status'];                       // ConsentStatus::CONSUMED, ::REJECTED, ...
$paymentStatus = $quickPayment['consent']['payments'][0]['status'] ?? null; // PaymentStatus::* once a payment exists

// Or, from a background job, block until settled (throws on rejection or timeout):
$quickPayment = $client->awaitSuccessfulQuickPayment($quickPaymentId, 300);
```

### Single consent followed by one-off payment, using Gateway flow
```php
$consent = $client->createGatewaySingleConsent(
    '0.01',
    'https://www.blinkpay.co.nz/sample-merchant-return-page',
    Pcr::build('particulars'),
    $idempotencyKey
);
$redirectUri = $consent['redirect_uri'];        // Redirect the consumer to this URL

// After the consumer returns, from a job: wait for authorisation, debit, wait for settlement.
$client->awaitAuthorisedSingleConsent($consent['consent_id'], 300);
$payment = $client->createSingleConsentPayment($consent['consent_id'], $paymentIdempotencyKey);
$settled = $client->awaitSuccessfulPayment($payment['payment_id'], 300);
```

## Polling and Settlement Behaviour

### Payment settlement
Payment settlement is asynchronous. Payments transition through these states (constants on `Enum\PaymentStatus`):
- `Pending` - Payment initiated, not yet settled
- `AcceptedSettlementInProcess` - Settlement in progress
- `AcceptedSettlementCompleted` - ✅ **ONLY THIS STATUS means the payment was accepted**
- `Rejected` - Payment failed

For a bank (A2A) payment, `AcceptedSettlementCompleted` means the payer's bank has sent the money, and the payment carries `accepted_reason` = `source_bank_payment_sent`. For a card payment made through the gateway, it means the card network accepted the charge (`accepted_reason` = `card_network_accepted`); the funds arrive through card settlement, and the payment's `amount` may carry `surcharge` and `total_charge` (the amount the customer actually paid, inclusive of surcharge) when surcharging is enabled for your account. Store `accepted_reason` with the order: it decides which refund type applies later. Constants are on `Enum\AcceptedReason`.

### Await helpers
Four helpers poll once a second for up to `$maxWaitSeconds` attempts and turn the outcome into typed exceptions, mirroring the Java and Node SDKs. The budget counts polls rather than elapsed time: a poll that times out or is retried adds its own duration, so lower `setRequestTimeout()` when the total wait matters. They block, so call them from a queue job, a scheduled command or a CLI script rather than a web request:

| Helper | Returns when | Throws | On timeout |
| --- | --- | --- | --- |
| `awaitSuccessfulQuickPayment($id, $seconds)` | The quick payment's payment is `AcceptedSettlementCompleted` | `ConsentRejectedException`, `ConsentTimeoutException` (gateway timeout), `PaymentRejectedException` | Not yet authorised: **revokes** the quick payment, throws `ConsentTimeoutException`. Authorised but unsettled: throws `PaymentTimeoutException`, revokes nothing |
| `awaitAuthorisedSingleConsent($id, $seconds)` | Consent is `Authorised` (or `Consumed`) | `ConsentRejectedException`, `ConsentTimeoutException` | Throws `ConsentTimeoutException`; nothing to revoke, no money moves on an unpaid single consent |
| `awaitAuthorisedEnduringConsent($id, $seconds)` | Consent is `Authorised` | `ConsentRejectedException`, `ConsentTimeoutException` | **Revokes** the consent (it grants ongoing access), throws `ConsentTimeoutException` |
| `awaitSuccessfulPayment($id, $seconds)` | Payment is `AcceptedSettlementCompleted` | `PaymentRejectedException` | Throws `PaymentTimeoutException`; the payment may still settle, keep polling or use the webhook |

A failed revoke is attached as the exception's `getPrevious()`. If the revoke is refused with `409` because the customer authorised in the moment after the last poll, the quick payment is re-read and reported as settled or as `PaymentTimeoutException`, never as abandoned. A poll that fails with a transport, `5xx` or `429` error leaves the outcome unknown, so it is absorbed and the next poll reads the authoritative status; a `404` or other client error propagates at once. `PaymentTimeoutException` is not a failure: never release goods on it, and never treat it as a rejection.

To poll on your own schedule instead, compare statuses with the constants rather than literals, so a typo cannot silently mis-classify a payment:
```php
use BlinkPay\BlinkDebit\Enum\PaymentStatus;

$payment = $client->getPayment($paymentId);
if ($payment['status'] === PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED) { /* fulfil */ }
```

### Quick payments
The first `getQuickPayment()` call after the consumer authorises **initiates the debit**. Treat an error on that call as "outcome not yet known" and retry; the payment's own status is the authority. A quick payment that is never retrieved is never debited and is eventually rejected.

### Revoking abandoned consents
- Revoke a **quick payment** that the consumer abandoned with `revokeQuickPayment()` while it is unpaid (`awaitSuccessfulQuickPayment()` does this on timeout).
- A **single consent** needs no clean-up if abandoned; no funds move until you create the payment. For a **card** single consent, call `createSingleConsentPayment()` promptly, as an unclaimed hold is released and the consent revoked.
- Revoke an abandoned **enduring consent** with `revokeEnduringConsent()`; it grants ongoing access, so do not leave it open (`awaitAuthorisedEnduringConsent()` does this on timeout).

## Individual API Call Examples
Amounts are NZD decimal strings with one or two decimals, such as `'12.50'`. Statement text is built with `Pcr::build($particulars, $code, $reference)`, in the API's own field order, which validates against the banks' 12-character rules and passes values through unchanged; `Pcr::sanitise()` is the opt-in lossy alternative for free text. Request bodies are built with the classes in `BlinkPay\BlinkDebit\Request` (`Flow`, `QuickPaymentRequest`, `SingleConsentRequest`, `EnduringConsentRequest`, `FixedRecurringPaymentRequest`, `Amount`), which return the API's snake_case arrays, so you can also hand-build or adjust a body exactly as the [API reference](https://merchants.blinkpay.co.nz/docs) defines it. Banks, periods, identifier types, flow types and statuses have constants in `BlinkPay\BlinkDebit\Enum`.

```php
use BlinkPay\BlinkDebit\Enum\Bank;
use BlinkPay\BlinkDebit\Enum\IdentifierType;
use BlinkPay\BlinkDebit\Enum\Period;
use BlinkPay\BlinkDebit\Enum\RetryStrategy;
use BlinkPay\BlinkDebit\Pcr;
use BlinkPay\BlinkDebit\Request\EnduringConsentRequest;
use BlinkPay\BlinkDebit\Request\FixedRecurringPaymentRequest;
use BlinkPay\BlinkDebit\Request\Flow;
use BlinkPay\BlinkDebit\Request\QuickPaymentRequest;
use BlinkPay\BlinkDebit\Request\SingleConsentRequest;
```

### Bank Metadata
Supplies the supported banks and supported flows on your account.
```php
$bankMetadataList = $client->getMeta();
```

### Quick Payments
#### Gateway Flow
```php
$response = $client->createGatewayQuickPayment($total, $redirectUri, Pcr::build($particulars, $code, $reference), $idempotencyKey);
```
#### Gateway Flow - Redirect Flow Hint
```php
$response = $client->createQuickPayment(
    QuickPaymentRequest::build(Flow::gateway($redirectUri, Flow::redirectHint(Bank::BNZ)), $total, Pcr::build($particulars, $code, $reference)),
    $idempotencyKey
);
```
#### Gateway Flow - Decoupled Flow Hint
```php
$response = $client->createQuickPayment(
    QuickPaymentRequest::build(
        Flow::gateway($redirectUri, Flow::decoupledHint(Bank::PNZ, IdentifierType::MOBILE_NUMBER, $mobileNumber)),
        $total,
        Pcr::build($particulars, $code, $reference)
    ),
    $idempotencyKey
);
```
#### Redirect Flow
```php
$response = $client->createQuickPayment(
    QuickPaymentRequest::build(Flow::redirect(Bank::ANZ, $redirectUri), $total, Pcr::build($particulars, $code, $reference)),
    $idempotencyKey
);
```
#### Redirect Flow - Native App
The redirect URI may be a deep or universal link. Setting `redirect_to_app` (the third argument of `Flow::redirect()` and of `Flow::gateway()`) makes the bank return `code` and `state` to the app, which must pass them on to `https://debit.blinkpay.co.nz/bank/1.0/return?state={state}&code={code}&redirect=false`, together with any `error` parameters, to complete the consent.
```php
$response = $client->createQuickPayment(
    QuickPaymentRequest::build(Flow::redirect(Bank::ANZ, 'myapp://blink/return', true), $total, Pcr::build($particulars, $code, $reference)),
    $idempotencyKey
);
```
#### Decoupled Flow
No `redirect_uri` is returned; the bank pushes the authorisation to the customer's app and notifies your callback URL.
```php
$response = $client->createQuickPayment(
    QuickPaymentRequest::build(
        Flow::decoupled(Bank::PNZ, IdentifierType::CONSENT_ID, $previousConsentId, $callbackUrl),
        $total,
        Pcr::build($particulars, $code, $reference)
    ),
    $idempotencyKey
);
```
#### Retrieval
```php
$quickPayment = $client->getQuickPayment($quickPaymentId);
```
#### Revocation
```php
$client->revokeQuickPayment($quickPaymentId);
```

### Single/One-Off Consents
A single consent takes the same body as a quick payment, built with `SingleConsentRequest::build()` and the same `Flow` helpers.
#### Gateway Flow
```php
$consent = $client->createGatewaySingleConsent($total, $redirectUri, Pcr::build($particulars, $code, $reference), $idempotencyKey);
```
#### Gateway Flow - Redirect Flow Hint
```php
$consent = $client->createSingleConsent(
    SingleConsentRequest::build(Flow::gateway($redirectUri, Flow::redirectHint(Bank::PNZ)), $total, Pcr::build($particulars, $code, $reference)),
    $idempotencyKey
);
```
#### Gateway Flow - Decoupled Flow Hint
```php
$consent = $client->createSingleConsent(
    SingleConsentRequest::build(
        Flow::gateway($redirectUri, Flow::decoupledHint($bank, $identifierType, $identifierValue)),
        $total,
        Pcr::build($particulars, $code, $reference)
    ),
    $idempotencyKey
);
```
#### Redirect Flow
Suitable for most consents.
```php
$consent = $client->createSingleConsent(
    SingleConsentRequest::build(Flow::redirect($bank, $redirectUri), $total, Pcr::build($particulars, $code, $reference)),
    $idempotencyKey
);
```
#### Decoupled Flow
This flow type allows better support for mobile by allowing the supply of a mobile number or previous consent ID to identify the customer with their bank.

The customer will receive the consent request directly to their online banking app. This flow does not send the user through a web redirect flow.
```php
$consent = $client->createSingleConsent(
    SingleConsentRequest::build(
        Flow::decoupled($bank, $identifierType, $identifierValue, $callbackUrl),
        $total,
        Pcr::build($particulars, $code, $reference)
    ),
    $idempotencyKey
);
```
#### Retrieval
Get the consent including its status.
```php
$consent = $client->getSingleConsent($consentId);
```
#### Revocation
```php
$client->revokeSingleConsent($consentId);
```

### Blink AutoPay - Enduring/Recurring Consents
Request an ongoing authorisation from the customer to debit their account on a recurring basis.

Note that such an authorisation can be revoked by the customer in their mobile banking app.

`EnduringConsentRequest::build($flow, $fromTimestamp, $period, $maximumAmountPeriod, $expiryTimestamp = null, $maximumAmountPayment = null, $hashedCustomerIdentifier = null)` takes any `Flow`; omit the expiry for an indefinite consent, and note it must not fall on the same NZ date as the start (`400 BP283`).
#### Gateway Flow
```php
$consent = $client->createEnduringConsent(
    EnduringConsentRequest::build(
        Flow::gateway($redirectUri),
        '2026-10-01T00:00:00+13:00',   // from_timestamp
        Period::MONTHLY,               // daily, weekly, fortnightly, monthly, annual
        $totalPerPeriod,
        '2027-10-01T00:00:00+13:00',   // expiry_timestamp, or null for indefinite
        $totalPerPayment               // optional per-payment cap
    ),
    $idempotencyKey
);
```
#### Gateway Flow - Redirect Flow Hint
```php
$flow = Flow::gateway($redirectUri, Flow::redirectHint(Bank::PNZ));
$consent = $client->createEnduringConsent(EnduringConsentRequest::build($flow, $startDate, $period, $totalPerPeriod), $idempotencyKey);
```
#### Gateway Flow - Decoupled Flow Hint
```php
$flow = Flow::gateway($redirectUri, Flow::decoupledHint($bank, $identifierType, $identifierValue));
$consent = $client->createEnduringConsent(EnduringConsentRequest::build($flow, $startDate, $period, $totalPerPeriod), $idempotencyKey);
```
#### Redirect Flow
```php
$flow = Flow::redirect($bank, $redirectUri);
$consent = $client->createEnduringConsent(EnduringConsentRequest::build($flow, $startDate, $period, $totalPerPeriod), $idempotencyKey);
```
#### Decoupled Flow
```php
$flow = Flow::decoupled($bank, $identifierType, $identifierValue, $callbackUrl);
$consent = $client->createEnduringConsent(EnduringConsentRequest::build($flow, $startDate, $period, $totalPerPeriod), $idempotencyKey);
```
#### Retrieval
```php
$consent = $client->getEnduringConsent($consentId);
```
#### Revocation
```php
$client->revokeEnduringConsent($consentId);
```

### Blink AutoPay - Fixed Recurring Payments
Let Blink run a payment schedule against an authorised enduring consent. Only one active schedule is allowed per consent (a duplicate returns `409`), the start date must be today or later in NZ time, and the amount must fit within the consent's caps.
#### Creation
```php
$schedule = $client->createFixedRecurringPayment(
    FixedRecurringPaymentRequest::build(
        $consentId,
        $total,
        Pcr::build($particulars, $code, $reference),
        '2026-11-01',              // optional start_date; defaults to today or the consent's start
        RetryStrategy::SAME_DAY    // optional: none (default) or same_day
    ),
    $idempotencyKey
);
$fixedRecurringPaymentId = $schedule['fixed_recurring_payment_id'];
```
#### Retrieval
```php
$schedule = $client->getFixedRecurringPayment($fixedRecurringPaymentId);   // status, next_payment_date, ...
```
#### Cancellation
Cancels the schedule and prevents future executions. The underlying enduring consent stays in place.
```php
$client->cancelFixedRecurringPayment($fixedRecurringPaymentId);
```

### Payments
The completion of a payment requires a consent to be in the Authorised status.
#### Single/One-Off
```php
$payment = $client->createSingleConsentPayment($consentId, $idempotencyKey);
```
#### Enduring/Recurring
If you already have an approved consent, you can run a Payment against that consent at the frequency as authorised in the consent.
```php
$payment = $client->createEnduringConsentPayment($consentId, $total, Pcr::build($particulars, $code, $reference), $idempotencyKey);
```
#### Raw payload
```php
$payment = $client->createPayment(['consent_id' => $consentId], $idempotencyKey);
```
A `409` with code `BP712` carries no payment ID: a concurrent request on the same consent won the bank submission, so read the consent's payments before retrying.
#### Retrieval
```php
$payment = $client->getPayment($paymentId);
$settled = $client->awaitSuccessfulPayment($paymentId, 300);   // from a job; see Await helpers
```

### Refunds
How the payment settled decides the refund type, so store the settled payment's `accepted_reason` (`Enum\AcceptedReason`) at payment time. The API accepts an optional idempotency key on refunds and replays a retried request with the same key instead of refunding twice; the helpers take it as an optional argument, and money-moving refunds should always send one. Without a key, the SDK does not retry a refund on a `5xx` or transport failure, since the first attempt may have gone through.
#### Account Number Refund
For a bank-settled (A2A) payment. Moves no money: poll `getRefund()` until it carries `account_number`, show that to the merchant for a manual transfer, and never persist the account number into your own storage.
```php
$refund = $client->createAccountNumberRefund($paymentId, $idempotencyKey);
```
#### Full Refund
A money-transfer refund of the whole payment. The API defines this for any payment; today it is processed for card-settled payments, where BlinkPay chooses between cancelling an unsettled charge and refunding a settled one (see the card payments guide in the merchant portal). Not allowed once a partial refund exists (`422 BP039`), and use a partial refund instead when a surcharge was applied.
```php
$refund = $client->createFullRefund($paymentId, Pcr::build($particulars, $code, $reference), $idempotencyKey);
```
#### Partial Refund
A money-transfer refund of part of the payment; several may be made up to the payment total. Today processed for card-settled payments. Always use this when a surcharge was applied.
```php
$refund = $client->createPartialRefund($paymentId, Pcr::build($particulars, $code, $reference), $total, $idempotencyKey);
```
#### Retrieval
A created money-moving refund is not necessarily processed: check `status` (`Enum\RefundStatus`: `processing`, `completed`, `failed`) and surface `detail.consent_redirect` to the merchant when their bank requires them to authorise the refund.
```php
$refund = $client->getRefund($refundId);
```

### Transactions
For reconciliation. Results are newest first; `page` is 1–10000 and `size` is 1–1000 (default 100).
```php
$transactions = $client->getTransactions('2026-09-01T00:00:00+12:00', '2026-09-01T23:59:59+12:00', [
    'bank' => Bank::BNZ,
    'payment_status' => PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED,
    // 'consent_status' => ..., 'card_network' => ..., 'merchant_id' => ..., 'page' => 1, 'size' => 500,
]);

$totals = $client->getTransactionTotals('2026-09-01', '2026-09-07');   // NZ dates; successful payments only
```

### Subscriptions
Register an HTTPS callback for fixed recurring payment lifecycle events. The signing `secret` is returned exactly once, on creation; store it immediately. Sandbox and production have separate subscriptions and secrets. Event types are validated locally against the `EVENT_*` constants.
```php
$subscription = $client->createSubscription('https://shop.example/blinkpay/webhook', [
    BlinkDebitClient::EVENT_FIXED_RECURRING_PAYMENT_COMPLETED,
    BlinkDebitClient::EVENT_FIXED_RECURRING_PAYMENT_FAILED,
    BlinkDebitClient::EVENT_FIXED_RECURRING_PAYMENT_CANCELLED,
]);
$secret = $subscription['secret'];            // whsec_…, shown only now

$subscriptions = $client->getSubscriptions();
$client->deleteSubscription($subscriptionId);
```

### Scopes
Features are gated by the scopes granted to your client. After the first token fetch, `getGrantedScopes()` returns them, `hasScopes(...)` checks any set of `SCOPE_*` constants, and `hasRefundScopes()` is a shortcut for the refund pair. All return `null` before the grant is known; resolve that by calling `getAccessToken()` once rather than assuming the feature is available.

## Webhooks
BlinkPay POSTs a JSON event to your subscription's callback URL, signed with the `X-Signature` header (`t={unix_timestamp},v1={hmac_sha256_hex}` over `{timestamp}.{raw_body}`). Verify every delivery against the **raw** request body before acting on it:
```php
use BlinkPay\BlinkDebit\WebhookSignature;

$rawBody = file_get_contents('php://input');
if (!WebhookSignature::verify($rawBody, $_SERVER['HTTP_X_SIGNATURE'] ?? '', $secret)) {
    http_response_code(400);   // 4xx is final: BlinkPay will not retry a delivery you reject
    exit;
}

$event = json_decode($rawBody, true);
// event_type, event_id, timestamp, frp_id, consent_id, and payment_id when one exists
```

- Signatures more than five minutes from the current time are rejected by default; `verify()` takes a `toleranceSeconds` argument to adjust this.
- Each attempt has a 30-second timeout. Deliveries are retried up to three more times, with exponential backoff, on a `5xx` response or a network failure only. A `4xx` is treated as final and is **not** retried, so return `4xx` for a delivery you are rejecting (a bad signature) and `5xx` for a transient problem on your side (database down) that you want redelivered.
- De-duplicate on the `X-Idempotency-Key` header (stable across retries) or the `event_id` in the body.
- Respond with any `2xx` once the event is safely recorded or queued. Do heavy work asynchronously.

## PSR Interoperability
**PSR-4** is the autoloading standard: a namespace prefix maps to a directory, and every class lives in the file named after it, so `BlinkPay\BlinkDebit\Psr\Psr18Transport` is `src/Psr/Psr18Transport.php`. Composer generates the autoloader from the `autoload.psr-4` entry in `composer.json`; a single `require 'vendor/autoload.php'` makes every class available on first use. Because classes load lazily, the framework adapters can live in this package without their frameworks being installed: nothing touches them until you reference them.

**PSR-18** is the HTTP client standard: one interface, `ClientInterface::sendRequest()`, that takes a PSR-7 request and returns a PSR-7 response. Coding to it lets an application choose Guzzle, Symfony HttpClient or any other implementation without the library caring. PSR-18 deliberately says nothing about building requests, so the companion PSR-17 factories are needed to create the request and its body stream. This library keeps its own tiny `HttpTransportInterface` so the zero-dependency cURL path stays possible, and bridges to PSR-18 with `Psr18Transport`:
```php
use BlinkPay\BlinkDebit\Psr\Psr18Transport;

// Guzzle 7: the client is PSR-18, HttpFactory implements both PSR-17 factories.
$factory = new GuzzleHttp\Psr7\HttpFactory();
$transport = new Psr18Transport(new GuzzleHttp\Client(['timeout' => 30]), $factory, $factory);

// Symfony HttpClient: Psr18Client implements all three interfaces itself.
$psr18 = new Symfony\Component\HttpClient\Psr18Client();
$transport = new Psr18Transport($psr18, $psr18, $psr18);

$client = new BlinkDebitClient($clientId, $clientSecret, true, $tokenCache, $transport);
```
Timeouts are not part of PSR-18, so configure them on the underlying client. A bespoke `HttpTransportInterface` should throw `Exception\TransportException` when no response was received, and may return response headers (keyed by lower-case name) so the client can honour `Retry-After`.

Token caches follow the same pattern. **PSR-16** (simple cache: `get`/`set`/`delete` with a TTL) is implemented by Laravel's cache repository and CakePHP's cache engines, and **PSR-6** (cache pools of items) is the native contract of Symfony Cache. `Psr16TokenCache` and `Psr6TokenCache` wrap either kind:
```php
use BlinkPay\BlinkDebit\Psr\Psr16TokenCache;
use BlinkPay\BlinkDebit\Psr\Psr6TokenCache;

$tokenCache = new Psr16TokenCache($anyPsr16Cache);     // e.g. Cache::store() in Laravel
$tokenCache = new Psr6TokenCache($anyPsr6CachePool);    // e.g. cache.app in Symfony
```

The PSR interface packages are not runtime dependencies of this library; they arrive with the framework or HTTP client you already use.

## Security
- Credentials and tokens never appear in exception messages or logs produced by the SDK.
- TLS certificate and hostname verification are always on, TLS 1.2 is the minimum, and redirects are not followed, so the bearer token cannot leak to another host.
- All IDs are validated as UUIDs and URL-encoded before use in a path; query parameters are RFC 3986 encoded.
- Header values from `RequestOptions` and idempotency keys are validated so untrusted input cannot inject headers.
- Webhook signatures are compared in constant time, with an empty secret always failing and stale or future-dated timestamps rejected.
- Sandbox is the default environment; production must be opted into explicitly, and an unparseable `BLINKPAY_SANDBOX` value is an error rather than a guess.
- Tokens are cached only in memory (process or APCu) or in the cache you supply; the SDK itself never writes them to disk, so give the framework integrations a memory-backed store.
- Requests that may have moved money are never retried blindly: a `5xx` or transport failure is replayed only under an idempotency key the API de-duplicates on.

If you believe you have found a security issue, contact [sysadmin@blinkpay.co.nz](mailto:sysadmin@blinkpay.co.nz) rather than opening a public issue.

## Dependencies
Runtime: none beyond PHP with `ext-curl` and `ext-json`. `ext-apcu` is used for the default token cache when present.

Optional (installed by the application, never by this package):
- `psr/http-client`, `psr/http-factory`, `psr/http-message` plus an implementation, for `Psr18Transport`
- `psr/simple-cache` for `Psr16TokenCache` (Laravel, CakePHP)
- `psr/cache` for `Psr6TokenCache` (Symfony)

Development: PHPUnit, PHPStan, the PSR interface packages and `nyholm/psr7`. The `test-frameworks/` project additionally installs Laravel (via `orchestra/testbench`), Symfony and CakePHP.
