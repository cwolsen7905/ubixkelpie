<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Console\Command\TriageCommand;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Service\Triage\TriageService;
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
 * PHPUnit test case for \Kelpie\Console\Command\TriageCommand
 *
 * What must hold:
 * - It is named kelpie:triage and refuses to run without ids or GitLab settings
 * - It prints one verdict per unresolved thread, from recorded GitLab and model responses
 * - The only requests it makes are GETs to GitLab and the one POST to the model
 *
 * @coversDefaultClass \Kelpie\Console\Command\TriageCommand
 */
final class TriageCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * "METHOD URL" of every request the stubbed client saw
     *
     * @var list<string>
     */
    private array $requests = [];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageCommand::class);
    }

    /**
     * It is discovered as kelpie:triage
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:triage', $this->command()->getName());
    }

    /**
     * Bad ids or missing settings fail without any request
     *
     * @return void
     */
    public function testRefusesBadInput(): void
    {
        $tester = new CommandTester($this->command());
        $this->assertSame(1, $tester->execute(['mr' => '12']));
        $this->assertSame(1, $tester->execute(['mr' => 'x', '--project' => '1']));

        putenv('GITLAB_API_TOKEN');
        $this->assertSame(1, $tester->execute(['mr' => '12', '--project' => '1']));
        $this->assertSame([], $this->requests);
    }

    /**
     * One verdict per unresolved thread, and no write to GitLab
     *
     * @return void
     */
    public function testPrintsVerdictPerUnresolvedThread(): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(0, $tester->execute(['mr' => '3', '--project' => '1']));

        $display = $tester->getDisplay();
        $this->assertStringContainsString(str_repeat('e', 40), $display);
        $this->assertStringContainsString('[fix]', $display);
        $this->assertStringContainsString('Rename it.', $display);

        $gitlab = 0;
        foreach ($this->requests as $request) {
            if (str_contains($request, 'gitlab.test')) {
                $this->assertStringStartsWith('GET ', $request);
                ++$gitlab;
            }
        }

        $this->assertGreaterThan(0, $gitlab);
    }

    /**
     * Dummy settings
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->requests = [];
        putenv('GITLAB_API_URL=https://gitlab.test/api/v4');
        putenv('GITLAB_API_TOKEN=tok-not-real');
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
        foreach (['GITLAB_API_URL', 'GITLAB_API_TOKEN', 'OPENROUTER_API_KEY', 'OPENROUTER_API_URL', 'CI_PROJECT_ID'] as $name) {
            putenv($name);
        }
    }

    /**
     * Build the command over a stubbed network
     *
     * @return TriageCommand
     */
    private function command(): TriageCommand
    {
        $logger     = $this->createStub(Logger::class);
        $httpClient = $this->createStub(HttpClient::class);
        $httpClient->method('sendRequest')->willReturnCallback(function (Request $request): Psr7Response {
            $this->requests[] = $request->getMethod() . ' ' . $request->getUri();

            if (str_contains((string) $request->getUri(), 'openrouter.test')) {
                $json    = new JsonService($this->createStub(Logger::class));
                $content = $json->encode(['class' => 'fix', 'reason' => 'Rename it.']);

                return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]]]));
            }

            $page = (string) file_get_contents(__DIR__ . '/../../GitLab/fixtures/mr_discussions_page2.json');

            return new Psr7Response(200, [], $page);
        });
        $json = new JsonService($logger);

        $service = new TriageService(
            $logger,
            new OpenRouterClient($logger, $httpClient, new Psr17Factory(), new Psr17Factory(), $json),
            new TriagePrompt($logger),
            new SafetyPolicy($logger),
            $json,
        );

        return new TriageCommand($logger, $httpClient, new Psr17Factory(), $json, $service);
    }
}
