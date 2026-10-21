<?php

declare(strict_types=1);

namespace Kelpie\Tests\Policy;

use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\Policy\EventFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Policy\EventFilter
 *
 * @coversDefaultClass \Kelpie\Policy\EventFilter
 */
final class EventFilterTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(EventFilter::class);
    }

    /**
     * A thread note on an open MR becomes a job
     *
     * @return void
     */
    public function testAThreadNoteBecomesAJob(): void
    {
        $job = (new EventFilter($this->createStub(Logger::class)))->filter('note hook', $this->note());

        $this->assertInstanceOf(AutofixJob::class, $job);
        $this->assertSame(5, $job->mrIid);
        $this->assertSame('abc123', $job->headSha);
        $this->assertSame('note', $job->source);
        $this->assertSame('d1', $job->discussionId);
        $this->assertFalse($job->afterAutofix);
    }

    /**
     * A head commit with the trailer marks the job
     *
     * @return void
     */
    public function testAnAutofixHeadMarksTheJob(): void
    {
        $payload = array_replace_recursive($this->note(), ['merge_request' => ['last_commit' => ['message' => "fix\n\nKelpie-Autofix: d0"]]]);

        $job = (new EventFilter($this->createStub(Logger::class)))->filter('note hook', $payload);

        $this->assertInstanceOf(AutofixJob::class, $job);
        $this->assertTrue($job->afterAutofix);
    }

    /**
     * Each loop guard and gate skips a note
     *
     * @param string                   $reason  Expected skip reason
     * @param array<int|string, mixed> $changes Replacements merged into the payload
     *
     * @return void
     */
    #[DataProvider('noteSkips')]
    public function testNoteSkips(string $reason, array $changes): void
    {
        $payload = array_replace_recursive($this->note(), $changes);
        $filter  = new EventFilter($this->createStub(Logger::class), 'kelpie-bot', ['shepherd'], ['ubixsys/ubixkelpie'], ['dev', 'staging'], ['shepherd/']);

        $this->assertSame($reason, $filter->filter('note hook', $payload));
    }

    /**
     * A failed gate job on an MR pipeline becomes a job
     *
     * @return void
     */
    public function testAFailedGatePipelineBecomesAJob(): void
    {
        $job = (new EventFilter($this->createStub(Logger::class)))->filter('pipeline hook', $this->pipeline());

        $this->assertInstanceOf(AutofixJob::class, $job);
        $this->assertSame('pipeline', $job->source);
        $this->assertSame(5, $job->mrIid);
        $this->assertSame('abc123', $job->headSha);
    }

    /**
     * Pipelines that are not fixable are skipped
     *
     * @param string                   $reason  Expected skip reason
     * @param array<int|string, mixed> $changes Replacements merged into the payload
     *
     * @return void
     */
    #[DataProvider('pipelineSkips')]
    public function testPipelineSkips(string $reason, array $changes): void
    {
        $payload = array_replace_recursive($this->pipeline(), $changes);

        $this->assertSame($reason, (new EventFilter($this->createStub(Logger::class)))->filter('pipeline hook', $payload));
    }

    /**
     * A finished review job becomes an MR-less job keyed by the head sha
     *
     * @return void
     */
    public function testAFinishedReviewJobBecomesAJob(): void
    {
        foreach (['success', 'failed'] as $status) {
            $job = (new EventFilter($this->createStub(Logger::class)))->filter('job hook', array_replace($this->reviewJob(), ['build_status' => $status]));

            $this->assertInstanceOf(AutofixJob::class, $job);
            $this->assertSame(0, $job->mrIid);
            $this->assertSame('abc123', $job->headSha);
            $this->assertSame('review', $job->source);
            $this->assertNull($job->discussionId);
        }
    }

    /**
     * Job hooks that are not a finished review of an enrolled project's head are skipped with their reason
     *
     * @return void
     */
    public function testJobHookSkips(): void
    {
        $filter = new EventFilter($this->createStub(Logger::class));

        $this->assertSame('not a review job', $filter->filter('job hook', array_replace($this->reviewJob(), ['build_name' => 'phpunit'])));
        $this->assertSame('review job not finished', $filter->filter('job hook', array_replace($this->reviewJob(), ['build_status' => 'running'])));
        $this->assertSame('project not allow-listed', $filter->filter('job hook', array_replace_recursive($this->reviewJob(), ['project' => ['path_with_namespace' => 'other/p']])));
        $this->assertSame('author is the bot or a Shepherd agent', $filter->filter('job hook', array_replace_recursive($this->reviewJob(), ['user' => ['username' => 'kelpie-bot']])));
        $this->assertSame('head is a kelpie-autofix commit', $filter->filter('job hook', array_replace_recursive($this->reviewJob(), ['commit' => ['message' => "fix\n\nKelpie-Autofix: d0"]])));
        $this->assertSame('no head sha', $filter->filter('job hook', array_replace($this->reviewJob(), ['sha' => ''])));
    }

    /**
     * The review job's name is configurable
     *
     * @return void
     */
    public function testReviewJobNamesAreConfigurable(): void
    {
        $filter = new EventFilter($this->createStub(Logger::class), reviewJobs: ['ai-review']);

        $this->assertSame('not a review job', $filter->filter('job hook', $this->reviewJob()));
        $this->assertInstanceOf(AutofixJob::class, $filter->filter('job hook', array_replace($this->reviewJob(), ['build_name' => 'ai-review'])));
    }

    /**
     * Other events are not autofix events
     *
     * @return void
     */
    public function testOtherEventsAreSkipped(): void
    {
        $this->assertSame('not an autofix event', (new EventFilter($this->createStub(Logger::class)))->filter('issue hook', []));
    }

    /**
     * @return array<string, array{string, array<int|string, mixed>}>
     */
    public static function noteSkips(): array
    {
        return [
            'bot'             => ['author is the bot or a Shepherd agent', ['user' => ['username' => 'kelpie-bot']]],
            'claim marker'    => ['kelpie marker', ['object_attributes' => ['note' => "x\n<!-- kelpie:claim thread=1 sha=a -->"]]],
            'closed'          => ['MR not open', ['merge_request' => ['state' => 'merged']]],
            'draft'           => ['MR is a draft', ['merge_request' => ['title' => 'Draft: wip']]],
            'issue note'      => ['not an MR note', ['object_attributes' => ['noteable_type' => 'Issue']]],
            'other project'   => ['project not allow-listed', ['project' => ['path_with_namespace' => 'org/other']]],
            'shepherd'        => ['author is the bot or a Shepherd agent', ['user' => ['username' => 'shepherd']]],
            'shepherd branch' => ['Shepherd-owned branch', ['merge_request' => ['source_branch' => 'shepherd/x']]],
            'system note'     => ['system note', ['object_attributes' => ['system' => true]]],
            'target'          => ['target branch not fixable', ['merge_request' => ['target_branch' => 'main']]],
            'top-level note'  => ['not in a thread', ['object_attributes' => ['type' => null]]],
        ];
    }

    /**
     * @return array<string, array{string, array<int|string, mixed>}>
     */
    public static function pipelineSkips(): array
    {
        return [
            'autofix commit'  => ['head is a kelpie-autofix commit', ['commit' => ['message' => 'fix Kelpie-Autofix: d']]],
            'bot push'        => ['author is the bot or a Shepherd agent', ['user' => ['username' => 'kelpie-bot']]],
            'branch pipeline' => ['not an MR pipeline', ['object_attributes' => ['source' => 'push']]],
            'deploy failed'   => ['no failed gate job', ['builds' => [['stage' => 'deploy', 'status' => 'failed']]]],
            'passed'          => ['pipeline not failed', ['object_attributes' => ['status' => 'success']]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function note(): array
    {
        return [
            'merge_request'     => [
                'iid'           => 5,
                'last_commit'   => ['id' => 'abc123', 'message' => 'feat: x'],
                'source_branch' => 'feat/x',
                'state'         => 'opened',
                'target_branch' => 'dev',
                'title'         => 'Add thing',
            ],
            'object_attributes' => [
                'discussion_id' => 'd1',
                'note'          => 'please rename this',
                'noteable_type' => 'MergeRequest',
                'system'        => false,
                'type'          => 'DiscussionNote',
            ],
            'project'           => ['path_with_namespace' => 'ubixsys/ubixkelpie'],
            'user'              => ['username' => 'alice'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pipeline(): array
    {
        return [
            'builds'            => [['stage' => 'lint-and-test', 'status' => 'failed', 'allow_failure' => false]],
            'commit'            => ['message' => 'feat: x'],
            'merge_request'     => [
                'iid'           => 5,
                'source_branch' => 'feat/x',
                'state'         => 'opened',
                'target_branch' => 'dev',
                'title'         => 'Add thing',
            ],
            'object_attributes' => ['source' => 'merge_request_event', 'status' => 'failed', 'sha' => 'abc123'],
            'project'           => ['path_with_namespace' => 'ubixsys/ubixkelpie'],
            'user'              => ['username' => 'alice'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewJob(): array
    {
        return [
            'build_name'   => 'kelpie-review',
            'build_status' => 'success',
            'commit'       => ['message' => 'feat: x'],
            'project'      => ['path_with_namespace' => 'ubixsys/ubixkelpie'],
            'sha'          => 'abc123',
            'user'         => ['username' => 'alice'],
        ];
    }
}
