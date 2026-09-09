<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Enum;

/**
 * Refund statuses. A 201 on creation means processing, not completed.
 */
final class RefundStatus
{
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    private function __construct()
    {
        // Static-only class: not meant to be instantiated.
    }
}
