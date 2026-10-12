<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Gitlab;

use Kelpie\Service\Gitlab\IssueOpenWebhookService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Gitlab\IssueOpenWebhookService
 *
 * @coversDefaultClass \Kelpie\Service\Gitlab\IssueOpenWebhookService
 */
final class IssueOpenWebhookServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Captured log context
     *
     * @var array<int|string, mixed>
     */
    private array $loggedContext = [];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueOpenWebhookService::class);
    }

    /**
     * A valid open issue is extracted and logged
     *
     * @return void
     */
    public function testAnOpenIssueIsExtractedAndLogged(): void
    {
        $logger = $this->createStub(Logger::class);
        $logger->method('info')->willReturnCallback(function (string $topic, array $context): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- log callback params unused
            $this->loggedContext = $context;
        });

        $service = new IssueOpenWebhookService($logger);
        $payload = [
            'object_attributes' => [
                'action'     => 'open',
                'iid'        => 7,
                'project_id' => 42,
                'title'      => 'Fix the thing',
            ],
            'object_kind'       => 'issue',
            'project'           => [
                'id'                  => 42,
                'name'                => 'my-project',
                'path_with_namespace' => 'org/my-project',
            ],
            'user'              => ['username' => 'alice'],
        ];

        $outcome = $service->handle($payload);

        $this->assertStringContainsString('issue #7', $outcome);
        $this->assertStringContainsString('org/my-project', $outcome);
        $this->assertStringContainsString('alice', $outcome);
        $this->assertSame(42, $this->loggedContext['project_id']);
        $this->assertSame('Fix the thing', $this->loggedContext['issue_title']);
    }

    /**
     * Fallback to project-level fields when object_attributes lacks project_id
     *
     * @return void
     */
    public function testFallbackToProjectFields(): void
    {
        $logger = $this->createStub(Logger::class);
        $logger->method('info')->willReturnCallback(function (string $topic, array $context): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- log callback params unused
            $this->loggedContext = $context;
        });

        $service = new IssueOpenWebhookService($logger);
        $payload = [
            'object_attributes' => [
                'action' => 'open',
                'iid'    => 2,
            ],
            'object_kind'       => 'issue',
            'project'           => [
                'id'                  => 99,
                'name'                => 'fallback',
                'path_with_namespace' => 'team/fallback',
            ],
            'user'              => ['username' => 'bob'],
        ];

        $outcome = $service->handle($payload);

        $this->assertStringContainsString('issue #2', $outcome);
        $this->assertSame(99, $this->loggedContext['project_id']);
    }

    /**
     * Closed issues return an ignored string
     *
     * @return void
     */
    public function testClosedIssuesAreHandledGracefully(): void
    {
        $logger = $this->createStub(Logger::class);
        $logger->method('info')->willReturnCallback(function (string $topic, array $context): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- log callback params unused
            $this->loggedContext = $context;
        });

        $service = new IssueOpenWebhookService($logger);
        $payload = [
            'object_attributes' => [
                'action' => 'close',
            ],
            'object_kind'       => 'issue',
            'project'           => ['id' => 1],
            'user'              => ['username' => 'carol'],
        ];

        $outcome = $service->handle($payload);

        $this->assertSame('ignored: not an open event', $outcome);
    }

    /**
     * Minimal payload does not throw
     *
     * @return void
     */
    public function testMinimalPayloadDoesNotThrow(): void
    {
        $service = new IssueOpenWebhookService($this->createStub(Logger::class));
        $payload = [];

        $outcome = $service->handle($payload);

        $this->assertStringContainsString('ignored', $outcome);
    }
}
