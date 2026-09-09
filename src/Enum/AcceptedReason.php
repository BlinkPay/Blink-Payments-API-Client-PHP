<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Enum;

/**
 * Why a payment reached AcceptedSettlementCompleted. Decides the refund path:
 * account_number for a bank-settled payment, full_refund or partial_refund for
 * a card-settled one.
 */
final class AcceptedReason
{
    public const SOURCE_BANK_PAYMENT_SENT = 'source_bank_payment_sent';
    public const CARD_NETWORK_ACCEPTED = 'card_network_accepted';

    private function __construct()
    {
    }
}
