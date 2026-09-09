<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * The payment did not reach AcceptedSettlementCompleted within the caller's
 * wait budget. It may still settle later: keep polling getPayment() or wait
 * for the webhook rather than treating this as a failure.
 */
class PaymentTimeoutException extends BlinkDebitApiException
{
}
