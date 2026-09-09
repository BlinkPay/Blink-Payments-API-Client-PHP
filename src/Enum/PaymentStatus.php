<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Enum;

/**
 * Payment statuses. Only AcceptedSettlementCompleted means funds left the
 * payer's bank (or, for a card payment, the card network accepted the charge;
 * see AcceptedReason). Rejected is terminal; the other two are in flight.
 */
final class PaymentStatus
{
    public const PENDING = 'Pending';
    public const ACCEPTED_SETTLEMENT_IN_PROCESS = 'AcceptedSettlementInProcess';
    public const ACCEPTED_SETTLEMENT_COMPLETED = 'AcceptedSettlementCompleted';
    public const REJECTED = 'Rejected';

    private function __construct()
    {
    }
}
