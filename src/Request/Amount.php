<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Request;

use BlinkPay\BlinkDebit\Validation;

/**
 * Builds the API's amount object. Only NZD is supported.
 */
final class Amount
{
    private function __construct()
    {
        // Static-only class: not meant to be instantiated.
    }

    /**
     * @param string $total Decimal string with one or two decimals, e.g. "12.50".
     *
     * @return array{currency: string, total: string}
     *
     * @throws \BlinkPay\BlinkDebit\BlinkDebitApiException When the total is malformed.
     */
    public static function nzd(string $total): array
    {
        return [
            'currency' => 'NZD',
            'total' => Validation::amount($total, 'amount'),
        ];
    }
}
