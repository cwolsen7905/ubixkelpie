<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageDryRunService;
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
 * PHPUnit test case for \Kelpie\Service\Triage\TriageDryRunService
 *
 * What must hold:
 * - It refuses bad ids or missing GitLab settings without any request
 * - It returns one verdict per unresolved thread, from recorded GitLab and model responses
 * - The only requests it makes are GETs to GitLab and the one POST to the model
 *
 * @coversDefaultClass \Kelpie\Service\Triage\TriageDryRunService
 */
final class TriageDryRunServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
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
        $this->testClassFollowingUbixStandards(TriageDryRunService::class);
    }

    /**
     * Bad ids or missing settings fail without any request
     *
     * @return void
     */
    public function testRefusesBadInput(): void
    {
        $service = $this->service();
        $this->assertFalse($service->triage('12')->success);
        $this->assertFalse($service->triage('x', '1')->success);
        $this->assertTrue($service->triage('x', '1')->isError);
        $this->assertFalse($this->service(new AutofixSettings(apiUrl: 'https://gitlab.test/api/v4'))->triage('12', '1')->success);
        $this->assertSame([], $this->requests);
    }

    /**
     * The project defaults to the settings' CI project
     *
     * @return void
     */
    public function testProjectDefaultsToTheSettings(): void
    {
        $settings = new AutofixSettings('https://gitlab.test/api/v4', apiToken: 'tok-not-real', ciProjectId: '1');

        $this->assertTrue($this->service($settings)->triage('3')->success);
        $this->assertNotSame([], $this->requests);
    }

    /**
     * One verdict per unresolved thread, and no write to GitLab
     *
     * @return void
     */
    public function testReturnsVerdictPerUnresolvedThread(): void
    {
        $result = $this->service()->triage('3', '1');

        $this->assertTrue($result->success);
        $lines = implode("\n", $result->lines);
        $this->assertStringContainsString(str_repeat('e', 40), $lines);
        $this->assertStringContainsString('[fix]', $lines);
        $this->assertStringContainsString('    Rename it.', $lines);

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
        putenv('OPENROUTER_API_KEY');
        putenv('OPENROUTER_API_URL');
    }

    /**
     * Build the service over a stubbed network
     *
     * @param ?AutofixSettings $settings The settings; a configured read token when null
     *
     * @return TriageDryRunService
     */
    private function service(?AutofixSettings $settings = null): TriageDryRunService
    {
        $settings ??= new AutofixSettings('https://gitlab.test/api/v4', apiToken: 'tok-not-real');
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

        $triage = new TriageService(
            $logger,
            new OpenRouterClient($logger, $httpClient, new Psr17Factory(), new Psr17Factory(), $json),
            new TriagePrompt($logger),
            new SafetyPolicy($logger),
            $json,
            new LlmFamilyService($logger),
        );

        return new TriageDryRunService($logger, $httpClient, new Psr17Factory(), $json, $triage, $settings);
    }
}
