<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\Thread;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\GitLab\FileReader;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Security\ContextScanner;
use Kelpie\Service\Autofix\ClaimService;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Service\Autofix\ThreadResponderService;
use Kelpie\Service\Autofix\TriageStatusService;
use Kelpie\Service\Triage\CodeContextService;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Tests\Service\Triage\TriageLlms;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\ThreadResponderService
 *
 * What must hold:
 * - A needs_human thread is claimed, answered, labelled, and the claim and working label are released
 * - A fix thread is claimed and released with no reply
 * - An answered thread and a claimed thread are skipped without triage
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\ThreadResponderService
 */
final class ThreadResponderServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
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
     * @var list<string> The bodies sent to the model
     */
    private array $modelBodies = [];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ThreadResponderService::class);
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
        $this->assertSame(['kelpie::needs-you'], $this->gitlab->labels()); // The working label came off, the outcome stayed
        $bodies = array_column($this->gitlab->notes()['d1'], 'body');
        $this->assertStringContainsString('kelpie:release', $bodies[1]);
        $this->assertStringContainsString('kelpie:reply', $bodies[2]);
    }

    /**
     * The responder triages a thread with the code it is about, read at the head it was given
     *
     * @return void
     */
    public function testThreadsAreTriagedWithTheirCode(): void
    {
        $this->gitlab->note('d1', 'Should we redesign this?');
        $files = $this->createStub(HttpClient::class);
        $files->method('sendRequest')->willReturn(new Psr7Response(200, [], "<?php\n\n\$a = redesign();\n"));
        $logger = $this->createStub(Logger::class);
        $code   = new CodeContextService($logger, new FileReader($logger, $files, new Psr17Factory(), 'https://gitlab.test/api/v4'));

        $this->responder('needs_human', $code)->respond('1', 3, 'abc', [$this->thread('d1')]);

        $this->assertCount(1, $this->modelBodies);
        $sent     = (new JsonService($logger))->decode($this->modelBodies[0]);
        $messages = $sent['messages'] ?? null;
        $this->assertIsArray($messages);
        $last = end($messages);
        $this->assertIsArray($last);
        $user = $last['content'] ?? null;
        $this->assertIsString($user);
        $this->assertStringContainsString('<code path="src/A.php" lines="1-4" at="abc">', $user);
        $this->assertStringContainsString('$a = redesign();', $user);
    }

    /**
     * An already_fixed thread with no changed code to show is answered and left open
     *
     * @return void
     */
    public function testAlreadyFixedThreadWithoutAChangeIsLeftOpen(): void
    {
        $this->gitlab->note('d1', 'Typo.');

        $outcomes = $this->responder('already_fixed')->respond('1', 3, 'abc', [$this->thread('d1')]);

        $this->assertSame(['d1' => ResponseOutcome::Replied], $outcomes);
        $this->assertSame([], $this->gitlab->resolved());
        $this->assertStringContainsString('class=already_fixed', implode("\n", array_column($this->gitlab->notes()['d1'], 'body')));
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
        $this->assertSame(['kelpie::proposed'], $this->gitlab->labels());
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
     * The run shows itself: a summary note edited into the outcome, 👀 on the thread, one status label
     *
     * @return void
     */
    public function testRunLeavesASummaryAndOneStatusLabel(): void
    {
        $firstNote = $this->gitlab->note('d1', 'Should we redesign this?');

        $this->responder('needs_human')->respond('1', 3, 'abc', [$this->thread('d1', $firstNote)]);

        $this->assertSame(['kelpie::needs-you'], $this->gitlab->labels());
        $this->assertSame([$firstNote . ':eyes'], $this->gitlab->awards());
        $summaries = [];
        foreach ($this->gitlab->notes() as $id => $notes) {
            if ($id !== 'd1') {
                $summaries[] = $notes[0]['body'];
            }
        }
        $this->assertCount(1, $summaries);
        $this->assertStringContainsString('**needs you**', $summaries[0]);
        $this->assertStringContainsString('could not answer: yours — Because.', $summaries[0]);
    }

    /**
     * `triage:off`, a draft and a run in progress (new or old label) leave the MR alone: no model call, no note, no label change
     *
     * @return void
     */
    public function testOffDraftAndClaimedMergeRequestsAreSkipped(): void
    {
        $this->gitlab->note('d1', 'x');

        $results = [];
        foreach ([['triage:off'], ['kelpie::working'], ['triage: working'], []] as $i => $labels) {
            $this->gitlab->withLabels($labels);
            $this->gitlab->mergeRequestFields(['draft' => $i === 3]);
            $results[] = $this->responder('needs_human')->respond('1', 3, 'abc', [$this->thread('d1')]);
        }

        $this->assertSame(array_fill(0, 4, ['d1' => ResponseOutcome::Skipped]), $results);
        $this->assertSame(0, $this->modelCalls);
        $this->assertSame([], $this->gitlab->labels());
        $this->assertCount(1, $this->gitlab->notes()); // Only the seeded thread
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
     * @param string $id          The discussion ID
     * @param int    $firstNoteId The first note
     *
     * @return Thread
     */
    private function thread(string $id, int $firstNoteId = 1): Thread
    {
        return new Thread($id, $firstNoteId, 'alice', 'Please look.', false, 1, new DateTimeImmutable(), new DateTimeImmutable(), 'src/A.php', 3);
    }

    /**
     * A responder whose model always answers with the given class
     *
     * @param string              $class The triage class the model returns
     * @param ?CodeContextService $code  Reads the code a thread is about; null for none
     *
     * @return ThreadResponderService
     */
    private function responder(string $class, ?CodeContextService $code = null): ThreadResponderService
    {
        putenv('OPENROUTER_API_KEY=test-key-not-real');
        putenv('OPENROUTER_API_URL=https://openrouter.test/api/v1');

        $logger = $this->createStub(Logger::class);
        $json   = new JsonService($logger);
        $model  = $this->createStub(HttpClient::class);
        $model->method('sendRequest')->willReturnCallback(function (Request $request) use ($json, $class): Psr7Response {
            ++$this->modelCalls;
            $this->modelBodies[] = (string) $request->getBody();
            $content             = $json->encode(['class' => $class, 'confidence' => 'high', 'reason' => 'Because.']);

            return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]]]));
        });

        $writer = $this->gitlab->writer($logger);

        return new ThreadResponderService(
            $logger,
            $writer,
            new ClaimService($logger, $writer),
            new ReplyService($logger, $writer, 'kelpie-bot'),
            new TriageService(
                $logger,
                new OpenRouterClient($logger, $model, new Psr17Factory(), new Psr17Factory(), $json),
                new TriagePrompt($logger, new ContextScanner($logger)),
                new SafetyPolicy($logger),
                $json,
                new LlmFamilyService($logger),
                TriageLlms::make($logger),
            ),
            new TriageStatusService($logger, $writer),
            $code,
        );
    }
}
