<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

use RuntimeException;

/**
 * Raised for transport failures and non-2xx Blink Debit API responses.
 *
 * The message is safe to log and show to a merchant-facing admin screen:
 * it carries the API's own error message and status code, never credentials,
 * tokens or request bodies.
 */
class BlinkDebitApiException extends RuntimeException
{
    /** @var int HTTP status code, or 0 for transport-level failures. */
    private int $statusCode;

    /** @var array<string, mixed>|null Decoded error body, when one was returned. */
    private ?array $responseBody;

    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(string $message, int $statusCode = 0, ?array $responseBody = null)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
