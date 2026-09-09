<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * HTTP 404: no consent, payment, refund, schedule or subscription with that ID.
 */
class ResourceNotFoundException extends BlinkDebitApiException
{
}
