<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Request;

use BlinkPay\BlinkDebit\Validation;

/**
 * Builds the request body for a fixed recurring payment schedule against an
 * authorised enduring consent.
 */
final class FixedRecurringPaymentRequest
{
    private function __construct()
    {
    }

    /**
     * @param string                $consentId     The authorised enduring consent.
     * @param string                $totalNzd      Amount of every scheduled payment, within the consent's caps.
     * @param array<string, string> $pcr           From {@see \BlinkPay\BlinkDebit\Pcr}.
     * @param string|null           $startDate     ISO 8601 date in NZ time, today or later; null defaults to the
     *                                             later of today and the consent's start.
     * @param string|null           $retryStrategy One of the {@see \BlinkPay\BlinkDebit\Enum\RetryStrategy} constants,
     *                                             or null for the API default (none).
     *
     * @return array<string, mixed>
     *
     * @throws \BlinkPay\BlinkDebit\BlinkDebitApiException When the consent ID is not a UUID or the amount is malformed.
     */
    public static function build(
        string $consentId,
        string $totalNzd,
        array $pcr,
        ?string $startDate = null,
        ?string $retryStrategy = null
    ): array {
        $payload = [
            'consent_id' => Validation::uuid($consentId, 'consent ID'),
            'amount' => Amount::nzd($totalNzd),
            'pcr' => $pcr,
        ];
        if ($startDate !== null) {
            $payload['start_date'] = Validation::nonEmpty($startDate, 'start date');
        }
        if ($retryStrategy !== null) {
            $payload['retry_strategy'] = Validation::nonEmpty($retryStrategy, 'retry strategy');
        }

        return $payload;
    }
}
