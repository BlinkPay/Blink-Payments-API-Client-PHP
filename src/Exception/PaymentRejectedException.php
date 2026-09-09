<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * The payment ended Rejected: the bank declined it and no funds moved.
 * Thrown by awaitSuccessfulPayment() and awaitSuccessfulQuickPayment().
 */
class PaymentRejectedException extends BlinkDebitApiException
{
}
