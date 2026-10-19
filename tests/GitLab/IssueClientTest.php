<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use Kelpie\GitLab\IssueClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\IssueClient
 *
 * What must hold:
 * - The issue is read with its title, description, author, confidentiality and labels
 * - Only the named user's notes are returned
 * - A comment is a POST to the notes and a label is a PUT with add_labels, so the issue's other labels stay
 * - A failure is false or null, never thrown
 *
 * @coversDefaultClass \Kelpie\GitLab\IssueClient
 */
final class IssueClientTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueClient::class);
    }

    /**
     * The issue is read
     *
     * @return void
     */
    public function testReadsTheIssue(): void
    {
        $gitlab = (new FakeIssueGitlab('Crash', 'Details', 'alice', true))->withLabel('bug');

        $issue = $this->client($gitlab)->issue('org/proj', 7);

        $this->assertNotNull($issue);
        $this->assertSame(['Crash', 'Details', 'alice', true, ['bug']], [$issue->title, $issue->description, $issue->author, $issue->confidential, $issue->labels]);
        $this->assertSame(['GET ' . FakeIssueGitlab::ISSUE], $gitlab->requests());
    }

    /**
     * An unreadable issue is null
     *
     * @return void
     */
    public function testAnUnreadableIssueIsNull(): void
    {
        $this->assertNull($this->client((new FakeIssueGitlab())->failing('GET'))->issue('org/proj', 7));
    }

    /**
     * Only the named user's notes come back
     *
     * @return void
     */
    public function testNotesByOneUser(): void
    {
        $gitlab = (new FakeIssueGitlab())->withNote('alice', 'hi')->withNote('kelpie-bot', 'triage')->withNote('bob', 'me too');

        $this->assertSame(['triage'], $this->client($gitlab)->notesBy('org/proj', 7, 'kelpie-bot'));
        $this->assertNull($this->client((new FakeIssueGitlab())->failing('GET'))->notesBy('org/proj', 7, 'kelpie-bot'));
    }

    /**
     * A comment is posted to the notes
     *
     * @return void
     */
    public function testPostsAComment(): void
    {
        $gitlab = new FakeIssueGitlab();

        $this->assertTrue($this->client($gitlab)->comment('org/proj', 7, 'hello'));
        $this->assertSame(['POST ' . FakeIssueGitlab::ISSUE . '/notes'], $gitlab->requests());
        $this->assertSame([['body' => 'hello']], $gitlab->bodies());
        $this->assertFalse($this->client((new FakeIssueGitlab())->failing('POST'))->comment('org/proj', 7, 'x'));
    }

    /**
     * A label is added, not set
     *
     * @return void
     */
    public function testAddsALabel(): void
    {
        $gitlab = new FakeIssueGitlab();

        $this->assertTrue($this->client($gitlab)->addLabel('org/proj', 7, 'kelpie::needs-human'));
        $this->assertSame(['PUT ' . FakeIssueGitlab::ISSUE], $gitlab->requests());
        $this->assertSame([['add_labels' => 'kelpie::needs-human']], $gitlab->bodies());
        $this->assertFalse($this->client((new FakeIssueGitlab())->failing('PUT'))->addLabel('org/proj', 7, 'x'));
    }

    /**
     * @param FakeIssueGitlab $gitlab The fake GitLab
     *
     * @return IssueClient
     */
    private function client(FakeIssueGitlab $gitlab): IssueClient
    {
        $logger  = $this->createStub(Logger::class);
        $factory = new Psr17Factory();

        return new IssueClient($logger, $gitlab, $factory, $factory, 'https://gitlab.test/api/v4', new JsonService($logger));
    }
}
