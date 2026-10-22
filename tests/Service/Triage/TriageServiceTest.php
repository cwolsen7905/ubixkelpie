<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use DateTimeImmutable;
use Kelpie\DataTransferObject\FixProposal;
use Kelpie\DataTransferObject\Thread;
use Kelpie\Enum\TriageClass;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Triage\TriageService
 *
 * What must hold:
 * - Each of the four classes is read from the model's JSON, also inside a code fence
 * - No answer, non-JSON or an unknown class is needs_human, never a guess
 * - A fix that breaks the safety policy is downgraded
 *
 * @coversDefaultClass \Kelpie\Service\Triage\TriageService
 */
final class TriageServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageService::class);
    }

    /**
     * Every class is understood
     *
     * @return void
     */
    public function testEachClassIsParsed(): void
    {
        foreach (TriageClass::cases() as $class) {
            $verdict = $this->service($this->answer('{"class": "' . $class->value . '", "reason": "because"}'))->triage($this->thread());

            $this->assertSame($class, $verdict->class);
            $this->assertSame('because', $verdict->reason);
        }
    }

    /**
     * A code-fenced answer is still read
     *
     * @return void
     */
    public function testCodeFencedAnswerIsParsed(): void
    {
        $verdict = $this->service($this->answer("```json\n{\"class\": \"not_an_issue\", \"reason\": \"style\"}\n```"))->triage($this->thread());

        $this->assertSame(TriageClass::NotAnIssue, $verdict->class);
    }

    /**
     * Anything unusable is needs_human
     *
     * @return void
     */
    public function testUnusableAnswersAreNeedsHuman(): void
    {
        $unusable = [
            $this->answer('I think this is fine.'),
            $this->answer('{"class": "merge_it", "reason": "x"}'),
            $this->answer('{"reason": "no class"}'),
            new Psr7Response(500),
        ];

        foreach ($unusable as $response) {
            $this->assertSame(TriageClass::NeedsHuman, $this->service($response)->triage($this->thread())->class);
        }
    }

    /**
     * An unsafe fix is downgraded by the policy, not by the prompt
     *
     * @return void
     */
    public function testUnsafeFixIsDowngraded(): void
    {
        $proposal = new FixProposal(500, ['php/A.php'], false, false, 0, 0, 'dev', false);
        $verdict  = $this->service($this->answer('{"class": "fix", "reason": "ok"}'))->triage($this->thread(), $proposal);

        $this->assertSame(TriageClass::NeedsHuman, $verdict->class);
    }

    /**
     * A long reason is cut
     *
     * @return void
     */
    public function testReasonIsCapped(): void
    {
        $json    = (new JsonService($this->createStub(Logger::class)))->encode(['class' => 'fix', 'reason' => str_repeat('x', 5000)]);
        $verdict = $this->service($this->answer($json))->triage($this->thread());

        $this->assertSame(500, strlen($verdict->reason));
    }

    /**
     * The default model is not Anthropic, and the verdict carries what the call cost
     *
     * @return void
     */
    public function testDefaultModelIsAskedAndItsCostIsKept(): void
    {
        putenv('KELPIE_TRIAGE_MODEL');
        $body    = '{"choices":[{"message":{"content":"{\"class\": \"defer\", \"reason\": \"later\"}"}}],"usage":{"prompt_tokens":10,"completion_tokens":2,"cost":0.0001}}';
        $verdict = $this->service(new Psr7Response(200, [], $body))->triage($this->thread());

        $this->assertSame(TriageClass::Defer, $verdict->class);
        $this->assertNotNull($verdict->completion);
        $this->assertSame('google/gemini-3-flash', $verdict->completion->model);
        $this->assertSame(0.0001, $verdict->completion->costUsd);
    }

    /**
     * A triage model from the review's family is refused without a call
     *
     * @return void
     */
    public function testModelInTheReviewsFamilyIsRefused(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=anthropic/claude-haiku-5.5');
        putenv('AI_REVIEW_MODEL=openrouter/anthropic/claude-sonnet-5.5');

        $response = new Psr7Response(500);
        $verdict  = $this->service($response)->triage($this->thread());

        $this->assertSame(TriageClass::NeedsHuman, $verdict->class);
        $this->assertStringContainsString('same family as the review', $verdict->reason);
        $this->assertNull($verdict->completion);
    }

    /**
     * Set a dummy key
     *
     * @return void
     */
    protected function setUp(): void
    {
        putenv('OPENROUTER_API_KEY=test-key-not-real');
    }

    /**
     * Clean the environment
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('OPENROUTER_API_KEY');
        putenv('KELPIE_TRIAGE_MODEL');
        putenv('AI_REVIEW_MODEL');
    }

    /**
     * Wrap model text as an OpenRouter response
     *
     * @param string $content The assistant text
     *
     * @return Psr7Response
     */
    private function answer(string $content): Psr7Response
    {
        return new Psr7Response(200, [], (new JsonService($this->createStub(Logger::class)))->encode(['choices' => [['message' => ['content' => $content]]]]));
    }

    /**
     * Build a service whose model always answers with the given response
     *
     * @param Psr7Response $response The canned response
     *
     * @return TriageService
     */
    private function service(Psr7Response $response): TriageService
    {
        $logger     = $this->createStub(Logger::class);
        $httpClient = $this->createStub(HttpClient::class);
        $httpClient->method('sendRequest')->willReturn($response);
        $json = new JsonService($logger);

        return new TriageService(
            $logger,
            new OpenRouterClient($logger, $httpClient, new Psr17Factory(), new Psr17Factory(), $json),
            new TriagePrompt($logger),
            new SafetyPolicy($logger),
            $json,
            new LlmFamilyService($logger),
        );
    }

    /**
     * @return Thread
     */
    private function thread(): Thread
    {
        $now = new DateTimeImmutable('2026-10-15T00:00:00Z');

        return new Thread('abc', 1, 'alice', 'Please rename.', false, 1, $now, $now, 'php/A.php', 3);
    }
}
