<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\FrameworkTest\Support;

use BlinkPay\BlinkDebit\HttpTransportInterface;

/**
 * Answers every request with a token response and records what was sent, so
 * a booted container can be exercised end to end without HTTP.
 */
final class RecordingTransport implements HttpTransportInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string, timeout: int}> */
    public array $requests = [];

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body') + ['timeout' => $timeoutSeconds];

        return [
            'status' => 200,
            'body' => '{"access_token":"tok-' . count($this->requests) . '","expires_in":3600,"scope":"view:metadata"}',
            'headers' => [],
        ];
    }
}
