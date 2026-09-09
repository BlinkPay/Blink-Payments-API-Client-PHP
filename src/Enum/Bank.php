<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Enum;

/**
 * Banks the API recognises. The set changes as banks are onboarded, so the SDK
 * does not reject unknown values locally; use getMeta() for what your account
 * supports today.
 */
final class Bank
{
    public const ASB = 'ASB';
    public const ANZ = 'ANZ';
    public const BNZ = 'BNZ';
    public const WESTPAC = 'Westpac';
    public const KIWIBANK = 'Kiwibank';
    public const NZHL = 'NZHL';
    public const PNZ = 'PNZ';
    public const CARD = 'Card';

    private function __construct()
    {
    }
}
