<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit;

use RuntimeException;

/**
 * Base class for every failure the SDK raises: local validation (status 0),
 * transport failures ({@see Exception\TransportException}), non-2xx API
 * responses (mapped to the subclasses in the Exception namespace by HTTP
 * status) and the outcome exceptions thrown by the await helpers.
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
    public function __construct(
        string $message,
        int $statusCode = 0,
        ?array $responseBody = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
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

    /**
     * The API's BPxxx error code, when the response carried one.
     */
    public function getErrorCode(): ?string
    {
        $code = $this->responseBody['code'] ?? null;

        return is_scalar($code) ? (string) $code : null;
    }
}
