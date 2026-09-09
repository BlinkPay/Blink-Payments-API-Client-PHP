<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Enum;

/**
 * Refund types. account_number moves no money; the other two are money-
 * transfer refunds.
 */
final class RefundType
{
    public const ACCOUNT_NUMBER = 'account_number';
    public const FULL_REFUND = 'full_refund';
    public const PARTIAL_REFUND = 'partial_refund';

    private function __construct()
    {
        // Static-only class: not meant to be instantiated.
    }
}
