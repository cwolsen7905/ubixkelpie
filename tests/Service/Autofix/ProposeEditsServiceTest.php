<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\Thread;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Triage\LlmFamilyService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\ProposeEditsService
 *
 * What must hold:
 * - The edits and the summary come from the model's JSON, with the cost of the call
 * - A model from the review's family is never called; neither is one for a thread with no file
 * - Anything unusable comes back as a failure reason with no edits
 * - A large file is shown as a window around the thread's line
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\ProposeEditsService
 */
final class ProposeEditsServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $dir = '';

    /**
     * The user messages the model was sent
     *
     * @var list<string>
     */
    private array $sent = [];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProposeEditsService::class);
    }

    /**
     * The default model is not Anthropic, and KELPIE_PROPOSE_MODEL overrides it
     *
     * @return void
     */
    public function testModelDefaultAndOverride(): void
    {
        $service = $this->service($this->answer('{}'));
        $this->assertSame('openai/gpt-5.5', $service->model());

        putenv('KELPIE_PROPOSE_MODEL=google/gemini-3-pro');
        $this->assertSame('google/gemini-3-pro', $service->model());
    }

    /**
     * Edits, summary, JSON for the record and the cost
     *
     * @return void
     */
    public function testEditsAreParsedWithTheirCost(): void
    {
        $content = '{"summary":"Adds a line.","edits":[{"file":"a.txt","old":"one","new":"two"}]}';
        $result  = $this->service($this->answer("```json\n" . $content . "\n```"))->propose($this->thread(), $this->dir, 'A typo.');

        $this->assertSame('', $result->failure);
        $this->assertSame('Adds a line.', $result->summary);
        $this->assertCount(1, $result->edits);
        $this->assertSame(['a.txt', 'one', 'two'], [$result->edits[0]->file, $result->edits[0]->old, $result->edits[0]->new]);
        $this->assertSame('[{"file":"a.txt","new":"two","old":"one"}]', $result->editsJson);
        $this->assertNotNull($result->completion);
        $this->assertSame('openai/gpt-5.5', $result->completion->model);
        $this->assertSame(0.01, $result->completion->costUsd);
        $this->assertStringContainsString("one\n", $this->sent[0]);
        $this->assertStringContainsString('A typo.', $this->sent[0]);
    }

    /**
     * An empty edit list is the model saying there is no safe edit
     *
     * @return void
     */
    public function testNoEditsIsAFailureWithTheModelsReason(): void
    {
        $result = $this->service($this->answer('{"summary":"Needs a redesign.","edits":[]}'))->propose($this->thread(), $this->dir, 'r');

        $this->assertSame([], $result->edits);
        $this->assertSame('The propose model found no safe edit: Needs a redesign.', $result->failure);
    }

    /**
     * An answer that is not the expected object is unusable
     *
     * @return void
     */
    public function testUnreadableAnswersFail(): void
    {
        foreach (['no json at all', '{"edits":"nope"}', '{"edits":[{"file":"a.txt","old":"one"}]}', '{"edits":[7]}'] as $answer) {
            $result = $this->service($this->answer($answer))->propose($this->thread(), $this->dir, 'r');

            $this->assertSame([], $result->edits, $answer);
            $this->assertSame('I could not read the propose model\'s answer.', $result->failure, $answer);
        }
    }

    /**
     * No answer from the model
     *
     * @return void
     */
    public function testNoAnswerFails(): void
    {
        $result = $this->service(new Psr7Response(500))->propose($this->thread(), $this->dir, 'r');

        $this->assertSame('The propose model gave no answer.', $result->failure);
    }

    /**
     * A model from the review's family is never called
     *
     * @return void
     */
    public function testSameFamilyModelIsNeverCalled(): void
    {
        putenv('KELPIE_PROPOSE_MODEL=openrouter/anthropic/claude-sonnet-5.5');

        $result = $this->service($this->answer('{"edits":[]}'))->propose($this->thread(), $this->dir, 'r');

        $this->assertStringContainsString('same family as the review', $result->failure);
        $this->assertSame([], $this->sent);
    }

    /**
     * A thread with no file, or a file that is not in the checkout, is not asked about
     *
     * @return void
     */
    public function testNoFileMeansNoCall(): void
    {
        $now = new DateTimeImmutable();

        $general = $this->service($this->answer('{}'))->propose(new Thread('d', 1, 'a', 'x', false, 1, $now, $now), $this->dir, 'r');
        $missing = $this->service($this->answer('{}'))->propose(new Thread('d', 1, 'a', 'x', false, 1, $now, $now, 'nope.txt', 1), $this->dir, 'r');
        $escape  = $this->service($this->answer('{}'))->propose(new Thread('d', 1, 'a', 'x', false, 1, $now, $now, '../outside.txt', 1), $this->dir, 'r');

        $this->assertSame('The thread is not on a file, so there is no text to edit.', $general->failure);
        $this->assertSame('The file the thread is on is not in the checkout.', $missing->failure);
        $this->assertSame('The file the thread is on is not in the checkout.', $escape->failure);
        $this->assertSame([], $this->sent);
    }

    /**
     * A file over the limit is shown as a window of whole lines around the thread
     *
     * @return void
     */
    public function testLargeFileIsShownAsAWindow(): void
    {
        $lines = [];
        for ($i = 1; $i <= 8000; $i++) {
            $lines[] = 'line ' . $i;
        }
        file_put_contents($this->dir . '/big.txt', implode("\n", $lines) . "\n");
        $now = new DateTimeImmutable();

        $this->service($this->answer('{"edits":[]}'))->propose(new Thread('d', 1, 'a', 'x', false, 1, $now, $now, 'big.txt', 2000), $this->dir, 'r');

        $this->assertStringContainsString("\nline 2000\n", $this->sent[0]);
        $this->assertStringContainsString("\nline 1750\n", $this->sent[0]);
        $this->assertStringContainsString("\nline 2250\n", $this->sent[0]);
        $this->assertStringNotContainsString("\nline 1749\n", $this->sent[0]);
        $this->assertStringNotContainsString("\nline 2251\n", $this->sent[0]);
        $this->assertStringContainsString('shown in part', $this->sent[0]);
    }

    /**
     * A checkout with a.txt and an environment with a key and no review model override
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kelpie-propose-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/a.txt', "one\n");
        file_put_contents(dirname($this->dir) . '/outside.txt', "x\n");
        $this->sent = [];
        putenv('OPENROUTER_API_KEY=test-key-not-real');
        putenv('OPENROUTER_API_URL=https://openrouter.test/api/v1');
        putenv('AI_REVIEW_MODEL');
        putenv('KELPIE_PROPOSE_MODEL');
    }

    /**
     * Remove the scratch checkout and the settings
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (str_contains($this->dir, 'kelpie-propose-test-')) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        putenv('KELPIE_PROPOSE_MODEL');
        putenv('OPENROUTER_API_URL');
    }

    /**
     * A chat-completion response carrying the text and a cost
     *
     * @param string $content The model's text
     *
     * @return Psr7Response
     */
    private function answer(string $content): Psr7Response
    {
        $json = new JsonService($this->createStub(Logger::class));

        return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]], 'usage' => ['completion_tokens' => 5, 'cost' => 0.01, 'prompt_tokens' => 50]]));
    }

    /**
     * @param Psr7Response $response The canned response to every call
     *
     * @return ProposeEditsService
     */
    private function service(Psr7Response $response): ProposeEditsService
    {
        $logger = $this->createStub(Logger::class);
        $json   = new JsonService($logger);
        $http   = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(function (Request $request) use ($response, $json): Psr7Response {
            $body         = $json->decode((string) $request->getBody());
            $messages     = is_array($body['messages'] ?? null) ? $body['messages'] : [];
            $user         = is_array($messages[1] ?? null) ? $messages[1] : [];
            $this->sent[] = is_string($user['content'] ?? null) ? $user['content'] : '';

            return $response;
        });

        return new ProposeEditsService($logger, new OpenRouterClient($logger, $http, new Psr17Factory(), new Psr17Factory(), $json), new ProposePrompt($logger), new LlmFamilyService($logger), $json);
    }

    /**
     * @return Thread
     */
    private function thread(): Thread
    {
        $now = new DateTimeImmutable();

        return new Thread('d1', 100, 'alice', 'Please fix the typo.', false, 1, $now, $now, 'a.txt', 1);
    }
}
