<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Gitlab;

use Kelpie\Service\Gitlab\NoteOnMrWebhookService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Gitlab\NoteOnMrWebhookService
 *
 * @coversDefaultClass \Kelpie\Service\Gitlab\NoteOnMrWebhookService
 */
final class NoteOnMrWebhookServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
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
        $this->testClassFollowingUbixStandards(NoteOnMrWebhookService::class);
    }

    /**
     * A valid MR note is extracted from the real GitLab Note Hook shape
     *
     * @return void
     */
    public function testAMrNoteIsExtractedAndLogged(): void
    {
        $logger = $this->createStub(Logger::class);
        $logger->method('info')->willReturnCallback(function (string $topic, array $context): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- log callback params unused
            $this->loggedContext = $context;
        });

        $service = new NoteOnMrWebhookService($logger);

        // Real GitLab Note Hook payload shape:
        // - comment is object_attributes.note
        // - MR details are at top-level merge_request.*
        // - project is project.*
        // - author is user.username
        $payload = [
            'merge_request'     => [
                'iid'        => 12,
                'project_id' => 42,
                'title'      => 'Add the feature',
            ],
            'object_attributes' => [
                'note'          => 'LGTM, looks good',
                'noteable_type' => 'MergeRequest',
            ],
            'object_kind'       => 'note',
            'project'           => [
                'id'                  => 42,
                'name'                => 'my-project',
                'path_with_namespace' => 'org/my-project',
            ],
            'user'              => ['username' => 'alice'],
        ];

        $outcome = $service->handle($payload);

        $this->assertStringContainsString('MR !12', $outcome);
        $this->assertStringContainsString('org/my-project', $outcome);
        $this->assertStringContainsString('alice', $outcome);
        $this->assertSame(42, $this->loggedContext['project_id']);
        $this->assertSame('my-project', $this->loggedContext['project_name']);
        $this->assertSame(12, $this->loggedContext['mr_iid']);
        $this->assertSame(16, $this->loggedContext['comment_length']);
    }

    /**
     * Fallback to project-level fields when merge_request lacks project_id
     *
     * @return void
     */
    public function testFallbackToProjectFields(): void
    {
        $logger = $this->createStub(Logger::class);
        $logger->method('info')->willReturnCallback(function (string $topic, array $context): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- log callback params unused
            $this->loggedContext = $context;
        });

        $service = new NoteOnMrWebhookService($logger);
        $payload = [
            'merge_request'     => [
                'iid'   => 3,
                'title' => 'WIP',
            ],
            'object_attributes' => [
                'note'          => 'fix it',
                'noteable_type' => 'MergeRequest',
            ],
            'object_kind'       => 'note',
            'project'           => [
                'id'                  => 99,
                'name'                => 'fallback',
                'path_with_namespace' => 'team/fallback',
            ],
            'user'              => ['username' => 'bob'],
        ];

        $outcome = $service->handle($payload);

        $this->assertStringContainsString('MR !3', $outcome);
        $this->assertSame(99, $this->loggedContext['project_id']);
    }

    /**
     * Non-MR notes return an ignored string
     *
     * @return void
     */
    public function testNonMrNotesAreHandledGracefully(): void
    {
        $logger = $this->createStub(Logger::class);
        $logger->method('info')->willReturnCallback(function (string $topic, array $context): void { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- log callback params unused
            $this->loggedContext = $context;
        });

        $service = new NoteOnMrWebhookService($logger);
        $payload = [
            'merge_request'     => null,
            'object_attributes' => [
                'note'          => 'issue comment',
                'noteable_type' => 'Issue',
            ],
            'object_kind'       => 'note',
            'project'           => ['id' => 1],
            'user'              => ['username' => 'carol'],
        ];

        $outcome = $service->handle($payload);

        $this->assertSame('ignored: not an MR note', $outcome);
    }

    /**
     * Minimal payload does not throw
     *
     * @return void
     */
    public function testMinimalPayloadDoesNotThrow(): void
    {
        $service = new NoteOnMrWebhookService($this->createStub(Logger::class));
        $payload = [];

        $outcome = $service->handle($payload);

        $this->assertStringContainsString('ignored', $outcome);
    }
}
