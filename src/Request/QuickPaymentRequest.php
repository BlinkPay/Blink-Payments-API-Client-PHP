<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Request;

/**
 * Builds the request body for a quick payment (a single consent that is
 * debited automatically once authorised). The body is identical to a single
 * consent's.
 */
final class QuickPaymentRequest
{
    private function __construct()
    {
        // Static-only class: not meant to be instantiated.
    }

    /**
     * @param array<string, mixed>  $flow From {@see Flow}.
     * @param array<string, string> $pcr  From {@see \BlinkPay\BlinkDebit\Pcr}.
     *
     * @return array<string, mixed>
     *
     * @throws \BlinkPay\BlinkDebit\BlinkDebitApiException When the amount is malformed.
     */
    public static function build(
        array $flow,
        string $totalNzd,
        array $pcr,
        ?string $hashedCustomerIdentifier = null
    ): array {
        return SingleConsentRequest::build($flow, $totalNzd, $pcr, $hashedCustomerIdentifier);
    }
}
