<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use Kelpie\GitLab\DiscussionWriter;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\DiscussionWriter
 *
 * What must hold:
 * - Notes come back oldest first, system notes left out
 * - A reply, a note edit and label changes reach the right GitLab endpoint
 * - A failure is reported as null/false and logged, never thrown
 *
 * @coversDefaultClass \Kelpie\GitLab\DiscussionWriter
 */
final class DiscussionWriterTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * The fake GitLab
     */
    private FakeGitlab $gitlab;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(DiscussionWriter::class);
    }

    /**
     * Notes are read as DTOs
     *
     * @return void
     */
    public function testNotesAreRead(): void
    {
        $this->gitlab->note('d1', 'first', '-2 minutes', 1);
        $this->gitlab->note('d1', 'second', '-1 minutes', 2, 'bob');

        $notes = $this->gitlab->writer($this->createStub(Logger::class))->notes('ubixsys/ubixkelpie', 3, 'd1');

        $this->assertNotNull($notes);
        $this->assertSame([1, 2], array_column($notes, 'id'));
        $this->assertSame('bob', $notes[1]->author);
        $this->assertContains('GET /projects/ubixsys%2Fubixkelpie/merge_requests/3/discussions/d1', $this->gitlab->requests());
    }

    /**
     * Reply, edit and labels
     *
     * @return void
     */
    public function testWrites(): void
    {
        $this->gitlab->note('d1', 'first', 'now', 1);
        $writer = $this->gitlab->writer($this->createStub(Logger::class));

        $id = $writer->reply('1', 3, 'd1', 'hello');
        $this->assertNotNull($id);
        $this->assertTrue($writer->editNote('1', 3, 'd1', $id, 'edited'));
        $this->assertSame('edited', $this->gitlab->notes()['d1'][1]['body']);

        $this->assertTrue($writer->addLabel('1', 3, 'kelpie::working'));
        $this->assertSame(['kelpie::working'], $this->gitlab->labels());
        $this->assertTrue($writer->removeLabel('1', 3, 'kelpie::working'));
        $this->assertSame([], $this->gitlab->labels());
        $this->assertSame(str_repeat('a', 40), $writer->headSha('1', 3));

        $this->assertTrue($writer->resolve('1', 3, 'd1'));
        $this->assertSame(['d1'], $this->gitlab->resolved());
        $this->assertFalse($writer->resolve('1', 3, 'nope'));
    }

    /**
     * Labels are changed in one request, project labels are created once, 👀 lands on a note, a summary note is opened
     *
     * @return void
     */
    public function testStatusCalls(): void
    {
        $writer = $this->gitlab->writer($this->createStub(Logger::class));

        $this->gitlab->withLabels(['old', 'gone']);
        $this->assertTrue($writer->changeLabels('1', 3, ['new', 'newer'], ['gone']));
        $this->assertSame(['old', 'new', 'newer'], $this->gitlab->labels());
        $this->assertSame(['old', 'new', 'newer'], $writer->mergeRequest('1', 3)?->labels);
        $this->assertTrue($writer->changeLabels('1', 3, [], []));

        $this->assertTrue($writer->ensureLabels('1', ['a' => '#000000', 'b' => '#ffffff']));
        $this->assertTrue($writer->ensureLabels('1', ['a' => '#000000', 'c' => '#ffffff']));
        $this->assertSame(['a', 'b', 'c'], $this->gitlab->projectLabels());

        $this->assertTrue($writer->awardEmoji('1', 3, 7, 'eyes'));
        $this->assertFalse($writer->awardEmoji('1', 3, 7, 'eyes'));

        $opened = $writer->openNote('1', 3, 'summary');
        $this->assertNotNull($opened);
        $this->assertSame($this->gitlab->notes()[$opened[0]][0]['id'], $opened[1]);
    }

    /**
     * The MR facts the fixer needs are read, and a fork is told apart
     *
     * @return void
     */
    public function testMergeRequest(): void
    {
        $writer = $this->gitlab->writer($this->createStub(Logger::class));

        $mr = $writer->mergeRequest('1', 3);

        $this->assertNotNull($mr);
        $this->assertSame(str_repeat('a', 40), $mr->sha);
        $this->assertSame('feat/x', $mr->sourceBranch);
        $this->assertSame('dev', $mr->targetBranch);
        $this->assertTrue($mr->open);
        $this->assertFalse($mr->draft);
        $this->assertSame($mr->sourceProjectId, $mr->targetProjectId);
        $this->assertSame('https://gitlab.test/g/p/-/merge_requests/3', $mr->webUrl);

        $this->gitlab->mergeRequestFields(['draft' => true, 'source_project_id' => 7, 'state' => 'merged']);
        $mr = $writer->mergeRequest('1', 3);
        $this->assertNotNull($mr);
        $this->assertTrue($mr->draft);
        $this->assertFalse($mr->open);
        $this->assertNotSame($mr->sourceProjectId, $mr->targetProjectId);

        $this->assertNull($writer->mergeRequest('1', 4));
    }

    /**
     * A new MR-level discussion is opened and its ID returned
     *
     * @return void
     */
    public function testOpen(): void
    {
        $id = $this->gitlab->writer($this->createStub(Logger::class))->open('1', 3, 'The log.');

        $this->assertNotNull($id);
        $this->assertSame('The log.', $this->gitlab->notes()[$id][0]['body']);
        $this->assertNull($this->gitlab->writer($this->createStub(Logger::class))->open('1', 4, 'x'));
    }

    /**
     * A 404 is null/false and logged
     *
     * @return void
     */
    public function testFailureIsLoggedNotThrown(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->atLeastOnce())->method('error');
        $writer = $this->gitlab->writer($logger);

        $this->assertNull($writer->notes('1', 3, 'missing'));
        $this->assertNull($writer->reply('1', 3, 'missing', 'x'));
        $this->assertFalse($writer->editNote('1', 3, 'missing', 9, 'x'));
        $this->assertFalse($writer->addLabel('1', 4, 'x'));
    }

    /**
     * Start every test with an empty GitLab
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->gitlab = new FakeGitlab();
    }
}
