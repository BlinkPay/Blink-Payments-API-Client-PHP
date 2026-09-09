<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Exception;

use BlinkPay\BlinkDebit\BlinkDebitApiException;

/**
 * The consent (or a quick payment's consent) ended Rejected or Revoked, so
 * no payment can be made against it. Thrown by the await helpers.
 */
class ConsentRejectedException extends BlinkDebitApiException
{
}
