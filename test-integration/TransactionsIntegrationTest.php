<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\IntegrationTest;

use BlinkPay\BlinkDebit\BlinkDebitApiException;
use BlinkPay\BlinkDebit\Uuid;

/**
 * The reporting endpoints. Each test first creates the data it then expects
 * to find, so it depends on neither test order nor the other SDKs' runs.
 * Both reports reflect a write immediately; rows are listed oldest first,
 * so a narrow window keeps a fresh row on the first page.
 */
class TransactionsIntegrationTest extends IntegrationTestCase
{
    private const NZ = 'Pacific/Auckland';

    public function testTransactionsAreListedForAWindow(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::NZ));
        // Two consents of our own, so the ordering check below has rows to
        // compare even when this class runs on its own.
        $consentIds = [];
        for ($i = 0; $i < 2; $i++) {
            $created = self::client()->createGatewaySingleConsent('1.25', self::REDIRECT_URI, self::pcr(), Uuid::v4());
            $consentIds[] = self::stringField($created, 'consent_id');
        }

        $transactions = self::client()->getTransactions(
            $now->modify('-2 minutes')->format(DATE_ATOM),
            $now->modify('+1 hour')->format(DATE_ATOM),
            ['size' => 1000]
        );

        $this->assertLessThan(1000, count($transactions), 'The window must fit on one page for the checks below.');
        $listedConsentIds = array_column($transactions, 'consent_id');
        foreach ($consentIds as $consentId) {
            $this->assertContains($consentId, $listedConsentIds);
        }

        // Oldest first, as the getTransactions() docblock says: the consent
        // rows' creation times never go backwards down the page.
        $consentRows = array_filter($transactions, static function (array $row): bool {
            return ($row['resource'] ?? null) === 'consent';
        });
        $creationTimes = array_map('strtotime', array_column($consentRows, 'consent_creation_timestamp'));
        $sorted = $creationTimes;
        sort($sorted);
        $this->assertSame($sorted, $creationTimes);

        foreach ($transactions as $transaction) {
            // One flat row per consent, payment or refund.
            $this->assertIsArray($transaction);
            $this->assertContains($transaction['resource'] ?? null, ['consent', 'payment', 'refund']);
            $this->assertArrayHasKey('consent_id', $transaction);
            $this->assertArrayHasKey('merchant_id', $transaction);
        }

        foreach ($consentIds as $consentId) {
            self::client()->revokeSingleConsent($consentId);
        }
    }

    public function testWindowOlderThanTwentyFourMonthsIsRejectedWithItsErrorCode(): void
    {
        try {
            self::client()->getTransactions('2000-01-01T00:00:00+13:00', '2000-01-02T00:00:00+13:00');
            $this->fail('The API limits the window to the last 24 months.');
        } catch (BlinkDebitApiException $exception) {
            $this->assertSame(400, $exception->getStatusCode());
            $this->assertSame('BP271', $exception->getErrorCode());
        }
    }

    public function testTransactionTotalsCountANewlySettledPayment(): void
    {
        // A window from yesterday to the current day on each read, so a
        // settlement that crosses midnight NZ time is still inside it.
        $timezone = new \DateTimeZone(self::NZ);
        $yesterday = (new \DateTimeImmutable('yesterday', $timezone))->format('Y-m-d');
        $before = self::client()->getTransactionTotals(
            $yesterday,
            (new \DateTimeImmutable('now', $timezone))->format('Y-m-d')
        );
        $volumeBefore = (int) ($before['NZD']['total_volume'] ?? 0);

        self::settledSingleConsentPaymentId();

        $after = self::client()->getTransactionTotals(
            $yesterday,
            (new \DateTimeImmutable('now', $timezone))->format('Y-m-d')
        );
        $this->assertArrayHasKey('NZD', $after);
        // At least one more: the other SDKs' runs may have settled payments too.
        $this->assertGreaterThan($volumeBefore, (int) ($after['NZD']['total_volume'] ?? 0));
        $this->assertArrayHasKey('total_value', $after['NZD']);
        $this->assertNotEmpty($after['NZD']['payments_by_date'] ?? []);
    }
}
