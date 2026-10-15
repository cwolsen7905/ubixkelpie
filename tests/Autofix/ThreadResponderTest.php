<?php

declare(strict_types=1);

namespace Kelpie\Tests\Autofix;

use DateTimeImmutable;
use Kelpie\Autofix\ClaimService;
use Kelpie\Autofix\ReplyService;
use Kelpie\Autofix\ThreadResponder;
use Kelpie\DataTransferObject\Thread;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Triage\SafetyPolicy;
use Kelpie\Triage\TriagePrompt;
use Kelpie\Triage\TriageService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Autofix\ThreadResponder
 *
 * What must hold:
 * - A needs_human thread is claimed, answered, labelled, and the claim and working label are released
 * - A fix thread is claimed and released with no reply
 * - An answered thread and a claimed thread are skipped without triage
 *
 * @coversDefaultClass \Kelpie\Autofix\ThreadResponder
 */
final class ThreadResponderTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * The fake GitLab
     */
    private FakeGitlab $gitlab;

    /**
     * Model requests made
     */
    private int $modelCalls = 0;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ThreadResponder::class);
    }

    /**
     * A needs_human thread end to end
     *
     * @return void
     */
    public function testNeedsHumanThreadIsAnsweredAndReleased(): void
    {
        $this->gitlab->note('d1', 'Should we redesign this?');

        $outcomes = $this->responder('needs_human')->respond('1', 3, 'abc', [$this->thread('d1')]);

        $this->assertSame(['d1' => ResponseOutcome::Replied], $outcomes);
        $this->assertSame(['kelpie::needs-human'], $this->gitlab->labels()); // The working label came off again
        $bodies = array_column($this->gitlab->notes()['d1'], 'body');
        $this->assertStringContainsString('kelpie:release', $bodies[1]);
        $this->assertStringContainsString('kelpie:reply', $bodies[2]);
    }

    /**
     * A fix thread is claimed and released, nothing said
     *
     * @return void
     */
    public function testFixThreadIsSilent(): void
    {
        $this->gitlab->note('d1', 'Typo.');

        $outcomes = $this->responder('fix')->respond('1', 3, 'abc', [$this->thread('d1')]);

        $this->assertSame(['d1' => ResponseOutcome::Silent], $outcomes);
        $this->assertSame([], $this->gitlab->labels());
        $this->assertCount(2, $this->gitlab->notes()['d1']); // The original and the released claim
        $this->assertStringContainsString('kelpie:release', $this->gitlab->notes()['d1'][1]['body']);
    }

    /**
     * Answered and claimed threads are skipped and cost no model call
     *
     * @return void
     */
    public function testAnsweredAndClaimedThreadsAreSkipped(): void
    {
        $this->gitlab->note('d1', 'x');
        $this->gitlab->note('d1', '<!-- kelpie:reply thread=d1 class=needs_human -->', 'now', null, 'kelpie-bot');
        $this->gitlab->note('d2', 'y');
        $this->gitlab->note('d2', '<!-- kelpie:claim thread=d2 sha=abc -->', '-1 minutes', null, 'someone-else');

        $outcomes = $this->responder('needs_human')->respond('1', 3, 'abc', [$this->thread('d1'), $this->thread('d2'), $this->thread('missing')]);

        $this->assertSame(
            ['d1' => ResponseOutcome::AlreadyAnswered, 'd2' => ResponseOutcome::ClaimedByOther, 'missing' => ResponseOutcome::Failed],
            $outcomes,
        );
        $this->assertSame(0, $this->modelCalls);
        $this->assertSame([], $this->gitlab->labels());
    }

    /**
     * Clean the environment
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('OPENROUTER_API_KEY');
        putenv('OPENROUTER_API_URL');
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

    /**
     * @param string $id The discussion ID
     *
     * @return Thread
     */
    private function thread(string $id): Thread
    {
        return new Thread($id, 1, 'alice', 'Please look.', false, 1, new DateTimeImmutable(), new DateTimeImmutable(), 'src/A.php', 3);
    }

    /**
     * A responder whose model always answers with the given class
     *
     * @param string $class The triage class the model returns
     *
     * @return ThreadResponder
     */
    private function responder(string $class): ThreadResponder
    {
        putenv('OPENROUTER_API_KEY=test-key-not-real');
        putenv('OPENROUTER_API_URL=https://openrouter.test/api/v1');

        $logger = $this->createStub(Logger::class);
        $json   = new JsonService($logger);
        $model  = $this->createStub(HttpClient::class);
        $model->method('sendRequest')->willReturnCallback(function () use ($json, $class): Psr7Response {
            ++$this->modelCalls;
            $content = $json->encode(['class' => $class, 'reason' => 'Because.']);

            return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]]]));
        });

        $writer = $this->gitlab->writer($logger);

        return new ThreadResponder(
            $logger,
            $writer,
            new ClaimService($logger, $writer),
            new ReplyService($logger, $writer),
            new TriageService(
                $logger,
                new OpenRouterClient($logger, $model, new Psr17Factory(), new Psr17Factory(), $json),
                new TriagePrompt($logger),
                new SafetyPolicy($logger),
                $json,
            ),
        );
    }
}
