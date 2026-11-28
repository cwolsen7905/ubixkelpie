<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\IssueDetails;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Security\ContextScanner;
use Kelpie\Service\Autofix\IssueFixPromptService;
use Kelpie\Service\Autofix\IssueProposeEditsService;
use Kelpie\Service\Autofix\ProposeEditsService;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Tests\Service\Triage\TriageLlms;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\IssueProposeEditsService
 *
 * What must hold:
 * - The model is asked twice: which files, then the edits; the cost is the sum of both
 * - Only paths from the checkout are read, dependencies and `.git` are not listed
 * - Anything unusable comes back as a failure reason and no edits
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\IssueProposeEditsService
 */
final class IssueProposeEditsServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $dir = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueProposeEditsService::class);
    }

    /**
     * The edits come back with the summed cost
     *
     * @return void
     */
    public function testProposesEditsInTwoCalls(): void
    {
        $http   = new ScriptedModelHttp(['{"files":["src/a.php","../etc/passwd","nope.php"]}', '{"summary":"Fix typo","edits":[{"file":"src/a.php","old":"teh","new":"the"}]}']);
        $result = $this->service($http)->propose($this->issue(), $this->dir);

        $this->assertSame('', $result->failure);
        $this->assertSame('Fix typo', $result->summary);
        $this->assertSame('src/a.php', $result->edits[0]->file);
        $this->assertNotNull($result->completion);
        $this->assertSame(100, $result->completion->promptTokens);
        $this->assertEqualsWithDelta(0.02, (float) $result->completion->costUsd, 0.0001);
        $this->assertCount(2, $http->sent());
        $this->assertStringContainsString("src/a.php\n", $http->sent()[0]);
        $this->assertStringNotContainsString('vendor/x.php', $http->sent()[0]);
        $this->assertStringNotContainsString('.git/', $http->sent()[0]);
        $this->assertStringContainsString('<file path="src/a.php">', $http->sent()[1]);
    }

    /**
     * A model that names no listed file stops after the first call
     *
     * @return void
     */
    public function testNoFileNamed(): void
    {
        $http   = new ScriptedModelHttp(['{"files":["../secret"]}']);
        $result = $this->service($http)->propose($this->issue(), $this->dir);

        $this->assertSame([], $result->edits);
        $this->assertStringContainsString('named no file', $result->failure);
        $this->assertNotNull($result->completion);
        $this->assertCount(1, $http->sent());
    }

    /**
     * No answer at all has no completion, so the caller can tell it from a refusal
     *
     * @return void
     */
    public function testNoAnswer(): void
    {
        $this->assertNull($this->service(new ScriptedModelHttp([]))->propose($this->issue(), $this->dir)->completion);

        $second = $this->service(new ScriptedModelHttp(['{"files":["src/a.php"]}']))->propose($this->issue(), $this->dir);
        $this->assertSame('The propose model gave no answer.', $second->failure);
        $this->assertNotNull($second->completion);
    }

    /**
     * An unreadable answer and an empty edit list are failures that keep the cost
     *
     * @return void
     */
    public function testUnusableAnswers(): void
    {
        $garbled = $this->service(new ScriptedModelHttp(['{"files":["src/a.php"]}', 'not json']))->propose($this->issue(), $this->dir);
        $this->assertStringContainsString('could not read', $garbled->failure);
        $this->assertNotNull($garbled->completion);

        $none = $this->service(new ScriptedModelHttp(['{"files":["src/a.php"]}', '{"summary":"needs a design call","edits":[]}']))->propose($this->issue(), $this->dir);
        $this->assertStringContainsString('needs a design call', $none->failure);
    }

    /**
     * A file over the size limit is not shown
     *
     * @return void
     */
    public function testLargeFilesAreNotShown(): void
    {
        file_put_contents($this->dir . '/big.php', str_repeat('x', 70000));
        $http   = new ScriptedModelHttp(['{"files":["big.php"]}']);
        $result = $this->service($http)->propose($this->issue(), $this->dir);

        $this->assertStringContainsString('too large', $result->failure);
        $this->assertCount(1, $http->sent());
    }

    /**
     * An empty or missing checkout has nothing to read
     *
     * @return void
     */
    public function testNothingToRead(): void
    {
        $result = $this->service(new ScriptedModelHttp([]))->propose($this->issue(), $this->dir . '/missing');

        $this->assertStringContainsString('no files', $result->failure);
    }

    /**
     * Fixture: a checkout with a source file, a dependency and a .git folder
     *
     * @return void
     */
    protected function setUp(): void
    {
        putenv('OPENROUTER_API_KEY=test-key');
        $this->dir = sys_get_temp_dir() . '/kelpie-ipe-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/src', 0700, true);
        mkdir($this->dir . '/vendor');
        mkdir($this->dir . '/.git');
        file_put_contents($this->dir . '/src/a.php', "<?php // teh typo\n");
        file_put_contents($this->dir . '/vendor/x.php', 'x');
        file_put_contents($this->dir . '/.git/config', 'x');
    }

    /**
     * Remove the fixture
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('OPENROUTER_API_KEY');
        if (str_contains($this->dir, 'kelpie-ipe-test-')) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /**
     * @return IssueDetails
     */
    private function issue(): IssueDetails
    {
        return new IssueDetails('Typo', 'There is a typo in src/a.php', 'alice', false);
    }

    /**
     * @param ScriptedModelHttp $http The scripted model
     *
     * @return IssueProposeEditsService
     */
    private function service(ScriptedModelHttp $http): IssueProposeEditsService
    {
        $logger  = $this->createStub(Logger::class);
        $json    = new JsonService($logger);
        $factory = new Psr17Factory();
        $client  = new OpenRouterClient($logger, $http, $factory, $factory, $json);
        $propose = new ProposeEditsService($logger, $client, new ProposePrompt($logger, new ContextScanner($logger)), new LlmFamilyService($logger), $json, TriageLlms::make($logger));

        return new IssueProposeEditsService($logger, $client, new IssueFixPromptService($logger, new ContextScanner($logger)), $propose, $json);
    }
}
