<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Console\Command\RespondCommand;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Tests\GitLab\FakeGitlab;
use Kelpie\Triage\SafetyPolicy;
use Kelpie\Triage\TriagePrompt;
use Kelpie\Triage\TriageService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\RespondCommand
 *
 * What must hold:
 * - It is named kelpie:respond and refuses bad ids, missing settings and projects off the allow-list
 * - It answers an unresolved thread and reports the outcome per thread
 * - It never sends a DELETE
 *
 * @coversDefaultClass \Kelpie\Console\Command\RespondCommand
 */
final class RespondCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
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
        $this->testClassFollowingUbixStandards(RespondCommand::class);
    }

    /**
     * It is discovered as kelpie:respond
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:respond', $this->command()->getName());
    }

    /**
     * Bad input, no token and an unlisted project fail without a request
     *
     * @return void
     */
    public function testRefusesBadInput(): void
    {
        $tester = new CommandTester($this->command());
        $this->assertSame(1, $tester->execute(['mr' => 'x', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));
        $this->assertSame(1, $tester->execute(['mr' => '3', '--project' => '1', '--path' => 'other/project']));

        putenv('KELPIE_BOT_GITLAB_TOKEN');
        $this->assertSame(1, $tester->execute(['mr' => '3', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));
        $this->assertSame([], $this->gitlab->requests());
    }

    /**
     * A needs_human thread is answered and the outcome printed
     *
     * @return void
     */
    public function testAnswersAThread(): void
    {
        $this->gitlab->note('d1', 'Should this be redesigned?');
        $tester = new CommandTester($this->command());

        $this->assertSame(0, $tester->execute(['mr' => '3', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));

        $this->assertStringContainsString('d1  replied', $tester->getDisplay());
        $this->assertSame(['kelpie::needs-human'], $this->gitlab->labels());
        foreach ($this->gitlab->requests() as $request) {
            $this->assertStringStartsNotWith('DELETE', $request);
        }
    }

    /**
     * Dummy settings
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->gitlab = new FakeGitlab();
        putenv('GITLAB_API_URL=https://gitlab.test/api/v4');
        putenv('KELPIE_BOT_GITLAB_TOKEN=tok-not-real');
        putenv('OPENROUTER_API_KEY=test-key-not-real');
        putenv('OPENROUTER_API_URL=https://openrouter.test/api/v1');
    }

    /**
     * Clean the environment
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (['GITLAB_API_URL', 'KELPIE_BOT_GITLAB_TOKEN', 'OPENROUTER_API_KEY', 'OPENROUTER_API_URL'] as $name) {
            putenv($name);
        }
    }

    /**
     * Build the command over the fake GitLab and a model that always says needs_human
     *
     * @return RespondCommand
     */
    private function command(): RespondCommand
    {
        $logger = $this->createStub(Logger::class);
        $json   = new JsonService($logger);
        $gitlab = $this->gitlab;
        $http   = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(static function (Request $request) use ($gitlab, $json): Psr7Response {
            if (str_contains((string) $request->getUri(), 'openrouter.test')) {
                $content = $json->encode(['class' => 'needs_human', 'reason' => 'Design call.']);

                return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]]]));
            }

            $response = $gitlab->sendRequest($request);

            return new Psr7Response($response->getStatusCode(), [], (string) $response->getBody());
        });

        $service = new TriageService(
            $logger,
            new OpenRouterClient($logger, $http, new Psr17Factory(), new Psr17Factory(), $json),
            new TriagePrompt($logger),
            new SafetyPolicy($logger),
            $json,
        );

        return new RespondCommand($logger, $http, new Psr17Factory(), new Psr17Factory(), $json, $service);
    }
}
