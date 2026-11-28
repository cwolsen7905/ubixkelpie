<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Security\ContextScanner;
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
     * @var list<string> The bodies sent to the model
     */
    private array $prompts = [];

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
     * Each thread on a line is triaged with the code it is about, read at the MR's head with the read token
     *
     * @return void
     */
    public function testThreadsAreTriagedWithTheirCode(): void
    {
        $this->service()->triage('3', '1');

        $this->assertNotSame([], $this->prompts);
        $this->assertStringContainsString('at=\\"head9abc\\"', implode('', $this->prompts));
        $this->assertStringContainsString('renamed = 1', implode('', $this->prompts));
    }

    /**
     * Dummy settings
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->requests = [];
        $this->prompts  = [];
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
                $this->prompts[] = (string) $request->getBody();
                $json            = new JsonService($this->createStub(Logger::class));
                $content         = $json->encode(['class' => 'fix', 'confidence' => 'high', 'reason' => 'Rename it.']);

                return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]]]));
            }

            $uri = (string) $request->getUri();
            if (str_contains($uri, '/repository/files/')) {
                return new Psr7Response(200, [], "<?php\n\$renamed = 1;\n");
            }
            if (str_ends_with((string) $request->getUri()->getPath(), '/merge_requests/3')) {
                return new Psr7Response(200, [], '{"sha":"head9abc"}');
            }
            $page = (string) file_get_contents(__DIR__ . '/../../GitLab/fixtures/mr_discussions_page2.json');

            return new Psr7Response(200, [], $page);
        });
        $json = new JsonService($logger);

        $triage = new TriageService(
            $logger,
            new OpenRouterClient($logger, $httpClient, new Psr17Factory(), new Psr17Factory(), $json),
            new TriagePrompt($logger, new ContextScanner($logger)),
            new SafetyPolicy($logger),
            $json,
            new LlmFamilyService($logger),
            TriageLlms::make($logger),
        );

        return new TriageDryRunService($logger, $httpClient, new Psr17Factory(), $json, $triage, $settings);
    }
}
