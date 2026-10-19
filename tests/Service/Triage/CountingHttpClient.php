<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;

/**
 * Answers every request with one canned response and counts the requests
 */
final class CountingHttpClient implements HttpClient
{
    /**
     * How many requests were sent
     */
    private int $calls = 0;

    /**
     * Constructor
     *
     * @param Psr7Response $response The canned response
     */
    public function __construct(
        private readonly Psr7Response $response,
    ) {
    }

    /**
     * How many requests were sent
     *
     * @return int
     */
    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * {@inheritDoc}
     */
    public function sendRequest(Request $request): Psr7Response // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- every request gets the same answer
    {
        ++$this->calls;

        return $this->response;
    }
}
