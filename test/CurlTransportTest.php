<?php

declare(strict_types=1);

namespace BlinkPay\BlinkDebit\Test;

use BlinkPay\BlinkDebit\CurlTransport;
use BlinkPay\BlinkDebit\Exception\TransportException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Drives the real ext-curl transport against a closed local port and against a
 * short-lived socket server, so the whole send path — including the split of
 * the inline header block from the body — runs under each PHP version in the
 * CI matrix and a curl deprecation surfaces in the test output rather than in
 * production logs.
 */
class CurlTransportTest extends TestCase
{
    public function testUnreachableHostRaisesTransportException(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The Blink Debit API could not be reached: ');

        // Port 9 (discard) is closed on a developer machine and on the CI runners.
        (new CurlTransport())->send('GET', 'http://127.0.0.1:9/meta', [], null, 2);
    }

    public function testHeadersAreSeparatedFromTheBody(): void
    {
        $response = $this->sendAgainstRawResponse(
            "HTTP/1.1 201 Created\r\n"
            . "Content-Type: application/json\r\n"
            . "X-Mixed-Case: Value\r\n"
            . "Content-Length: 14\r\n"
            . "\r\n"
            . '{"payment":1}' . "\n"
        );

        $this->assertSame(201, $response['status']);
        $this->assertSame('{"payment":1}' . "\n", $response['body']);
        $this->assertSame('application/json', $response['headers']['content-type']);
        // Names are lower-cased so the client can look them up without guessing the casing.
        $this->assertSame('Value', $response['headers']['x-mixed-case']);
        // The status line carries no colon and must not become a header.
        $this->assertArrayNotHasKey('http/1.1 201 created', $response['headers']);
    }

    public function testRetryAfterIsReadFromAResponseWithNoBody(): void
    {
        $response = $this->sendAgainstRawResponse(
            "HTTP/1.1 429 Too Many Requests\r\nRetry-After: 42\r\nContent-Length: 0\r\n\r\n"
        );

        $this->assertSame(429, $response['status']);
        $this->assertSame('', $response['body']);
        $this->assertSame('42', $response['headers']['retry-after']);
    }

    /**
     * libcurl accepts the bare-LF header terminators some origins and proxies
     * still emit, so the parser has to as well or the client silently loses
     * Retry-After and backs off on its own schedule.
     */
    public function testHeadersTerminatedByBareLineFeedsAreParsed(): void
    {
        $response = $this->sendAgainstRawResponse(
            "HTTP/1.1 429 Too Many Requests\nRetry-After: 11\nContent-Length: 2\n\nok"
        );

        $this->assertSame(429, $response['status']);
        $this->assertSame('ok', $response['body']);
        $this->assertSame('11', $response['headers']['retry-after']);
        $this->assertSame('2', $response['headers']['content-length']);
    }

    public function testAnInterimResponseDoesNotDisplaceTheRealHeaders(): void
    {
        $response = $this->sendAgainstRawResponse(
            "HTTP/1.1 100 Continue\r\nRetry-After: 999\r\n\r\n"
            . "HTTP/1.1 429 Too Many Requests\r\nRetry-After: 7\r\nContent-Length: 2\r\n\r\nok"
        );

        $this->assertSame(429, $response['status']);
        $this->assertSame('ok', $response['body']);
        // Both blocks are inside the header size, so the real response has to win.
        $this->assertSame('7', $response['headers']['retry-after']);
    }

    /**
     * Serves one byte-exact HTTP response from a short-lived PHP process and
     * returns what the transport made of it. Writing the response as raw bytes
     * keeps the line terminators under the test's control, which a real web
     * server would normalise away.
     *
     * @param string $rawResponse Response bytes, status line included.
     *
     * @return array{status: int, body: string, headers?: array<string, string>}
     */
    private function sendAgainstRawResponse(string $rawResponse): array
    {
        // Bound to port 0 so concurrent jobs on a CI runner cannot collide; the
        // child reports the port it was given on stdout.
        $server = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, $errstr);
    exit(1);
}
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1) . "\n");
$client = stream_socket_accept($server, 10);
if ($client === false) {
    exit(1);
}
fread($client, 8192);
fwrite($client, base64_decode($argv[1]));
fclose($client);
PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $server, base64_encode($rawResponse)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the stub HTTP server.');
        }

        try {
            $port = fgets($pipes[1]);
            if ($port === false || trim($port) === '') {
                throw new RuntimeException(sprintf(
                    'The stub HTTP server did not report a port: %s',
                    (string) stream_get_contents($pipes[2])
                ));
            }

            return (new CurlTransport())->send('GET', 'http://127.0.0.1:' . trim($port) . '/meta', [], null, 5);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }
}
