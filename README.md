# Blink Payments API Client for PHP
[![CI](https://github.com/BlinkPay/Blink-Debit-API-Client-PHP/actions/workflows/build.yml/badge.svg)](https://github.com/BlinkPay/Blink-Debit-API-Client-PHP/actions/workflows/build.yml)
[![Packagist](https://img.shields.io/packagist/v/blinkpay/blink-debit-api-client-php.svg?label=Packagist)](https://packagist.org/packages/blinkpay/blink-debit-api-client-php)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=blink-debit-api-client-php&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=blink-debit-api-client-php)
[![Vulnerabilities](https://sonarcloud.io/api/project_badges/measure?project=blink-debit-api-client-php&metric=vulnerabilities)](https://sonarcloud.io/summary/new_code?id=blink-debit-api-client-php)
[![Snyk security](https://img.shields.io/badge/Snyk_security-monitored-9043C6)](https://app.snyk.io/org/blinkpay-zw9)

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

It covers every operation in the Blink Debit API: bank metadata, quick payments, single and enduring consents, fixed recurring payments, payments, refunds, transaction reporting and webhook subscriptions, plus a verifier for signed webhook deliveries.

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

The suite is fully offline: HTTP is replaced by an in-memory transport, so no sandbox credentials are needed. The PSR adapters are covered with in-memory PSR-6/PSR-16 doubles and `nyholm/psr7`. The Laravel, Symfony and CakePHP glue is exercised against the real frameworks in CI-style throwaway projects rather than in this suite, to keep the library's dev dependencies small.

## Minimum Requirements
- PHP 7.4 or later (8.1+ recommended; the Symfony bundle needs Symfony 6.1+ and therefore PHP 8.1+)
- `ext-curl` and `ext-json`
- Composer 2

Optional, for the integrations:
- Laravel 9+ (auto-discovered service provider)
- Symfony 6.1+ (bundle), or any Symfony version with manual wiring
- CakePHP 4.2+ or 5.x (plugin)
- Any PSR-18 HTTP client with PSR-17 factories, if you prefer not to use the bundled cURL transport

## Adding the dependency
```shell
composer require blinkpay/blink-debit-api-client-php
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

$client = new BlinkDebitClient(
    getenv('BLINKPAY_CLIENT_ID'),
    getenv('BLINKPAY_CLIENT_SECRET'),
    filter_var(getenv('BLINKPAY_SANDBOX'), FILTER_VALIDATE_BOOLEAN)
);

try {
    $response = $client->createGatewayQuickPayment(
        '0.01',                                                       // NZD total as a decimal string
        'https://www.blinkpay.co.nz/sample-merchant-return-page',     // must be whitelisted for your merchant
        Pcr::build('particulars', 'reference', 'code'),               // what the customer sees on their statement
        $idempotencyKey                                               // a fresh UUID per checkout attempt
    );

    $redirectUri = $response['redirect_uri'];       // Redirect the consumer to this URL
    $quickPaymentId = $response['quick_payment_id'];

    // After the consumer returns, retrieve the quick payment. The first retrieval
    // initiates the debit, so poll until consent.payments[0].status is terminal.
    $quickPayment = $client->getQuickPayment($quickPaymentId);
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
export BLINKPAY_SANDBOX=true
export BLINKPAY_TIMEOUT=30              # seconds, optional
export BLINKPAY_CACHE_STORE=redis       # Laravel only, optional
```

### Token caching
Access tokens last about an hour and are refreshed five minutes early. The default `InMemoryTokenCache` is per-process, which suits CLI scripts and tests; a web integration should persist tokens in its framework cache so each request does not fetch a new token. The framework integrations below do this automatically; plain PHP can pass a `Psr16TokenCache`, a `Psr6TokenCache`, or any `TokenCacheInterface` implementation. Cache keys are scoped by environment and client ID, so a shared cache can never serve a sandbox token to a production client or one merchant's token to another.

## Client creation

### Plain PHP
```php
use BlinkPay\BlinkDebit\BlinkDebitClient;

$client = new BlinkDebitClient(
    $clientId,
    $clientSecret,
    $sandbox = true,
    $tokenCache = null,     // TokenCacheInterface; default InMemoryTokenCache
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

Tokens are persisted in the default cache store (or `BLINKPAY_CACHE_STORE`). To use your own HTTP stack or token store, bind `HttpTransportInterface` or `TokenCacheInterface` in a provider of your own; the package provider picks those bindings up.

### Symfony 6.1+
Register `BlinkPay\BlinkDebit\Symfony\BlinkDebitBundle` in `config/bundles.php`, then:
```yaml
# config/packages/blink_debit.yaml
blink_debit:
  client_id: '%env(BLINKPAY_CLIENT_ID)%'
  client_secret: '%env(BLINKPAY_CLIENT_SECRET)%'
  sandbox: '%env(bool:BLINKPAY_SANDBOX)%'
  # cache: cache.app                                          # PSR-6 pool for tokens
  # timeout: 30
  # http_client: Symfony\Component\HttpClient\Psr18Client     # PSR-18 instead of cURL
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
      - '%env(bool:BLINKPAY_SANDBOX)%'
      - '@BlinkPay\BlinkDebit\Psr\Psr6TokenCache'
```

### CakePHP 4.2+ and 5.x
```php
// config/app_local.php
'BlinkPay' => [
    'clientId' => env('BLINKPAY_CLIENT_ID', ''),
    'clientSecret' => env('BLINKPAY_CLIENT_SECRET', ''),
    'sandbox' => filter_var(env('BLINKPAY_SANDBOX', true), FILTER_VALIDATE_BOOLEAN),
    // 'cacheConfig' => 'default',
    // 'timeout' => 30,
],

// src/Application.php, in bootstrap()
$this->addPlugin(\BlinkPay\BlinkDebit\CakePHP\BlinkDebitPlugin::class);
```

The plugin registers the client in the DI container for constructor and action injection. Register `HttpTransportInterface` or `TokenCacheInterface` in your application's `services()` to override the transport or token store.

## Request ID, Correlation ID and Idempotency Key
Every endpoint method accepts an optional trailing `RequestOptions` carrying the tracing and customer-context headers the API defines:
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

**Idempotency keys are required** on every consent and payment create call (`createQuickPayment`, `createSingleConsent`, `createEnduringConsent`, `createPayment`, `createFixedRecurringPayment` and their typed helpers). Use a fresh UUID for each distinct operation and reuse it only when retrying that same request: a key is bound permanently to the payment it creates, so it can never produce a second debit. The refunds and subscriptions endpoints take no idempotency key; guard those against double submission in your integration (a per-order lock, say).

Unlike the Java, Node and .NET SDKs, this SDK does not generate IDs for you. PHP has no standard UUID function, and generating a key silently would defeat its purpose across a retried PHP request. Use `random_bytes()` plus formatting, or your framework's helper (`Str::uuid()` in Laravel, `Uuid::v4()` in Symfony, `Text::uuid()` in CakePHP).

## Error Handling
Every failure throws `BlinkDebitApiException`:
```php
use BlinkPay\BlinkDebit\BlinkDebitApiException;

try {
    $payment = $client->createSingleConsentPayment($consentId, $idempotencyKey);
} catch (BlinkDebitApiException $e) {
    $e->getStatusCode();     // HTTP status, or 0 for transport and local validation failures
    $e->getResponseBody();   // decoded error body, when one was returned (includes the BPxxx code)
    $e->getMessage();        // safe to log: never contains credentials, tokens or request bodies
}
```

- A `401` on an authenticated call is retried once with a fresh token before the exception is raised, so a credential rotation does not strand cached tokens. A second `401` raises.
- Invalid IDs, non-UUID idempotency keys, malformed amounts, non-HTTPS callback URLs and unsafe header values are rejected locally, before any request is sent.
- A token-endpoint failure names the HTTP status and the server's message; only `400`/`401`/`403` are reported as a credential problem, so a rate limit or outage does not send you to rotate secrets.
- The SDK does not retry `429` or `5xx` responses itself. Retry at the integration level with the **same** idempotency key, which the API replays safely.

## Full Examples
### Quick payment (one-off payment), using Gateway flow
A quick payment is a one-off payment that combines the API calls needed for both the consent and the payment.
```php
$response = $client->createGatewayQuickPayment(
    '0.01',
    'https://www.blinkpay.co.nz/sample-merchant-return-page',
    Pcr::build('particulars', 'reference', 'code'),
    $idempotencyKey,
    hash('sha256', $customerId)     // optional; omit when no per-customer value exists
);
$redirectUri = $response['redirect_uri'];       // Redirect the consumer to this URL
$quickPaymentId = $response['quick_payment_id'];

// After the consumer returns: confirm server-side, never from query parameters.
$quickPayment = $client->getQuickPayment($quickPaymentId);
$consentStatus = $quickPayment['consent']['status'];                       // Consumed, Rejected, Revoked, GatewayTimeout, ...
$paymentStatus = $quickPayment['consent']['payments'][0]['status'] ?? null; // once Consumed or Rejected
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

// After the consumer returns and the consent is Authorised:
$payment = $client->createSingleConsentPayment($consent['consent_id'], $paymentIdempotencyKey);
$status = $client->getPayment($payment['payment_id'])['status'];
// TODO inspect the payment result status
```

## Polling and Settlement Behaviour
This SDK does not include blocking "await" helpers, since a PHP web request is usually short-lived. Poll from a queue job, a scheduled command or the customer's return page, with a bounded number of attempts.

### Payment settlement
Payment settlement is asynchronous. Payments transition through these states:
- `Pending` - Payment initiated, not yet settled
- `AcceptedSettlementInProcess` - Settlement in progress
- `AcceptedSettlementCompleted` - ✅ **ONLY THIS STATUS means money has been sent from the payer's bank**
- `Rejected` - Payment failed

```php
function waitForSettlement(BlinkDebitClient $client, string $paymentId, int $maxAttempts = 60): array
{
    for ($i = 0; $i < $maxAttempts; $i++) {
        $payment = $client->getPayment($paymentId);

        if ($payment['status'] === 'AcceptedSettlementCompleted') {
            return $payment;    // SUCCESS - funds sent from payer's bank
        }
        if ($payment['status'] === 'Rejected') {
            throw new RuntimeException('Payment rejected');
        }

        sleep(5);
    }
    throw new RuntimeException('Payment settlement timeout');
}
```

### Quick payments
The first `getQuickPayment()` call after the consumer authorises **initiates the debit**. Treat an error on that call as "outcome not yet known" and retry; the payment's own status is the authority. A quick payment that is never retrieved is never debited and is eventually rejected.

### Revoking abandoned consents
- Revoke a **quick payment** that the consumer abandoned with `revokeQuickPayment()` while it is unpaid.
- A **single consent** needs no clean-up if abandoned; no funds move until you create the payment. For a **card** single consent, call `createSingleConsentPayment()` promptly, as an unclaimed hold is released and the consent revoked.
- Revoke an abandoned **enduring consent** with `revokeEnduringConsent()`; it grants ongoing access, so do not leave it open.

## Individual API Call Examples
Amounts are NZD decimal strings with one or two decimals, such as `'12.50'`. Statement text is built with `Pcr::build($particulars, $reference, $code)`, which sanitises to the banks' 12-character rules. Raw-payload methods accept the request body exactly as the [API reference](https://docs.blinkpay.co.nz) defines it, using snake_case keys.

### Bank Metadata
Supplies the supported banks and supported flows on your account.
```php
$bankMetadataList = $client->getMeta();
```

### Quick Payments
#### Gateway Flow
```php
$response = $client->createGatewayQuickPayment($total, $redirectUri, Pcr::build($particulars, $reference, $code), $idempotencyKey);
```
#### Gateway Flow - Redirect Flow Hint
```php
$response = $client->createQuickPayment([
    'flow' => ['detail' => [
        'type' => 'gateway',
        'redirect_uri' => $redirectUri,
        'flow_hint' => ['type' => 'redirect', 'bank' => $bank],
    ]],
    'amount' => ['currency' => 'NZD', 'total' => $total],
    'pcr' => Pcr::build($particulars, $reference, $code),
], $idempotencyKey);
```
#### Gateway Flow - Decoupled Flow Hint
```php
$response = $client->createQuickPayment([
    'flow' => ['detail' => [
        'type' => 'gateway',
        'redirect_uri' => $redirectUri,
        'flow_hint' => [
            'type' => 'decoupled',
            'bank' => $bank,
            'identifier_type' => $identifierType,
            'identifier_value' => $identifierValue,
        ],
    ]],
    'amount' => ['currency' => 'NZD', 'total' => $total],
    'pcr' => Pcr::build($particulars, $reference, $code),
], $idempotencyKey);
```
#### Redirect Flow
```php
$response = $client->createQuickPayment([
    'flow' => ['detail' => ['type' => 'redirect', 'bank' => $bank, 'redirect_uri' => $redirectUri]],
    'amount' => ['currency' => 'NZD', 'total' => $total],
    'pcr' => Pcr::build($particulars, $reference, $code),
], $idempotencyKey);
```
#### Decoupled Flow
```php
$response = $client->createQuickPayment([
    'flow' => ['detail' => [
        'type' => 'decoupled',
        'bank' => $bank,
        'identifier_type' => $identifierType,
        'identifier_value' => $identifierValue,
        'callback_url' => $callbackUrl,
    ]],
    'amount' => ['currency' => 'NZD', 'total' => $total],
    'pcr' => Pcr::build($particulars, $reference, $code),
], $idempotencyKey);
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
#### Gateway Flow
```php
$consent = $client->createGatewaySingleConsent($total, $redirectUri, Pcr::build($particulars, $reference, $code), $idempotencyKey);
```
#### Gateway Flow - Redirect Flow Hint
```php
$consent = $client->createSingleConsent([
    'flow' => ['detail' => [
        'type' => 'gateway',
        'redirect_uri' => $redirectUri,
        'flow_hint' => ['type' => 'redirect', 'bank' => 'PNZ'],
    ]],
    'amount' => ['currency' => 'NZD', 'total' => $total],
    'pcr' => Pcr::build($particulars, $reference, $code),
], $idempotencyKey);
```
#### Gateway Flow - Decoupled Flow Hint
```php
$consent = $client->createSingleConsent([
    'flow' => ['detail' => [
        'type' => 'gateway',
        'redirect_uri' => $redirectUri,
        'flow_hint' => [
            'type' => 'decoupled',
            'bank' => $bank,
            'identifier_type' => $identifierType,
            'identifier_value' => $identifierValue,
        ],
    ]],
    'amount' => ['currency' => 'NZD', 'total' => $total],
    'pcr' => Pcr::build($particulars, $reference, $code),
], $idempotencyKey);
```
#### Redirect Flow
Suitable for most consents.
```php
$consent = $client->createSingleConsent([
    'flow' => ['detail' => ['type' => 'redirect', 'bank' => $bank, 'redirect_uri' => $redirectUri]],
    'amount' => ['currency' => 'NZD', 'total' => $total],
    'pcr' => Pcr::build($particulars, $reference, $code),
], $idempotencyKey);
```
#### Decoupled Flow
This flow type allows better support for mobile by allowing the supply of a mobile number or previous consent ID to identify the customer with their bank.

The customer will receive the consent request directly to their online banking app. This flow does not send the user through a web redirect flow.
```php
$consent = $client->createSingleConsent([
    'flow' => ['detail' => [
        'type' => 'decoupled',
        'bank' => $bank,
        'identifier_type' => $identifierType,
        'identifier_value' => $identifierValue,
        'callback_url' => $callbackUrl,
    ]],
    'amount' => ['currency' => 'NZD', 'total' => $total],
    'pcr' => Pcr::build($particulars, $reference, $code),
], $idempotencyKey);
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
#### Gateway Flow
```php
$consent = $client->createEnduringConsent([
    'flow' => ['detail' => ['type' => 'gateway', 'redirect_uri' => $redirectUri]],
    'maximum_amount_period' => ['currency' => 'NZD', 'total' => $totalPerPeriod],
    'maximum_amount_payment' => ['currency' => 'NZD', 'total' => $totalPerPayment],   // optional per-payment cap
    'from_timestamp' => $startDate,        // ISO 8601, e.g. '2026-10-01T00:00:00+13:00'
    'expiry_timestamp' => $endDate,        // omit for an indefinite consent
    'period' => $period,                   // daily, weekly, fortnightly, monthly, annual
], $idempotencyKey);
```
#### Gateway Flow - Redirect Flow Hint
```php
$consent = $client->createEnduringConsent([
    'flow' => ['detail' => [
        'type' => 'gateway',
        'redirect_uri' => $redirectUri,
        'flow_hint' => ['type' => 'redirect', 'bank' => 'PNZ'],
    ]],
    'maximum_amount_period' => ['currency' => 'NZD', 'total' => $totalPerPeriod],
    'maximum_amount_payment' => ['currency' => 'NZD', 'total' => $totalPerPayment],
    'from_timestamp' => $startDate,
    'expiry_timestamp' => $endDate,
    'period' => $period,
], $idempotencyKey);
```
#### Gateway Flow - Decoupled Flow Hint
```php
$consent = $client->createEnduringConsent([
    'flow' => ['detail' => [
        'type' => 'gateway',
        'redirect_uri' => $redirectUri,
        'flow_hint' => [
            'type' => 'decoupled',
            'bank' => $bank,
            'identifier_type' => $identifierType,
            'identifier_value' => $identifierValue,
        ],
    ]],
    'maximum_amount_period' => ['currency' => 'NZD', 'total' => $totalPerPeriod],
    'maximum_amount_payment' => ['currency' => 'NZD', 'total' => $totalPerPayment],
    'from_timestamp' => $startDate,
    'expiry_timestamp' => $endDate,
    'period' => $period,
], $idempotencyKey);
```
#### Redirect Flow
```php
$consent = $client->createEnduringConsent([
    'flow' => ['detail' => ['type' => 'redirect', 'bank' => $bank, 'redirect_uri' => $redirectUri]],
    'maximum_amount_period' => ['currency' => 'NZD', 'total' => $total],
    'maximum_amount_payment' => ['currency' => 'NZD', 'total' => $totalPerPayment],
    'from_timestamp' => $startDate,
    'expiry_timestamp' => $endDate,
    'period' => $period,
], $idempotencyKey);
```
#### Decoupled Flow
```php
$consent = $client->createEnduringConsent([
    'flow' => ['detail' => [
        'type' => 'decoupled',
        'bank' => $bank,
        'identifier_type' => $identifierType,
        'identifier_value' => $identifierValue,
        'callback_url' => $callbackUrl,
    ]],
    'maximum_amount_period' => ['currency' => 'NZD', 'total' => $total],
    'maximum_amount_payment' => ['currency' => 'NZD', 'total' => $totalPerPayment],
    'from_timestamp' => $startDate,
    'expiry_timestamp' => $endDate,
    'period' => $period,
], $idempotencyKey);
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
$schedule = $client->createFixedRecurringPayment([
    'consent_id' => $consentId,
    'amount' => ['currency' => 'NZD', 'total' => $total],
    'pcr' => Pcr::build($particulars, $reference, $code),
    'start_date' => '2026-11-01',          // optional; defaults to today or the consent's start
    'retry_strategy' => 'same_day',        // optional: none (default) or same_day
], $idempotencyKey);
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
$payment = $client->createEnduringConsentPayment($consentId, $total, Pcr::build($particulars, $reference, $code), $idempotencyKey);
```
#### Raw payload
```php
$payment = $client->createPayment(['consent_id' => $consentId], $idempotencyKey);
```
A `409` with code `BP712` carries no payment ID: a concurrent request on the same consent won the bank submission, so read the consent's payments before retrying.
#### Retrieval
```php
$payment = $client->getPayment($paymentId);
```

### Refunds
How the payment settled decides the refund type. Store the settled payment's `accepted_reason` (`card_network_accepted` or `source_bank_payment_sent`) at payment time, since it decides the path later.
#### Account Number Refund
For a bank-settled (A2A) payment. Moves no money: poll `getRefund()` until it carries `account_number`, show that to the merchant for a manual transfer, and never persist the account number into your own storage.
```php
$refund = $client->createAccountNumberRefund($paymentId);
```
#### Full Refund
For a card-settled payment, refunding the full un-surcharged total through the card network.
```php
$refund = $client->createFullRefund($paymentId, Pcr::build($particulars, $reference, $code));
```
#### Partial Refund
For a card-settled payment. Always use this when a surcharge was applied.
```php
$refund = $client->createPartialRefund($paymentId, Pcr::build($particulars, $reference, $code), $total);
```
#### Retrieval
A created money-moving refund is not necessarily processed: check `status` (`processing`, `completed`, `failed`) and surface `detail.consent_redirect` to the merchant when their bank requires them to authorise the refund.
```php
$refund = $client->getRefund($refundId);
```

### Transactions
For reconciliation. Results are newest first; `page` starts at 1 and `size` is 1-1000 (default 100).
```php
$transactions = $client->getTransactions('2026-09-01T00:00:00+12:00', '2026-09-01T23:59:59+12:00', [
    'bank' => 'BNZ',
    'payment_status' => 'AcceptedSettlementCompleted',
    // 'consent_status' => ..., 'card_network' => ..., 'merchant_id' => ..., 'page' => 1, 'size' => 500,
]);

$totals = $client->getTransactionTotals('2026-09-01', '2026-09-07');   // NZ dates; successful payments only
```

### Subscriptions
Register an HTTPS callback for fixed recurring payment lifecycle events. The signing `secret` is returned exactly once, on creation; store it immediately. Sandbox and production have separate subscriptions and secrets.
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
    http_response_code(400);
    exit;
}

$event = json_decode($rawBody, true);
// event_type, event_id, timestamp, frp_id, consent_id, and payment_id when one exists
```

- Signatures more than five minutes from the current time are rejected by default; `verify()` takes a `toleranceSeconds` argument to adjust this.
- Deliveries are retried up to three more times on a non-2xx or network failure, with exponential backoff. De-duplicate on the `X-Idempotency-Key` header (stable across retries) or the `event_id` in the body.
- Respond with any `2xx` once the event is safely recorded or queued. Do heavy work asynchronously; each attempt has a 30-second timeout.

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
Timeouts are not part of PSR-18, so configure them on the underlying client.

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
- Sandbox is the default environment; production must be opted into explicitly.

If you believe you have found a security issue, contact [sysadmin@blinkpay.co.nz](mailto:sysadmin@blinkpay.co.nz) rather than opening a public issue.

## Dependencies
Runtime: none beyond PHP with `ext-curl` and `ext-json`.

Optional (installed by the application, never by this package):
- `psr/http-client`, `psr/http-factory`, `psr/http-message` plus an implementation, for `Psr18Transport`
- `psr/simple-cache` for `Psr16TokenCache` (Laravel, CakePHP)
- `psr/cache` for `Psr6TokenCache` (Symfony)

Development: PHPUnit, PHPStan, the PSR interface packages and `nyholm/psr7`.
