<?php

declare(strict_types=1);

namespace Kelpie\Tests\Security;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * A request handler for middleware tests: answers 204 and remembers it ran
 */
final class ApiTokenRecordingHandler implements Handler
{
    /**
     * Whether the handler ran
     */
    private bool $called = false;

    /**
     * @return bool Whether the handler ran
     */
    public function wasCalled(): bool
    {
        return $this->called;
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request): Response // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- the interface requires it
    {
        $this->called = true;

        return new Psr7Response(204);
    }
}
