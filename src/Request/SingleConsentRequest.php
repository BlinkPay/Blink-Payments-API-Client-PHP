<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Request;

/**
 * Builds the request body for a single consent. A quick payment uses the
 * same body; see {@see QuickPaymentRequest}.
 */
final class SingleConsentRequest
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>  $flow                     From {@see Flow}.
     * @param string                $totalNzd                 Decimal amount as a string, e.g. "12.50".
     * @param array<string, string> $pcr                      From {@see \BlinkPay\BlinkDebit\Pcr}.
     * @param string|null           $hashedCustomerIdentifier SHA-256 of a per-customer identifier, or null to
     *                                                        omit. Send only a genuinely per-customer value:
     *                                                        hashing a blank one would make every such order
     *                                                        look like one customer to Blink's risk checks.
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
        $payload = [
            'flow' => $flow,
            'amount' => Amount::nzd($totalNzd),
            'pcr' => $pcr,
        ];
        if ($hashedCustomerIdentifier !== null && $hashedCustomerIdentifier !== '') {
            $payload['hashed_customer_identifier'] = $hashedCustomerIdentifier;
        }

        return $payload;
    }
}
