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
     * "METHOD query" of every request
     *
     * @var list<string>
     */
    private array $queries = [];

    /**
     * The answer to a POST when it differs from the one for everything else
     */
    private ?Psr7Response $create = null;

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
     * A client that lists the given award_emoji JSON on GET and answers any POST with the given status (201 by default)
     *
     * @param string $json       The JSON list of awards already on the issue
     * @param int    $postStatus The status a POST is answered with
     *
     * @return self
     */
    public static function awards(string $json, int $postStatus = 201): self
    {
        $client         = new self(new Psr7Response(200, [], $json));
        $client->create = new Psr7Response($postStatus, [], '{}');

        return $client;
    }

    /**
     * The "METHOD query" of every request so far
     *
     * @return list<string>
     */
    public function queries(): array
    {
        return $this->queries;
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
        $this->queries[]  = $request->getMethod() . ' ' . $request->getUri()->getQuery();

        if ($this->create !== null && $request->getMethod() === 'POST') {
            return $this->create;
        }

        if ($this->answer === null) {
            throw new class ('down') extends RuntimeException implements ClientException {
            };
        }

        return $this->answer;
    }
}
