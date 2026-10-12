<?php

declare(strict_types=1);

namespace Kelpie\Controller\Gitlab;

use Kelpie\Service\Gitlab\IssueOpenWebhookService;
use Kelpie\Service\Gitlab\NoteOnMrWebhookService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Controller\AbstractController as Controller;
use Ubix\Enum\StatusCode;
use Ubix\Exception\DtoException;
use Ubix\Renderer\TemplateRenderer;
use Ubix\Service\JsonService;

/**
 * GitLab webhook receiver for Kelpie
 *
 * Verifies X-Gitlab-Token against GITLAB_WEBHOOK_SECRET, dispatches by
 * X-Gitlab-Event header. 👍 (Emoji Hook) is not handled here — that goes
 * upstream in uBixOps.
 *
 * @see \Kelpie\Tests\Controller\Gitlab\GitlabWebhookControllerTest PHPUnit test case
 */
final class GitlabWebhookController extends Controller
{
    /**
     * Constructor
     *
     * @param Logger                  $logger                  Logger
     * @param TemplateRenderer        $templateRenderer        Unused; required by parent
     * @param JsonService             $jsonService             JSON service from parent
     * @param NoteOnMrWebhookService  $noteOnMrWebhookService  Handles Note Hook on MRs
     * @param IssueOpenWebhookService $issueOpenWebhookService Handles Issue Hook open events
     * @param string                  $secret                  The webhook secret token
     */
    public function __construct(
        Logger $logger,
        TemplateRenderer $templateRenderer,
        JsonService $jsonService,
        private readonly NoteOnMrWebhookService $noteOnMrWebhookService,
        private readonly IssueOpenWebhookService $issueOpenWebhookService,
        private readonly string $secret,
    ) {
        parent::__construct($logger, $templateRenderer, $jsonService);
    }

    /**
     * POST /gitlab/webhook
     *
     * @param Request  $request  The webhook delivery
     * @param Response $response The response
     *
     * @return Response 401 for a wrong secret, otherwise 200 with what happened
     */
    public function webhook(Request $request, Response $response): Response // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Slim passes the request to every action
    {
        $secretHeader = $request->getHeaderLine('X-Gitlab-Token');

        if ($this->secret === '' || !hash_equals($this->secret, $secretHeader)) {
            return $this->renderJson($response, ['outcome' => 'rejected: webhook secret does not match'], StatusCode::UNAUTHORIZED);
        }

        $body       = (string) $request->getBody();
        $event      = $request->getHeaderLine('X-Gitlab-Event');
        $eventLower = strtolower($event);

        try {
            $payload = $this->jsonService->decode($body);
        } catch (DtoException $e) {
            $this->logger->error('GitLab webhook: not JSON', ['error' => $e->getMessage()]);

            return $this->renderJson($response, ['outcome' => 'error: invalid JSON'], StatusCode::OK);
        }

        return match ($eventLower) {
            'note hook' => $this->handleNote($payload, $response),
            'issue hook' => $this->handleIssue($payload, $response),
            default => $this->renderJson($response, ['outcome' => 'ignored: not handled'], StatusCode::OK),
        };
    }

    /**
     * Handle a Note Hook payload
     *
     * Only MR notes are handled; issue/wiki comments are ignored.
     *
     * @param array<int|string, mixed> $payload  The decoded payload
     * @param Response                 $response The PSR response
     *
     * @return Response
     */
    private function handleNote(array $payload, Response $response): Response
    {
        $objectAttributes = is_array($payload['object_attributes'] ?? null) ? $payload['object_attributes'] : [];

        $noteableType = $objectAttributes['noteable_type'] ?? '';

        if ($noteableType !== 'MergeRequest') {
            return $this->renderJson($response, ['outcome' => 'ignored: not an MR note'], StatusCode::OK);
        }

        $result = $this->noteOnMrWebhookService->handle($payload);

        $this->logger->info('GitLab webhook: note on MR', ['result' => $result]);

        return $this->renderJson($response, ['outcome' => $result], StatusCode::OK);
    }

    /**
     * Handle an Issue Hook payload
     *
     * Only open actions are handled.
     *
     * @param array<int|string, mixed> $payload  The decoded payload
     * @param Response                 $response The PSR response
     *
     * @return Response
     */
    private function handleIssue(array $payload, Response $response): Response
    {
        $objectAttributes = is_array($payload['object_attributes'] ?? null) ? $payload['object_attributes'] : [];

        $action = $objectAttributes['action'] ?? '';

        if ($action !== 'open') {
            return $this->renderJson($response, ['outcome' => 'ignored: not an open event'], StatusCode::OK);
        }

        $result = $this->issueOpenWebhookService->handle($payload);

        $this->logger->info('GitLab webhook: issue open', ['result' => $result]);

        return $this->renderJson($response, ['outcome' => $result], StatusCode::OK);
    }
}
