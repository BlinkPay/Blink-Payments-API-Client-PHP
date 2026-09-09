<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Request;

use BlinkPay\BlinkDebit\Validation;

/**
 * Builds the request body for an enduring (recurring) consent.
 */
final class EnduringConsentRequest
{
    private function __construct()
    {
        // Static-only class: not meant to be instantiated.
    }

    /**
     * @param array<string, mixed> $flow                     From {@see Flow}.
     * @param string               $fromTimestamp            ISO 8601 date-time the consent starts, e.g. "2026-10-01T00:00:00+13:00".
     * @param string               $period                   One of the {@see \BlinkPay\BlinkDebit\Enum\Period} constants.
     * @param string               $maximumAmountPeriodNzd   Cap on the total debited per period, e.g. "50.00".
     * @param string|null          $expiryTimestamp          ISO 8601 date-time, or null for an indefinite consent. Must
     *                                                       not fall on the same NZ calendar date as $fromTimestamp
     *                                                       (400 BP283).
     * @param string|null          $maximumAmountPaymentNzd  Optional cap per individual payment.
     * @param string|null          $hashedCustomerIdentifier SHA-256 of a per-customer identifier, or null to omit.
     *
     * @return array<string, mixed>
     *
     * @throws \BlinkPay\BlinkDebit\BlinkDebitApiException When an amount is malformed or a required value is blank.
     */
    public static function build(
        array $flow,
        string $fromTimestamp,
        string $period,
        string $maximumAmountPeriodNzd,
        ?string $expiryTimestamp = null,
        ?string $maximumAmountPaymentNzd = null,
        ?string $hashedCustomerIdentifier = null
    ): array {
        $payload = [
            'flow' => $flow,
            'from_timestamp' => Validation::nonEmpty($fromTimestamp, 'from timestamp'),
            'period' => Validation::nonEmpty($period, 'period'),
            'maximum_amount_period' => Amount::nzd($maximumAmountPeriodNzd),
        ];
        if ($expiryTimestamp !== null) {
            $payload['expiry_timestamp'] = Validation::nonEmpty($expiryTimestamp, 'expiry timestamp');
        }
        if ($maximumAmountPaymentNzd !== null) {
            $payload['maximum_amount_payment'] = Amount::nzd($maximumAmountPaymentNzd);
        }
        if ($hashedCustomerIdentifier !== null && $hashedCustomerIdentifier !== '') {
            $payload['hashed_customer_identifier'] = $hashedCustomerIdentifier;
        }

        return $payload;
    }
}
