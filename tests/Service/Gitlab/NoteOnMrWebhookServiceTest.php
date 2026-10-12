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
     * A valid MR note is extracted and logged
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
        $payload = [
            'object_attributes' => [
                'iid'           => 12,
                'message'       => 'LGTM, looks good',
                'noteable_type' => 'MergeRequest',
                'project_id'    => 42,
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

        $service = new NoteOnMrWebhookService($logger);
        $payload = [
            'object_attributes' => [
                'iid'           => 3,
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
     * Non-MR notes return an empty result (they are ignored upstream)
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
            'object_attributes' => [
                'noteable_type' => 'Issue',
            ],
            'object_kind'       => 'note',
            'user'              => ['username' => 'carol'],
        ];

        $outcome = $service->handle($payload);

        $this->assertStringContainsString('noted', $outcome);
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

        $this->assertStringContainsString('noted', $outcome);
    }
}
