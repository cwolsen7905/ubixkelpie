<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\GateServer;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use RuntimeException;

/**
 * An HTTP client whose every request is served by a real GateServer in this process, over a socket pair
 *
 * The request is written whole before the server reads it, so keep checkouts small (a few kilobytes).
 */
final class LoopbackGateHttp implements HttpClient
{
    /**
     * Constructor
     *
     * @param GateServer $server  The server
     * @param string     $workDir Its work directory
     */
    public function __construct(
        private readonly GateServer $server,
        private readonly string $workDir,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException When no socket pair can be made
     */
    public function sendRequest(Request $request): Response
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('No socket pair.');
        }
        [$client, $server] = $pair;

        $body = (string) $request->getBody();
        $head = implode("\r\n", [
            $request->getMethod() . ' ' . $request->getUri()->getPath() . ' HTTP/1.1',
            'Content-Length: ' . strlen($body),
            'X-Kelpie-Gate-Steps: ' . $request->getHeaderLine('X-Kelpie-Gate-Steps'),
            '',
            '',
        ]);
        self::write($client, $head . $body);

        $this->server->handle($server, $this->workDir, $this->workDir . '/ready', 1048576);
        fclose($server);
        $raw = (string) stream_get_contents($client);
        fclose($client);

        [$headers, $content] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        preg_match('~^HTTP/1\.1 (\d{3})~', $headers, $match);

        return new Psr7Response((int) ($match[1] ?? 500), [], $content);
    }

    /**
     * Write raw bytes to a socket, all of them
     *
     * @param mixed  $socket The socket (a resource)
     * @param string $data   The bytes
     *
     * @throws RuntimeException When the socket takes nothing
     *
     * @return void
     */
    public static function write(mixed $socket, string $data): void
    {
        while ($data !== '' && is_resource($socket)) {
            $sent = stream_socket_sendto($socket, $data);
            if ($sent <= 0) {
                throw new RuntimeException('The socket took nothing.');
            }
            $data = substr($data, $sent);
        }
    }
}
