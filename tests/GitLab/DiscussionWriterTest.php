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
