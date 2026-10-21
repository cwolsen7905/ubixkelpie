<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\TriagePrompt;
use Kelpie\Service\Autofix\RespondService;
use Kelpie\Service\Triage\TriageService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\RespondService
 *
 * What must hold:
 * - It refuses bad ids, missing settings and projects off the allow-list, before any request
 * - The project and path default to the settings' CI values
 * - It answers an unresolved thread and reports the outcome per thread
 * - It never sends a DELETE
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\RespondService
 */
final class RespondServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
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
        $this->testClassFollowingUbixStandards(RespondService::class);
    }

    /**
     * Bad input, no token and an unlisted project fail without a request
     *
     * @return void
     */
    public function testRefusesBadInput(): void
    {
        $service = $this->service();
        $this->assertFalse($service->respond('x', '1', 'ubixsys/ubixkelpie')->success);
        $this->assertFalse($service->respond('3', '1', 'other/project')->success);
        $this->assertTrue($service->respond('3', '1', 'other/project')->isError);

        $this->assertFalse($this->service(new AutofixSettings(apiUrl: 'https://gitlab.test/api/v4'))->respond('3', '1', 'ubixsys/ubixkelpie')->success);
        $this->assertSame([], $this->gitlab->requests());
    }

    /**
     * Missing --project and --path fall back to the settings
     *
     * @return void
     */
    public function testDefaultsComeFromTheSettings(): void
    {
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real', ciProjectId: 'x', ciProjectPath: 'ubixsys/ubixkelpie');

        $result = $this->service($settings)->respond('3');

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must be numbers', $result->lines[0]);
        $this->assertSame([], $this->gitlab->requests());
    }

    /**
     * A needs_human thread is answered and the outcome reported
     *
     * @return void
     */
    public function testAnswersAThread(): void
    {
        $this->gitlab->note('d1', 'Should this be redesigned?');

        $result = $this->service()->respond('3', '1', 'ubixsys/ubixkelpie');

        $this->assertTrue($result->success);
        $this->assertSame(['d1  replied'], $result->lines);
        $this->assertSame(['kelpie::needs-human', 'triage: needs you'], $this->gitlab->labels());
        foreach ($this->gitlab->requests() as $request) {
            $this->assertStringStartsNotWith('DELETE', $request);
        }
    }

    /**
     * With no unresolved thread it says so
     *
     * @return void
     */
    public function testNoThreads(): void
    {
        $this->assertSame(['No unresolved threads.'], $this->service()->respond('3', '1', 'ubixsys/ubixkelpie')->lines);
    }

    /**
     * A fresh fake GitLab for each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->gitlab = new FakeGitlab();
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
     * Build the service over the fake GitLab and a model that always says needs_human
     *
     * @param ?AutofixSettings $settings The settings; a configured bot on ubixsys/ubixkelpie when null
     *
     * @return RespondService
     */
    private function service(?AutofixSettings $settings = null): RespondService
    {
        $settings ??= new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real');
        $logger     = $this->createStub(Logger::class);
        $json       = new JsonService($logger);
        $gitlab     = $this->gitlab;
        $http       = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(static function (Request $request) use ($gitlab, $json): Psr7Response {
            if (str_contains((string) $request->getUri(), 'openrouter.test')) {
                $content = $json->encode(['class' => 'needs_human', 'reason' => 'Design call.']);

                return new Psr7Response(200, [], $json->encode(['choices' => [['message' => ['content' => $content]]]]));
            }

            $response = $gitlab->sendRequest($request);

            return new Psr7Response($response->getStatusCode(), [], (string) $response->getBody());
        });

        $triage = new TriageService(
            $logger,
            new OpenRouterClient($logger, $http, new Psr17Factory(), new Psr17Factory(), $json),
            new TriagePrompt($logger),
            new SafetyPolicy($logger),
            $json,
        );

        return new RespondService($logger, new GitlabClientFactory($logger, $http, new Psr17Factory(), new Psr17Factory(), $json, $settings), $triage, $settings);
    }
}
