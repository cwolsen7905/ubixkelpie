<?php

declare(strict_types=1);

namespace Kelpie\Controller\Gitlab;

use Kelpie\Service\Autofix\EnqueueService;
use Kelpie\Service\Autofix\TriageReplyService;
use Kelpie\Service\Gitlab\IssueOpenWebhookService;
use Kelpie\Service\Gitlab\NoteOnMrWebhookService;
use Kelpie\Service\Gitlab\ProposalReactionWebhookService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use TypeError;
use Ubix\Controller\AbstractController as Controller;
use Ubix\Enum\StatusCode;
use Ubix\Exception\DtoException;
use Ubix\Renderer\TemplateRenderer;
use Ubix\Service\Ci\ApprovalWebhookService;
use Ubix\Service\JsonService;

/**
 * GitLab webhook receiver for Kelpie
 *
 * Verifies X-Gitlab-Token against GITLAB_WEBHOOK_SECRET, dispatches by
 * X-Gitlab-Event header. Note, Pipeline and Job hooks also feed the autofix event filter, which writes a job row for `kelpie:work`; the webhook does no other work. Merge Request and Emoji hooks are handed to uBixCore's
 * ApprovalWebhookService, which re-runs the MR's sign-off job on an approval or a thumbs-up. An Emoji Hook also goes to
 * ProposalReactionWebhookService, which queues a thumbs on one of Kelpie's proposals, and a Note Hook that starts with
 * "Triage:" goes to TriageReplyService instead of the ordinary autofix filter.
 *
 * @see \Kelpie\Tests\Controller\Gitlab\GitlabWebhookControllerTest PHPUnit test case
 */
final class GitlabWebhookController extends Controller
{
    /**
     * Constructor
     *
     * @param Logger                         $logger                  Logger
     * @param TemplateRenderer               $templateRenderer        Unused; required by parent
     * @param JsonService                    $jsonService             JSON service from parent
     * @param NoteOnMrWebhookService         $noteOnMrWebhookService  Handles Note Hook on MRs
     * @param IssueOpenWebhookService        $issueOpenWebhookService Handles Issue Hook open events
     * @param ApprovalWebhookService         $approvalWebhookService  Re-runs the sign-off job on an approval (uBixCore)
     * @param EnqueueService                 $enqueueService          Filters Note, Pipeline and Job hooks into autofix jobs and writes them
     * @param ProposalReactionWebhookService $reactionService         Queues a thumbs-up or thumbs-down on a proposal
     * @param TriageReplyService             $triageReplyService      Queues a re-run for a "Triage:" reply from the author or a maintainer
     * @param string                         $secret                  The webhook secret token
     */
    public function __construct(
        Logger $logger,
        TemplateRenderer $templateRenderer,
        JsonService $jsonService,
        private readonly NoteOnMrWebhookService $noteOnMrWebhookService,
        private readonly IssueOpenWebhookService $issueOpenWebhookService,
        private readonly ApprovalWebhookService $approvalWebhookService,
        private readonly EnqueueService $enqueueService,
        private readonly ProposalReactionWebhookService $reactionService,
        private readonly TriageReplyService $triageReplyService,
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

        $body  = (string) $request->getBody();
        $event = $request->getHeaderLine('X-Gitlab-Event');

        return $this->dispatch($request, $body, $event, $response);
    }

    /**
     * Decode and dispatch the payload to the right handler
     *
     * @param Request  $request  The webhook delivery
     * @param string   $body     The raw payload
     * @param string   $event    The X-Gitlab-Event header value
     * @param Response $response The PSR response
     *
     * @return Response
     */
    private function dispatch(Request $request, string $body, string $event, Response $response): Response
    {
        $eventLower = strtolower($event);

        try {
            $payload = $this->jsonService->decode($body);
        } catch (DtoException $e) {
            $this->logger->error('GitLab webhook: not JSON', ['error' => $e->getMessage()]);

            return $this->renderJson($response, ['outcome' => 'error: invalid JSON'], StatusCode::OK);
        } catch (TypeError $e) {
            $this->logger->error('GitLab webhook: scalar body', ['error' => $e->getMessage()]);

            return $this->renderJson($response, ['outcome' => 'ignored: scalar body'], StatusCode::OK);
        }

        if ($eventLower === 'emoji hook') {
            return $this->emoji($request, $body, $payload, $response);
        }
        if ($eventLower === 'merge request hook') {
            return $this->approval($request, $body, $response);
        }

        return match ($eventLower) {
            'note hook' => $this->note($payload, $response, $event),
            'pipeline hook', 'job hook' => $this->pipeline($payload, $response, $event),
            'issue hook' => $this->issue($payload, $response),
            default => $this->renderJson($response, ['outcome' => 'ignored: not handled'], StatusCode::OK),
        };
    }

    /**
     * Dispatch to the Note Hook handler
     *
     * @param array<int|string, mixed> $payload  The decoded payload
     * @param Response                 $response The PSR response
     * @param string                   $event    The X-Gitlab-Event header value
     *
     * @return Response
     */
    private function note(array $payload, Response $response, string $event): Response
    {
        $result = $this->noteOnMrWebhookService->handle($payload);

        $this->logger->info('GitLab webhook: note', ['result' => $result]);

        // A "Triage:" note is a command: only its author's standing decides, never the ordinary filter
        $autofix = $this->triageReplyService->isCommand($payload) ? $this->triageReplyService->handle($payload) : $this->enqueueService->handle($event, $payload);

        return $this->renderJson($response, ['outcome' => $result, 'autofix' => $autofix], StatusCode::OK);
    }

    /**
     * Dispatch to the autofix filter for a Pipeline or Job Hook
     *
     * @param array<int|string, mixed> $payload  The decoded payload
     * @param Response                 $response The PSR response
     * @param string                   $event    The X-Gitlab-Event header value
     *
     * @return Response
     */
    private function pipeline(array $payload, Response $response, string $event): Response
    {
        $autofix = $this->enqueueService->handle($event, $payload);

        return $this->renderJson($response, ['outcome' => $autofix, 'autofix' => $autofix], StatusCode::OK);
    }

    /**
     * Dispatch to the Issue Hook handler
     *
     * @param array<int|string, mixed> $payload  The decoded payload
     * @param Response                 $response The PSR response
     *
     * @return Response
     */
    private function issue(array $payload, Response $response): Response
    {
        $result = $this->issueOpenWebhookService->handle($payload);

        $this->logger->info('GitLab webhook: issue', ['result' => $result]);

        return $this->renderJson($response, ['outcome' => $result], StatusCode::OK);
    }

    /**
     * Hand an Emoji Hook to the approval service, and to the proposal reaction service
     *
     * The approval service keeps its own answer and status; the reaction service only adds what it queued.
     *
     * @param Request                  $request  The webhook delivery
     * @param string                   $body     The raw payload
     * @param array<int|string, mixed> $payload  The decoded payload
     * @param Response                 $response The PSR response
     *
     * @return Response
     */
    private function emoji(Request $request, string $body, array $payload, Response $response): Response
    {
        $approval = $this->approvalWebhookService->handle($request->getHeaderLine('X-Gitlab-Token'), $body);
        if ($approval['status'] === 401) {
            return $this->renderJson($response, ['outcome' => $approval['outcome']], StatusCode::UNAUTHORIZED);
        }

        $reaction = $this->reactionService->handle($payload);

        $this->logger->info('GitLab webhook: emoji', ['approval' => $approval['outcome'], 'reaction' => $reaction]);

        return $this->renderJson($response, ['outcome' => $approval['outcome'], 'reaction' => $reaction], StatusCode::OK);
    }

    /**
     * Hand a Merge Request or Emoji Hook to the approval service
     *
     * The service answers 200 for everything but a wrong secret, GitLab-side errors included,
     * so GitLab never disables the hook.
     *
     * @param Request  $request  The webhook delivery
     * @param string   $body     The raw payload
     * @param Response $response The PSR response
     *
     * @return Response
     */
    private function approval(Request $request, string $body, Response $response): Response
    {
        $result = $this->approvalWebhookService->handle($request->getHeaderLine('X-Gitlab-Token'), $body);

        $this->logger->info('GitLab webhook: approval', ['result' => $result['outcome']]);

        return $this->renderJson($response, ['outcome' => $result['outcome']], $result['status'] === 401 ? StatusCode::UNAUTHORIZED : StatusCode::OK);
    }
}
