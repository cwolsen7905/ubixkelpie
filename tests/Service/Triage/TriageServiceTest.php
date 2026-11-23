<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use DateTimeImmutable;
use Kelpie\DataTransferObject\FixProposal;
use Kelpie\DataTransferObject\Thread;
use Kelpie\Enum\TriageClass;
use Kelpie\Enum\TriageConfidence;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageLlmService;
use Kelpie\Service\Triage\TriageService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
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
    private string $sentBody = '';

    /**
     * @var list<string> The model of each request sent, in order
     */
    private array $sentModels = [];

    /**
     * @var list<Psr7Response> The responses still to give, in order, when no single one is canned
     */
    private array $responses = [];

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
            $verdict = $this->service($this->answer('{"class": "' . $class->value . '", "confidence": "high", "reason": "because"}'))->triage($this->thread());

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
        $verdict  = $this->service($this->answer('{"class": "fix", "confidence": "high", "reason": "ok"}'))->triage($this->thread(), $proposal);

        $this->assertSame(TriageClass::NeedsHuman, $verdict->class);
    }

    /**
     * A fix the model is not sure of is needs_human, and so is a fix with no confidence at all
     *
     * @return void
     */
    public function testAFixBelowTheThresholdIsNeedsHuman(): void
    {
        foreach (['{"class": "fix", "confidence": "low", "reason": "maybe"}', '{"class": "fix", "reason": "maybe"}', '{"class": "fix", "confidence": "certain", "reason": "maybe"}'] as $answer) {
            $verdict = $this->service($this->answer($answer))->triage($this->thread());

            $this->assertSame(TriageClass::NeedsHuman, $verdict->class);
            $this->assertStringContainsString('maybe', $verdict->reason);
        }

        $this->assertSame(TriageClass::Fix, $this->service($this->answer('{"class": "fix", "confidence": "medium", "reason": "ok"}'))->triage($this->thread())->class);
    }

    /**
     * Low confidence only holds back a fix: other classes keep their class and carry the confidence
     *
     * @return void
     */
    public function testOtherClassesAreNotHeldBack(): void
    {
        $verdict = $this->service($this->answer('{"class": "needs_context", "confidence": "low", "reason": "which file?"}'))->triage($this->thread());

        $this->assertSame(TriageClass::NeedsContext, $verdict->class);
        $this->assertSame(TriageConfidence::Low, $verdict->confidence);
    }

    /**
     * A long reason is cut
     *
     * @return void
     */
    public function testReasonIsCapped(): void
    {
        $json    = (new JsonService($this->createStub(Logger::class)))->encode(['class' => 'fix', 'confidence' => 'high', 'reason' => str_repeat('x', 5000)]);
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
        $this->assertSame('deepseek/deepseek-v4.1-flash', $verdict->completion->model);
        $this->assertSame(0.0001, $verdict->completion->costUsd);
    }

    /**
     * The request leaves a reasoning model room to answer: well above the client's default cap of 400
     *
     * @return void
     */
    public function testRequestLeavesRoomForReasoning(): void
    {
        $this->service($this->answer('{"class": "defer", "reason": "later"}'))->triage($this->thread());

        $this->assertStringContainsString('"max_tokens":2000', $this->sentBody);
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
     * A model that trains on its prompts is refused for a project that is not open source, and no model is called
     *
     * @return void
     */
    public function testTrainingTierOnPrivateProjectIsRefused(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=meta/muse-spark-1.3-contributor');
        $models = TriageLlms::make($this->createStub(Logger::class), 'private');

        $verdict = $this->service($this->answer('{"class":"fix","confidence":"high","reason":"would be asked"}'), $models)->triage($this->thread(), null, '', 'g/p');
        putenv('KELPIE_TRIAGE_MODEL');

        $this->assertSame(TriageClass::NeedsHuman, $verdict->class);
        $this->assertStringContainsString('trains on the prompts it receives', $verdict->reason);
        $this->assertNull($verdict->completion);
    }

    /**
     * The same model is asked for an open-source project (an OSI license detected)
     *
     * @return void
     */
    public function testTrainingTierOnPublicProjectIsAsked(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=meta/muse-spark-1.3-contributor');
        $models = TriageLlms::make($this->createStub(Logger::class), 'private', null, 'g/p', 'bsd-3-clause');

        $verdict = $this->service($this->answer('{"class":"not_an_issue","reason":"fine as is"}'), $models)->triage($this->thread(), null, '', 'g/p');
        putenv('KELPIE_TRIAGE_MODEL');

        $this->assertSame(TriageClass::NotAnIssue, $verdict->class);
    }

    /**
     * On an open-source project the review is AI_REVIEW_MODEL_PUBLIC's: a triage model of that family is refused there
     *
     * @return void
     */
    public function testOpenSourceReviewFamilyIsRefused(): void
    {
        putenv('AI_REVIEW_MODEL_PUBLIC=openrouter/meta/muse-spark-1.3-contributor');
        putenv('KELPIE_TRIAGE_MODEL_PUBLIC=meta/muse-spark-1.3-contributor');
        $models  = TriageLlms::make($this->createStub(Logger::class), 'private', null, 'g/p', 'bsd-3-clause');
        $verdict = $this->service(new Psr7Response(500), $models)->triage($this->thread(), null, '', 'g/p');
        putenv('KELPIE_TRIAGE_MODEL_PUBLIC');
        putenv('AI_REVIEW_MODEL_PUBLIC');

        $this->assertSame(TriageClass::NeedsHuman, $verdict->class);
        $this->assertStringContainsString('same family as the review', $verdict->reason);
        $this->assertSame([], $this->sentModels);
    }

    /**
     * When the open-source model fails (OpenRouter's 404 for a data policy that excludes its tier) the regular model is asked
     *
     * @return void
     */
    public function testFailedOpenSourceModelFallsBack(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=google/gemini-3.8-flash');
        $models    = TriageLlms::make($this->createStub(Logger::class), 'private', null, 'g/p', 'bsd-3-clause');
        $responses = [new Psr7Response(404, [], '{"error":{"message":"No endpoints found matching your data policy"}}'), $this->answer('{"class":"not_an_issue","reason":"dismissed with a reason"}')];
        $verdict   = $this->service(null, $models, $responses)->triage($this->thread(), null, '', 'g/p');

        $this->assertSame(TriageClass::NotAnIssue, $verdict->class);
        $this->assertSame(['upstage/solar-pro-4-contributor', 'google/gemini-3.8-flash'], $this->sentModels);
    }

    /**
     * Without a project the visibility is unknown, so a training-tier model is refused
     *
     * @return void
     */
    public function testTrainingTierWithoutProjectIsRefused(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=meta/muse-spark-1.3-contributor');

        $verdict = $this->service($this->answer('{"class":"fix","confidence":"high","reason":"would be asked"}'))->triage($this->thread());
        putenv('KELPIE_TRIAGE_MODEL');

        $this->assertSame(TriageClass::NeedsHuman, $verdict->class);
        $this->assertStringContainsString('not known to be open source', $verdict->reason);
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
     * @param ?Psr7Response     $response  The canned response for every call; null to answer from `$responses`
     * @param ?TriageLlmService $models    The model chooser; a public project with no override when null
     * @param Psr7Response[]    $responses One response per call, in order
     *
     * @return TriageService
     */
    private function service(?Psr7Response $response, ?TriageLlmService $models = null, array $responses = []): TriageService
    {
        $logger          = $this->createStub(Logger::class);
        $json            = new JsonService($logger);
        $this->responses = array_values($responses);
        $httpClient      = $this->createStub(HttpClient::class);
        $httpClient->method('sendRequest')->willReturnCallback(function (Request $request) use ($response, $json): Psr7Response {
            $this->sentBody     = (string) $request->getBody();
            $sent               = $json->decode($this->sentBody);
            $this->sentModels[] = is_string($sent['model'] ?? null) ? $sent['model'] : '';

            return $response ?? array_shift($this->responses) ?? new Psr7Response(500);
        });

        return new TriageService(
            $logger,
            new OpenRouterClient($logger, $httpClient, new Psr17Factory(), new Psr17Factory(), $json),
            new TriagePrompt($logger),
            new SafetyPolicy($logger),
            $json,
            new LlmFamilyService($logger),
            $models ?? TriageLlms::make($logger),
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
