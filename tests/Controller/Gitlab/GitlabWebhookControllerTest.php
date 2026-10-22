<?php

declare(strict_types=1);

namespace Kelpie\Tests\Controller\Gitlab;

use Kelpie\Controller\Gitlab\GitlabWebhookController;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\HttpClient\ApprovalRetryHttpClient;
use Kelpie\Policy\EventFilter;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\EnqueueService;
use Kelpie\Service\Autofix\TriageReplyService;
use Kelpie\Service\Gitlab\IssueAwardEmojiService;
use Kelpie\Service\Gitlab\IssueOpenWebhookService;
use Kelpie\Service\Gitlab\MemberAccessReaderService;
use Kelpie\Service\Gitlab\NoteOnMrWebhookService;
use Kelpie\Service\Gitlab\ProposalReactionWebhookService;
use Kelpie\Service\Gitlab\ThreadActorService;
use Kelpie\Tests\Repository\IssueTriageJob\InMemoryIssueQueue;
use Kelpie\Tests\Service\Autofix\FakeJobSql;
use Kelpie\Tests\Service\Autofix\InMemoryOutcomeStore;
use Kelpie\Tests\Service\Autofix\InsertedJobs;
use Kelpie\Tests\Service\Gitlab\StubHttpClient;
use Latte\Engine;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Ubix\Enum\StatusCode;
use Ubix\Exception\HttpClientException;
use Ubix\Renderer\TemplateRenderer;
use Ubix\Service\Ci\ApprovalWebhookService;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Controller\Gitlab\GitlabWebhookController
 *
 * @coversDefaultClass \Kelpie\Controller\Gitlab\GitlabWebhookController
 */
final class GitlabWebhookControllerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * The parameters of every job the proposal reaction and Triage: services inserted
     */
    private InsertedJobs $inserts;

    /**
     * @var list<array{topic: string, context: array<int|string, mixed>}>
     */
    private array $logged = [];

    /**
     * Requests the fake GitLab client received, as "METHOD path"
     *
     * @var list<string>
     */
    private array $sent = [];

    /**
     * Status of the newest sign-off job the fake GitLab reports
     */
    private string $jobStatus = 'failed';

    /**
     * Whether the fake GitLab refuses every call
     */
    private bool $gitlabDown = false;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(GitlabWebhookController::class);
    }

    /**
     * A wrong token gives 401
     *
     * @return void
     */
    public function testAWrongTokenGives401(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, 'correct');
        $request    = new ServerRequest('POST', '/', ['X-Gitlab-Token' => 'wrong']);
        $request    = $request->withBody((new StreamFactory())->createStream('{}'));
        $response   = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::UNAUTHORIZED->value, $result->getStatusCode());
    }

    /**
     * A missing token gives 401
     *
     * @return void
     */
    public function testAMissingTokenGives401(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, 'correct');
        $request    = new ServerRequest('POST', '/');
        $request    = $request->withBody((new StreamFactory())->createStream('{}'));
        $response   = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::UNAUTHORIZED->value, $result->getStatusCode());
    }

    /**
     * An empty/unset secret rejects every token (including empty)
     *
     * @return void
     */
    public function testAnEmptySecretRejectsEveryToken(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, '');
        $request    = new ServerRequest('POST', '/', ['X-Gitlab-Token' => '']);
        $request    = $request->withBody((new StreamFactory())->createStream('{}'));
        $response   = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::UNAUTHORIZED->value, $result->getStatusCode());
    }

    /**
     * An unset (empty string) secret rejects a present token too
     *
     * @return void
     */
    public function testAnEmptySecretRejectsAPresentToken(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, '');
        $request    = new ServerRequest('POST', '/', ['X-Gitlab-Token' => 'anything']);
        $request    = $request->withBody((new StreamFactory())->createStream('{}'));
        $response   = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::UNAUTHORIZED->value, $result->getStatusCode());
    }

    /**
     * A valid Note Hook on an MR reaches the service
     *
     * @return void
     */
    public function testAValidNoteHookOnMrReachesTheService(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, 'secret');
        $payload    = $this->encode([
            'merge_request'     => [
                'iid'        => 12,
                'project_id' => 1,
                'title'      => 'Add the feature',
            ],
            'object_attributes' => [
                'note'          => 'LGTM',
                'noteable_type' => 'MergeRequest',
            ],
            'object_kind'       => 'note',
            'project'           => [
                'id'                  => 1,
                'name'                => 'proj',
                'path_with_namespace' => 'org/proj',
            ],
            'user'              => ['username' => 'alice'],
        ]);

        $request  = (new ServerRequest(
            'POST',
            '/gitlab/webhook',
            ['X-Gitlab-Token' => 'secret', 'X-Gitlab-Event' => 'Note Hook'],
        ))
            ->withBody((new StreamFactory())->createStream($payload));
        $response = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::OK->value, $result->getStatusCode());
        $result = $this->logged[1]['context']['result'] ?? '';
        $this->assertIsString($result);
        $this->assertStringContainsString('noted: MR !12', $result);
    }

    /**
     * A failed MR Pipeline Hook is filtered into an autofix job
     *
     * @return void
     */
    public function testAFailedPipelineHookIsQueuedForAutofix(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, 'secret');
        $payload    = $this->encode([
            'builds'            => [['stage' => 'lint-and-test', 'status' => 'failed']],
            'commit'            => ['message' => 'feat: x'],
            'merge_request'     => ['iid' => 3, 'state' => 'opened', 'title' => 't', 'target_branch' => 'dev'],
            'object_attributes' => ['source' => 'merge_request_event', 'status' => 'failed', 'sha' => 'abc'],
            'project'           => ['path_with_namespace' => 'ubixsys/ubixkelpie'],
            'user'              => ['username' => 'alice'],
        ]);

        $request = (new ServerRequest(
            'POST',
            '/gitlab/webhook',
            ['X-Gitlab-Token' => 'secret', 'X-Gitlab-Event' => 'Pipeline Hook'],
        ))
            ->withBody((new StreamFactory())->createStream($payload));

        $result = $controller->webhook($request, new Response());

        $this->assertSame(StatusCode::OK->value, $result->getStatusCode());
        $this->assertStringContainsString('queued: ubixsys/ubixkelpie!3@abc', stripslashes((string) $result->getBody()));
    }

    /**
     * A finished review Job Hook is written as a job for the worker
     *
     * @return void
     */
    public function testAFinishedReviewJobHookIsQueued(): void
    {
        $controller = $this->makeController($this->captureLogger(), 'secret');
        $payload    = $this->encode([
            'build_name'   => 'kelpie-review',
            'build_status' => 'success',
            'project'      => ['path_with_namespace' => 'ubixsys/ubixkelpie'],
            'sha'          => 'abc',
        ]);

        $request = (new ServerRequest('POST', '/gitlab/webhook', ['X-Gitlab-Token' => 'secret', 'X-Gitlab-Event' => 'Job Hook']))
            ->withBody((new StreamFactory())->createStream($payload));

        $result = $controller->webhook($request, new Response());

        $this->assertSame(StatusCode::OK->value, $result->getStatusCode());
        $this->assertStringContainsString('queued: ubixsys/ubixkelpie!0@abc (enqueued)', stripslashes((string) $result->getBody()));
    }

    /**
     * A valid Issue Hook with open reaches the service
     *
     * @return void
     */
    public function testAValidIssueHookWithOpenReachesTheService(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, 'secret');
        $payload    = $this->encode([
            'object_attributes' => [
                'action'     => 'open',
                'iid'        => 5,
                'project_id' => 1,
                'title'      => 'New issue',
            ],
            'object_kind'       => 'issue',
            'project'           => [
                'id'                  => 1,
                'name'                => 'proj',
                'path_with_namespace' => 'org/proj',
            ],
            'user'              => ['username' => 'bob'],
        ]);

        $request  = (new ServerRequest(
            'POST',
            '/gitlab/webhook',
            ['X-Gitlab-Token' => 'secret', 'X-Gitlab-Event' => 'Issue Hook'],
        ))
            ->withBody((new StreamFactory())->createStream($payload));
        $response = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::OK->value, $result->getStatusCode());
        $result = $this->logged[1]['context']['result'] ?? '';
        $this->assertIsString($result);
        $this->assertStringContainsString('triage: new issue', $result);
    }

    /**
     * A Note on an issue gives 200 ignored
     *
     * @return void
     */
    public function testANoteOnAnIssueGives200Ignored(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, 'secret');
        $payload    = $this->encode([
            'merge_request'     => null,
            'object_attributes' => [
                'note'          => 'comment',
                'noteable_type' => 'Issue',
            ],
            'object_kind'       => 'note',
            'project'           => [
                'id'                  => 1,
                'name'                => 'proj',
                'path_with_namespace' => 'org/proj',
            ],
            'user'              => ['username' => 'carol'],
        ]);

        $request  = (new ServerRequest(
            'POST',
            '/gitlab/webhook',
            ['X-Gitlab-Token' => 'secret', 'X-Gitlab-Event' => 'Note Hook'],
        ))
            ->withBody((new StreamFactory())->createStream($payload));
        $response = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::OK->value, $result->getStatusCode());
    }

    /**
     * An Issue with close gives 200 ignored
     *
     * @return void
     */
    public function testAnIssueWithCloseGives200Ignored(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, 'secret');
        $payload    = $this->encode([
            'object_attributes' => [
                'action' => 'close',
            ],
            'object_kind'       => 'issue',
            'project'           => [
                'id'                  => 1,
                'name'                => 'proj',
                'path_with_namespace' => 'org/proj',
            ],
            'user'              => ['username' => 'dave'],
        ]);

        $request  = (new ServerRequest(
            'POST',
            '/gitlab/webhook',
            ['X-Gitlab-Token' => 'secret', 'X-Gitlab-Event' => 'Issue Hook'],
        ))
            ->withBody((new StreamFactory())->createStream($payload));
        $response = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::OK->value, $result->getStatusCode());
    }

    /**
     * An unknown event gives 200 ignored
     *
     * @return void
     */
    public function testAnUnknownEventGives200Ignored(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, 'secret');
        $payload    = $this->encode(['foo' => 'bar']);

        $request  = (new ServerRequest(
            'POST',
            '/gitlab/webhook',
            ['X-Gitlab-Token' => 'secret', 'X-Gitlab-Event' => 'Pipeline Hook'],
        ))
            ->withBody((new StreamFactory())->createStream($payload));
        $response = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::OK->value, $result->getStatusCode());
    }

    /**
     * Bad JSON gives 200 ignored
     *
     * @return void
     */
    public function testBadJsonGives200Ignored(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, 'secret');
        $request    = (new ServerRequest(
            'POST',
            '/gitlab/webhook',
            ['X-Gitlab-Token' => 'secret', 'X-Gitlab-Event' => 'Note Hook'],
        ))
            ->withBody((new StreamFactory())->createStream('not json'));
        $response   = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::OK->value, $result->getStatusCode());
    }

    /**
     * A scalar body (e.g. 5 or x) gives 200 ignored
     *
     * @return void
     */
    public function testAScalarBodyGives200Ignored(): void
    {
        $logger     = $this->captureLogger();
        $controller = $this->makeController($logger, 'secret');
        $request    = (new ServerRequest(
            'POST',
            '/gitlab/webhook',
            ['X-Gitlab-Token' => 'secret', 'X-Gitlab-Event' => 'Note Hook'],
        ))
            ->withBody((new StreamFactory())->createStream('5'));
        $response   = new Response();

        $result = $controller->webhook($request, $response);

        $this->assertSame(StatusCode::OK->value, $result->getStatusCode());
    }

    /**
     * An Approved merge request event retries the sign-off job
     *
     * @return void
     */
    public function testAnApprovedMergeRequestHookRetriesTheJob(): void
    {
        $reply = $this->deliver('Merge Request Hook', $this->mergeRequestEvent('approved'));

        $this->assertStringContainsString('retried: job 12', $reply);
        $this->assertSame([
            'GET /api/v4/projects/7/merge_requests/3',
            'GET /api/v4/projects/7/pipelines/99/jobs',
            'POST /api/v4/projects/7/jobs/12/retry',
        ], $this->sent);
    }

    /**
     * A thumbs-up on a merge request counts the same as an Approve
     *
     * @return void
     */
    public function testAThumbsUpOnAMergeRequestRetriesTheJob(): void
    {
        $reply = $this->deliver('Emoji Hook', $this->encode([
            'merge_request'     => ['iid' => 3],
            'object_attributes' => ['action' => 'award', 'awardable_type' => 'MergeRequest', 'name' => 'thumbsup'],
            'object_kind'       => 'emoji',
            'project'           => ['id' => 7],
        ]));

        $this->assertStringContainsString('retried: job 12', $reply);
        $this->assertContains('POST /api/v4/projects/7/jobs/12/retry', $this->sent);
    }

    /**
     * A thumbs-up from the MR author on one of Kelpie's proposals queues the apply, next to the approval service's own answer
     *
     * @return void
     */
    public function testAThumbsUpOnAProposalIsQueuedForApply(): void
    {
        $reply = $this->deliver('Emoji Hook', $this->proposalReaction('thumbsup', 5));

        $this->assertStringContainsString('"reaction":"queued: apply org\/proj!3#d1', $reply);
        $this->assertCount(1, $this->inserts);
        $this->assertSame('apply', $this->inserts->get(0)['source']);
    }

    /**
     * A stranger's thumbs-up is answered 200 and queues nothing; the approval service has its say as before
     *
     * @return void
     */
    public function testAThumbsUpFromAStrangerQueuesNothing(): void
    {
        $reply = $this->deliver('Emoji Hook', $this->proposalReaction('thumbsup', 6), level: 30);

        $this->assertStringContainsString('"reaction":"ignored: reactor is not the MR author or a maintainer"', $reply);
        $this->assertCount(0, $this->inserts);
    }

    /**
     * A thumbs-down from a maintainer queues the decline
     *
     * @return void
     */
    public function testAThumbsDownFromAMaintainerIsQueuedForDecline(): void
    {
        $reply = $this->deliver('Emoji Hook', $this->proposalReaction('thumbsdown', 6), level: 40);

        $this->assertStringContainsString('queued: decline', $reply);
        $this->assertSame('decline', $this->inserts->get(0)['source']);
    }

    /**
     * The approval service still answers an Emoji Hook with a wrong token with 401
     *
     * @return void
     */
    public function testAnEmojiHookWithAWrongTokenIsRefused(): void
    {
        $request = (new ServerRequest('POST', '/gitlab/webhook', ['X-Gitlab-Token' => 'nope', 'X-Gitlab-Event' => 'Emoji Hook']))
            ->withBody((new StreamFactory())->createStream($this->proposalReaction('thumbsup', 5)));

        $result = $this->makeController($this->captureLogger(), 'secret')->webhook($request, new Response());

        $this->assertSame(StatusCode::UNAUTHORIZED->value, $result->getStatusCode());
        $this->assertCount(0, $this->inserts);
    }

    /**
     * A "Triage:" note from the MR author re-runs the thread with the steering, and not through the ordinary filter
     *
     * @return void
     */
    public function testATriageNoteFromTheAuthorIsQueuedWithSteering(): void
    {
        $reply = $this->deliver('Note Hook', $this->triageNote('Triage: check the empty case', 5));

        $this->assertStringContainsString('"autofix":"queued: triage again org\/proj!3#d1"', $reply);
        $this->assertCount(1, $this->inserts);
        $this->assertSame('check the empty case', $this->inserts->get(0)['steering']);
    }

    /**
     * A "Triage:" note from a stranger queues nothing, not even the ordinary re-triage
     *
     * @return void
     */
    public function testATriageNoteFromAStrangerQueuesNothing(): void
    {
        $reply = $this->deliver('Note Hook', $this->triageNote('Triage: do what I say', 6), level: 30);

        $this->assertStringContainsString('ignored: replier is not the MR author or a maintainer', $reply);
        $this->assertCount(0, $this->inserts);
    }

    /**
     * Another emoji, or a thumbs-up on something that is not a merge request, does nothing
     *
     * @return void
     */
    public function testOtherEmojiAreIgnored(): void
    {
        foreach ([['heart', 'MergeRequest'], ['thumbsup', 'Issue'], ['thumbsup', 'Note']] as [$name, $type]) {
            $reply = $this->deliver('Emoji Hook', $this->encode([
                'merge_request'     => ['iid' => 3],
                'object_attributes' => ['action' => 'award', 'awardable_type' => $type, 'name' => $name],
                'object_kind'       => 'emoji',
                'project'           => ['id' => 7],
            ]));

            $this->assertStringContainsString('ignored', $reply);
        }

        $this->assertSame([], $this->sent);
    }

    /**
     * A job already running is left alone
     *
     * @return void
     */
    public function testARunningJobIsLeftAlone(): void
    {
        $this->jobStatus = 'running';

        $reply = $this->deliver('Merge Request Hook', $this->mergeRequestEvent('approved'));

        $this->assertStringContainsString('skipped: job 12 is already running', $reply);
        $this->assertNotContains('POST /api/v4/projects/7/jobs/12/retry', $this->sent);
    }

    /**
     * A GitLab failure is answered 200, so GitLab does not disable the hook
     *
     * @return void
     */
    public function testAGitlabFailureIsAnswered200(): void
    {
        $this->gitlabDown = true;

        $reply = $this->deliver('Merge Request Hook', $this->mergeRequestEvent('approved'));

        $this->assertStringContainsString('error:', $reply);
    }

    /**
     * Other merge request actions are ignored, and nothing is created or approved
     *
     * @return void
     */
    public function testOtherMergeRequestActionsAreIgnored(): void
    {
        $reply = $this->deliver('Merge Request Hook', $this->mergeRequestEvent('open'));

        $this->assertStringContainsString('ignored', $reply);
        $this->assertSame([], $this->sent);
    }

    /**
     * A merge request hook with a wrong token is refused before GitLab is touched
     *
     * @return void
     */
    public function testAMergeRequestHookWithAWrongTokenIsRefused(): void
    {
        $controller = $this->makeController($this->captureLogger(), 'secret');
        $request    = (new ServerRequest('POST', '/gitlab/webhook', ['X-Gitlab-Token' => 'wrong', 'X-Gitlab-Event' => 'Merge Request Hook']))
            ->withBody((new StreamFactory())->createStream($this->mergeRequestEvent('approved')));

        $result = $controller->webhook($request, new Response());

        $this->assertSame(StatusCode::UNAUTHORIZED->value, $result->getStatusCode());
        $this->assertSame([], $this->sent);
    }

    /**
     * The service the controller holds cannot approve: the guard client refuses it
     *
     * @return void
     */
    public function testTheWiredClientCannotApprove(): void
    {
        $this->expectException(HttpClientException::class);

        $this->guardedClient()->sendRequest(new ServerRequest('POST', 'https://gitlab.example.com/api/v4/projects/7/merge_requests/3/approve'));
    }

    /**
     * Deliver one webhook to a controller wired to the fake GitLab
     *
     * @param string $event The X-Gitlab-Event header
     * @param string $body  The payload
     * @param ?int   $level The access level GitLab reports for a user who is not the MR's author
     *
     * @return string The reply body, after asserting the status was 200
     */
    private function deliver(string $event, string $body, ?int $level = 10): string
    {
        $request = (new ServerRequest('POST', '/gitlab/webhook', ['X-Gitlab-Token' => 'secret', 'X-Gitlab-Event' => $event]))
            ->withBody((new StreamFactory())->createStream($body));

        $result = $this->makeController($this->captureLogger(), 'secret', $level)->webhook($request, new Response());

        $this->assertSame(StatusCode::OK->value, $result->getStatusCode());

        return (string) $result->getBody();
    }

    /**
     * An Emoji Hook for note 105, Kelpie's proposal on thread d1 of org/proj!3, whose author is user 5
     *
     * @param string $emoji  The emoji
     * @param int    $userId Who reacted
     *
     * @return string
     */
    private function proposalReaction(string $emoji, int $userId): string
    {
        return $this->encode([
            'merge_request'     => ['author_id' => 5, 'iid' => 3],
            'object_attributes' => ['action' => 'award', 'awardable_id' => 105, 'awardable_type' => 'Note', 'name' => $emoji],
            'object_kind'       => 'emoji',
            'project'           => ['id' => 7, 'path_with_namespace' => 'org/proj'],
            'user'              => ['id' => $userId, 'username' => 'alice'],
        ]);
    }

    /**
     * A Note Hook with a reply in thread d1 of org/proj!3, whose author is user 5
     *
     * @param string $note   The note
     * @param int    $userId Who wrote it
     *
     * @return string
     */
    private function triageNote(string $note, int $userId): string
    {
        return $this->encode([
            'merge_request'     => ['author_id' => 5, 'iid' => 3, 'last_commit' => ['id' => str_repeat('a', 40)], 'state' => 'opened', 'target_branch' => 'dev'],
            'object_attributes' => ['discussion_id' => 'd1', 'note' => $note, 'noteable_type' => 'MergeRequest', 'type' => 'DiscussionNote'],
            'object_kind'       => 'note',
            'project'           => ['id' => 7, 'path_with_namespace' => 'org/proj'],
            'user'              => ['id' => $userId, 'username' => 'alice'],
        ]);
    }

    /**
     * A merge request event body for project 7, MR !3
     *
     * @param string $action The object_attributes action
     *
     * @return string
     */
    private function mergeRequestEvent(string $action): string
    {
        return $this->encode([
            'object_attributes' => ['action' => $action, 'iid' => 3],
            'object_kind'       => 'merge_request',
            'project'           => ['id' => 7],
        ]);
    }

    /**
     * Build a logger that captures log entries
     *
     * @return Logger
     */
    private function captureLogger(): Logger
    {
        $logger = $this->createStub(Logger::class);
        $logger->method('info')->willReturnCallback(function (string $topic, array $context): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- log callback params unused
            $this->logged[] = ['topic' => $topic, 'context' => $context];
        });

        return $logger;
    }

    /**
     * Build a controller with the given logger and secret
     *
     * @param Logger $logger The logger
     * @param string $secret The webhook secret token
     * @param ?int   $level  The access level GitLab reports for a user who is not the MR's author
     *
     * @return GitlabWebhookController
     */
    private function makeController(Logger $logger, string $secret = 'shh', ?int $level = 10): GitlabWebhookController
    {
        $templateRender = new TemplateRenderer($logger, new Engine());
        $jsonService    = new JsonService($logger);
        $noteService    = new NoteOnMrWebhookService($logger);
        $issueService   = new IssueOpenWebhookService($logger, new MemberAccessReaderService($logger, $this->guardedClient(), new Psr17Factory(), 'https://gitlab.example.com/api/v4', $jsonService), new IssueAwardEmojiService($logger, $this->guardedClient(), new Psr17Factory(), 'https://gitlab.example.com/api/v4', $jsonService), new InMemoryIssueQueue(), true, 'kelpie-bot', [], ['org/proj']);

        $approvalService = new ApprovalWebhookService(
            $logger,
            $this->guardedClient(),
            new Psr17Factory(),
            $jsonService,
            'https://gitlab.example.com/api/v4',
            $secret,
            '7=project-token',
            '',
            ['require-approval', 'require-approval-mr'],
        );

        $sql = $this->createStub(SqlService::class);
        $sql->method('query')->willReturn(1);
        $sql->method('lastInsertId')->willReturn('7');
        $sql->method('getColumn')->willReturn('0');

        $this->inserts = new InsertedJobs();
        $outcomes      = new InMemoryOutcomeStore();
        $outcomes->record(new ThreadOutcomeRecord('org/proj', 3, 'd1', str_repeat('a', 40), 'fix', 'propose', ThreadOutcome::Proposed, 'r', proposalNoteId: 105, editsJson: '[{"file":"a.txt","new":"b","old":"a"}]'));
        $actors   = new ThreadActorService($logger, new MemberAccessReaderService($logger, StubHttpClient::member($level), new Psr17Factory(), 'https://gitlab.example.com/api/v4', $jsonService));
        $reaction = new ProposalReactionWebhookService($logger, $actors, $outcomes, FakeJobSql::service($logger, $this->createStub(SqlService::class), $this->inserts, FakeJobSql::FREE, str_repeat('a', 40)), true, 'kelpie-bot');
        $triage   = new TriageReplyService($logger, $actors, $outcomes, $outcomes, FakeJobSql::service($logger, $this->createStub(SqlService::class), $this->inserts, FakeJobSql::FREE, str_repeat('a', 40)), true, 'kelpie-bot');

        return new GitlabWebhookController(
            $logger,
            $templateRender,
            $jsonService,
            $noteService,
            $issueService,
            $approvalService,
            new EnqueueService($logger, new EventFilter($logger), new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql)), true),
            $reaction,
            $triage,
            $secret,
        );
    }

    /**
     * Encode a payload as JSON using the framework JsonService
     *
     * @param array<int|string, mixed> $payload The payload
     *
     * @return string The JSON string
     */
    private function encode(array $payload): string
    {
        $logger = $this->createStub(Logger::class);

        return (new JsonService($logger))->encode($payload);
    }

    /**
     * The production client stack around a fake GitLab: the guard over a recording stub
     *
     * @return ApprovalRetryHttpClient
     */
    private function guardedClient(): ApprovalRetryHttpClient
    {
        $gitlab = $this->createStub(HttpClient::class);
        $gitlab->method('sendRequest')->willReturnCallback(function (Request $request): Psr7Response {
            $call         = $request->getMethod() . ' ' . $request->getUri()->getPath();
            $this->sent[] = $call;

            if ($this->gitlabDown) {
                return new Psr7Response(500, [], '{}');
            }

            return new Psr7Response(200, [], match ($call) {
                'GET /api/v4/projects/7/merge_requests/3' => '{"head_pipeline":{"id":99}}',
                'GET /api/v4/projects/7/pipelines/99/jobs' => '[{"id":12,"name":"require-approval-mr","status":"' . $this->jobStatus . '"}]',
                default => '{}',
            });
        });

        return new ApprovalRetryHttpClient($this->createStub(Logger::class), $gitlab);
    }
}
