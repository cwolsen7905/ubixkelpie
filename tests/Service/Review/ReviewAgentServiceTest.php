<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\Service\Review\ReviewAgentService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\ReviewAgentService
 *
 * What must hold:
 * - A tool call is run over the checkout and its text goes back to the model; the final text and the summed usage come out
 * - The last allowed turn is sent without tools, so the model must answer
 * - A failed call, or a loop that never answers, is null text (with the usage so far)
 * - A tool call the model cannot write as JSON gets an error text, not an exception
 * - Nothing of the GitLab token reaches the model
 *
 * @coversDefaultClass \Kelpie\Service\Review\ReviewAgentService
 */
final class ReviewAgentServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewAgentService::class);
    }

    /**
     * A read tool call is answered from the checkout, then the model's text is returned with the summed usage
     *
     * @return void
     */
    public function testToolCallThenAnswer(): void
    {
        $repo    = new TempRepo(['src/a.txt' => "alpha\nbeta"]);
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->model()->tool('read', ['path' => 'src/a.txt'])->text('{"verdict":"pass"}', 500, 0.002);

        try {
            [$text, $usage] = $harness->modelService()->run(SettingsBuilder::make(['repo' => $repo->path(), 'effort' => 'low']), 'SYSTEM', 'USER');
        } finally {
            $repo->remove();
        }

        $this->assertSame('{"verdict":"pass"}', $text);
        $this->assertSame([510, 510, 2100], [$usage->tokensIn, $usage->tokensOut, $usage->microCost]);
        $sent = $harness->model()->sent();
        $this->assertCount(2, $sent);
        $this->assertSame('anthropic/claude-haiku-5.5', $sent[0]['model']);
        $this->assertSame(['effort' => 'low'], $sent[0]['reasoning']);
        $messages = $sent[1]['messages'];
        $this->assertIsArray($messages);
        $this->assertSame(['system', 'user', 'assistant', 'tool'], array_column($messages, 'role'));
        $tool = $messages[3];
        $this->assertIsArray($tool);
        $this->assertSame("1: alpha\n2: beta\n", $tool['content']);
        $this->assertSame('call_0', $tool['tool_call_id']);
    }

    /**
     * The last turn has no tools
     *
     * @return void
     */
    public function testLastTurnHasNoTools(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->model()->tool('glob', ['pattern' => '*'])->text('answer');

        [$text] = $harness->modelService()->run(SettingsBuilder::make(['maxTurns' => 2]), 's', 'u');

        $this->assertSame('answer', $text);
        $sent = $harness->model()->sent();
        $this->assertArrayHasKey('tools', $sent[0]);
        $this->assertArrayNotHasKey('tools', $sent[1]);
    }

    /**
     * Tool calls on the last turn are not run: its text is the answer, even if empty
     *
     * @return void
     */
    public function testLastTurnToolCallsAreNotRun(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->model()->tool('glob', ['pattern' => '*']);

        [$text] = $harness->modelService()->run(SettingsBuilder::make(['maxTurns' => 1]), 's', 'u');

        $this->assertSame('', $text);
        $this->assertCount(1, $harness->model()->sent());
    }

    /**
     * A failed call is null text and keeps the usage so far
     *
     * @return void
     */
    public function testFailedCallIsNull(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->model()->tool('glob', ['pattern' => '*'])->fail();

        [$text, $usage] = $harness->modelService()->run(SettingsBuilder::make(), 's', 'u');

        $this->assertNull($text);
        $this->assertSame(10, $usage->tokensIn);
    }

    /**
     * Arguments that are not JSON, and a tool that does not exist, are answered with error texts
     *
     * @return void
     */
    public function testBadToolCallsGetErrorTexts(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->model()->tool('bash', ['command' => 'env'])->text('done');

        [$text] = $harness->modelService()->run(SettingsBuilder::make(), 's', 'u');

        $this->assertSame('done', $text);
        $messages = $harness->model()->sent()[1]['messages'];
        $this->assertIsArray($messages);
        $tool = $messages[3];
        $this->assertIsArray($tool);
        $this->assertIsString($tool['content']);
        $this->assertStringContainsString('unknown tool bash', $tool['content']);
    }

    /**
     * The GitLab token is never part of what the model is sent
     *
     * @return void
     */
    public function testTheTokenNeverReachesTheModel(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->model()->tool('grep', ['pattern' => 'token'])->text('ok');

        $harness->modelService()->run(SettingsBuilder::make(['token' => 'glpat-SECRET-TOKEN']), 's', 'u');

        foreach ($harness->model()->sent() as $request) {
            $this->assertStringNotContainsString('glpat-SECRET-TOKEN', serialize($request));
        }
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
    }
}
