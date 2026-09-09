<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\Exception\TransportException;
use BlinkPay\BlinkDebit\HttpTransportInterface;

/**
 * Records every request and replays queued responses, so tests can assert on
 * the exact wire contract without any HTTP.
 */
class FakeTransport implements HttpTransportInterface
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: ?string, timeout: int}> */
    public array $requests = [];

    /** @var array<int, array{status: int, body: string, headers: array<string, string>}|TransportException> */
    private array $responses = [];

    /**
     * @param array<string, string> $headers Response headers keyed by lower-case name.
     */
    public function queue(int $status, array $body, array $headers = []): void
    {
        $this->responses[] = [
            'status' => $status,
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
            'headers' => $headers,
        ];
    }

    /**
     * Queues a transport failure (no HTTP response at all).
     */
    public function queueFailure(string $message = 'connection reset'): void
    {
        $this->responses[] = new TransportException('The Blink Debit API could not be reached: ' . $message);
    }

    /**
     * Queues a response with a verbatim body, e.g. the empty body of a 204.
     */
    public function queueRaw(int $status, string $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body, 'headers' => []];
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

        $response = array_shift($this->responses);
        if ($response instanceof TransportException) {
            throw $response;
        }

        return $response;
    }

    /**
     * URLs of every request sent, in order, with the host stripped.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(static function (array $request): string {
            return (string) preg_replace('#^https://[^/]+#', '', $request['url']);
        }, $this->requests);
    }

    public function lastRequest(): array
    {
        if ($this->requests === []) {
            throw new \LogicException('FakeTransport received no requests.');
        }

        return $this->requests[count($this->requests) - 1];
    }
}
