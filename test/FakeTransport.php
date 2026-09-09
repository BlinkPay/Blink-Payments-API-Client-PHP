<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\HttpTransportInterface;

/**
 * Records every request and replays queued responses, so tests can assert on
 * the exact wire contract without any HTTP.
 */
class FakeTransport implements HttpTransportInterface
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: ?string, timeout: int}> */
    public array $requests = [];

    /** @var array<int, array{status: int, body: string}> */
    private array $responses = [];

    public function queue(int $status, array $body): void
    {
        $this->responses[] = [
            'status' => $status,
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Queues a response with a verbatim body, e.g. the empty body of a 204.
     */
    public function queueRaw(int $status, string $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body];
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeoutSeconds,
        ];

        if ($this->responses === []) {
            throw new \LogicException('FakeTransport has no queued response for ' . $method . ' ' . $url);
        }

        return array_shift($this->responses);
    }

    public function lastRequest(): array
    {
        if ($this->requests === []) {
            throw new \LogicException('FakeTransport received no requests.');
        }

        return $this->requests[count($this->requests) - 1];
    }
}
