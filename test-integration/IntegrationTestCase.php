<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\BlinkDebitClient;
use BlinkPay\BlinkDebit\Enum\Bank;
use BlinkPay\BlinkDebit\Enum\ConsentStatus;
use BlinkPay\BlinkDebit\Enum\IdentifierType;
use BlinkPay\BlinkDebit\Enum\PaymentStatus;
use BlinkPay\BlinkDebit\Enum\Period;
use BlinkPay\BlinkDebit\Env;
use BlinkPay\BlinkDebit\InMemoryTokenCache;
use BlinkPay\BlinkDebit\Pcr;
use BlinkPay\BlinkDebit\Request\EnduringConsentRequest;
use BlinkPay\BlinkDebit\Request\Flow;
use BlinkPay\BlinkDebit\Request\QuickPaymentRequest;
use BlinkPay\BlinkDebit\Request\SingleConsentRequest;
use BlinkPay\BlinkDebit\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the tests that drive the real Blink Debit sandbox, the
 * counterpart of the Java and Node SDKs' integration suites.
 *
 * Credentials come from BLINKPAY_CLIENT_ID and BLINKPAY_CLIENT_SECRET; the
 * tests skip themselves when either is unset, so the suite is harmless on a
 * fork or a workstation without sandbox access. The client is always built
 * for the sandbox: these tests create consents and payments, and a
 * BLINKPAY_DEBIT_URL naming any other host fails the run rather than being
 * followed.
 *
 * The sandbox's PNZ test bank authorises a decoupled consent for the phone
 * number below on its own and settles payments at once, which is what lets
 * the payment, refund and await-helper tests run unattended. Redirect and
 * gateway consents are never authorised, so they are used where a timeout
 * is the expected outcome.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const REDIRECT_URI = 'https://www.blinkpay.co.nz/sample-merchant-return-page';
    protected const CALLBACK_URL = 'https://www.mymerchant.co.nz/callback';

    /** SHA-256 of a sample customer identifier, the same value the Java and Node suites send. */
    protected const CUSTOMER_HASH = '88df3798e32512ac340164f7ed133343d6dcb4888e4a91b03512dedd9800d12e';

    protected const BANK = Bank::PNZ;

    /** PNZ phone number the sandbox authorises decoupled consents for automatically. */
    protected const PHONE_NUMBER = '+64-259531933';

    protected const PARTICULARS = 'particulars';
    protected const CODE = 'code';
    protected const REFERENCE = 'reference';

    /** Seconds (one poll each) allowed for the sandbox's automatic authorisation and settlement. */
    protected const AWAIT_SECONDS = 30;

    /** Seconds (one poll each) allowed for a consent nobody will authorise, so a timeout arrives quickly. */
    protected const TIMEOUT_SECONDS = 2;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private static ?BlinkDebitClient $client = null;

    protected function setUp(): void
    {
        // Blank counts as unset: GitHub Actions passes a secret the run cannot
        // read (a fork or Dependabot pull request) as an empty string.
        if (self::env(Env::CLIENT_ID) === '' || self::env(Env::CLIENT_SECRET) === '') {
            self::markTestSkipped(sprintf(
                'Set %s and %s to run the sandbox integration tests.',
                Env::CLIENT_ID,
                Env::CLIENT_SECRET
            ));
        }

        // The SDK never reads BLINKPAY_DEBIT_URL; the check catches a CI
        // configuration pointing the organisation's variables elsewhere.
        $debitUrl = self::env('BLINKPAY_DEBIT_URL');
        if ($debitUrl !== '' && rtrim($debitUrl, '/') !== BlinkDebitClient::SANDBOX_BASE_URL) {
            self::fail(sprintf(
                'BLINKPAY_DEBIT_URL must be the sandbox (%s): these tests create consents and payments.',
                BlinkDebitClient::SANDBOX_BASE_URL
            ));
        }

        // Guards every test that builds a client from the environment.
        if (!Env::bool(Env::get(Env::SANDBOX), true)) {
            self::fail('BLINKPAY_SANDBOX must be unset or true: these tests create consents and payments.');
        }
    }

    /**
     * The variable's value with surrounding whitespace removed, or an empty
     * string when it is unset.
     */
    private static function env(string $name): string
    {
        return trim((string) Env::get($name));
    }

    /**
     * One client shared by the resource tests, so they fetch one access
     * token between them: the token endpoint is rate limited and the sandbox
     * grants an hour-long token. The OAuth tests build their own clients,
     * because the grant is what they test.
     */
    protected static function client(): BlinkDebitClient
    {
        if (self::$client === null) {
            self::$client = new BlinkDebitClient(
                (string) Env::get(Env::CLIENT_ID),
                (string) Env::get(Env::CLIENT_SECRET),
                true,
                new InMemoryTokenCache()
            );
        }

        return self::$client;
    }

    // ------------------------------------------------------------------
    // Request fixtures
    // ------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    protected static function pcr(): array
    {
        return Pcr::build(self::PARTICULARS, self::CODE, self::REFERENCE);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function redirectFlow(): array
    {
        return Flow::redirect(self::BANK, self::REDIRECT_URI);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decoupledFlow(): array
    {
        return Flow::decoupled(self::BANK, IdentifierType::PHONE_NUMBER, self::PHONE_NUMBER, self::CALLBACK_URL);
    }

    /**
     * @param array<string, mixed> $flow
     *
     * @return array<string, mixed>
     */
    protected static function singleConsentRequest(array $flow, string $totalNzd = '1.25'): array
    {
        return SingleConsentRequest::build($flow, $totalNzd, self::pcr(), self::CUSTOMER_HASH);
    }

    /**
     * @param array<string, mixed> $flow
     *
     * @return array<string, mixed>
     */
    protected static function quickPaymentRequest(array $flow): array
    {
        return QuickPaymentRequest::build($flow, '1.25', self::pcr(), self::CUSTOMER_HASH);
    }

    /**
     * A fortnightly consent from now, capped at $50 per period and per payment.
     *
     * @param array<string, mixed> $flow
     *
     * @return array<string, mixed>
     */
    protected static function enduringConsentRequest(array $flow): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Auckland')))->format(DATE_ATOM);

        return EnduringConsentRequest::build($flow, $now, Period::FORTNIGHTLY, '50.00', null, '50.00', self::CUSTOMER_HASH);
    }

    // ------------------------------------------------------------------
    // Sandbox scenarios shared between test classes
    // ------------------------------------------------------------------

    /**
     * Creates a decoupled single consent and waits for the sandbox to
     * authorise it.
     *
     * @throws BlinkDebitApiException
     */
    protected static function authorisedSingleConsentId(string $totalNzd = '1.25'): string
    {
        $created = self::client()->createSingleConsent(
            self::singleConsentRequest(self::decoupledFlow(), $totalNzd),
            Uuid::v4()
        );
        $consentId = self::stringField($created, 'consent_id');
        self::assertUuid($consentId, 'consent_id');
        self::assertArrayNotHasKey('redirect_uri', $created, 'A decoupled consent has no redirect URI.');

        $consent = self::client()->awaitAuthorisedSingleConsent($consentId, self::AWAIT_SECONDS);
        self::assertSame(ConsentStatus::AUTHORISED, $consent['status'] ?? null);

        return $consentId;
    }

    /**
     * Creates a decoupled enduring consent and waits for the sandbox to
     * authorise it.
     *
     * @throws BlinkDebitApiException
     */
    protected static function authorisedEnduringConsentId(): string
    {
        $created = self::client()->createEnduringConsent(
            self::enduringConsentRequest(self::decoupledFlow()),
            Uuid::v4()
        );
        $consentId = self::stringField($created, 'consent_id');
        self::assertUuid($consentId, 'consent_id');
        self::assertArrayNotHasKey('redirect_uri', $created, 'A decoupled consent has no redirect URI.');

        $consent = self::client()->awaitAuthorisedEnduringConsent($consentId, self::AWAIT_SECONDS);
        self::assertSame(ConsentStatus::AUTHORISED, $consent['status'] ?? null);

        return $consentId;
    }

    /**
     * Debits a freshly authorised single consent and waits for settlement.
     *
     * @throws BlinkDebitApiException
     */
    protected static function settledSingleConsentPaymentId(string $totalNzd = '1.25'): string
    {
        $consentId = self::authorisedSingleConsentId($totalNzd);
        $created = self::client()->createSingleConsentPayment($consentId, Uuid::v4());
        $paymentId = self::stringField($created, 'payment_id');
        self::assertUuid($paymentId, 'payment_id');

        $payment = self::client()->awaitSuccessfulPayment($paymentId, self::AWAIT_SECONDS);
        self::assertSame(PaymentStatus::ACCEPTED_SETTLEMENT_COMPLETED, $payment['status'] ?? null);

        return $paymentId;
    }

    // ------------------------------------------------------------------
    // Assertions
    // ------------------------------------------------------------------

    protected static function assertUuid(string $value, string $label): void
    {
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $value, sprintf('%s is a UUID', $label));
    }

    /**
     * The API echoes the PCR with its keys in its own order, so the
     * comparison is by value, not by position.
     *
     * @param mixed $actual
     */
    protected static function assertPcr($actual): void
    {
        self::assertEquals(self::pcr(), $actual);
    }

    /**
     * Asserts that the exception for a missing resource carries the 404 the
     * API sent, not just the type.
     *
     * @param callable(): mixed $call
     */
    protected static function assertNotFound(callable $call): void
    {
        try {
            $call();
        } catch (BlinkDebitApiException $exception) {
            self::assertSame(404, $exception->getStatusCode(), $exception->getMessage());

            return;
        }

        self::fail('Expected the API to answer 404.');
    }

    /**
     * @param array<string, mixed> $response
     */
    protected static function stringField(array $response, string $key): string
    {
        self::assertArrayHasKey($key, $response);
        self::assertIsString($response[$key]);

        return $response[$key];
    }
}
