<?php

declare(strict_types=1);

namespace Kelpie\Tests\Controller\Gitlab;

use Kelpie\Controller\Gitlab\GitlabWebhookController;
use Kelpie\Service\Gitlab\IssueOpenWebhookService;
use Kelpie\Service\Gitlab\NoteOnMrWebhookService;
use Latte\Engine;
use Nyholm\Psr7\ServerRequest;
use Psr\Log\LoggerInterface as Logger;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Ubix\Enum\StatusCode;
use Ubix\Renderer\TemplateRenderer;
use Ubix\Service\JsonService;
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
     * @var list<array{topic: string, context: array<int|string, mixed>}>
     */
    private array $logged = [];

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
        $this->assertStringContainsString('opened: issue #5', $result);
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
     *
     * @return GitlabWebhookController
     */
    private function makeController(Logger $logger, string $secret = 'shh'): GitlabWebhookController
    {
        $templateRender = new TemplateRenderer($logger, new Engine());
        $jsonService    = new JsonService($logger);
        $noteService    = new NoteOnMrWebhookService($logger);
        $issueService   = new IssueOpenWebhookService($logger);

        return new GitlabWebhookController(
            $logger,
            $templateRender,
            $jsonService,
            $noteService,
            $issueService,
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
}
