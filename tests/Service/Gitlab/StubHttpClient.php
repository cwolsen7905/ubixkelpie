<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Gitlab;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use RuntimeException;

/**
 * Answers every request with one canned response (or throws), and records "METHOD path" of each
 */
final class StubHttpClient implements HttpClient
{
    /**
     * "METHOD path" of every request
     *
     * @var list<string>
     */
    private array $requests = [];

    /**
     * Constructor
     *
     * @param ?Psr7Response $answer The response; null makes the client throw
     */
    public function __construct(
        private readonly ?Psr7Response $answer,
    ) {
    }

    /**
     * A client that answers 200 with a member of the given access level, or 404 for null
     *
     * @param ?int $level The access level
     *
     * @return self
     */
    public static function member(?int $level): self
    {
        return new self($level === null ? new Psr7Response(404, [], '{}') : new Psr7Response(200, [], '{"access_level":' . $level . '}'));
    }

    /**
     * The "METHOD path" of every request so far
     *
     * @return list<string>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException When built without an answer
     */
    public function sendRequest(Request $request): Psr7Response
    {
        $this->requests[] = $request->getMethod() . ' ' . $request->getUri()->getPath();

        if ($this->answer === null) {
            throw new class ('down') extends RuntimeException implements ClientException {
            };
        }

        return $this->answer;
    }
}
