<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\GitlabClientFactory
 *
 * What must hold:
 * - Everything it builds talks to the settings' API URL with the bot's token
 * - Each call builds a new object
 *
 * @coversDefaultClass \Kelpie\GitLab\GitlabClientFactory
 */
final class GitlabClientFactoryTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * The requests the stubbed client saw
     *
     * @var list<Request>
     */
    private array $requests = [];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(GitlabClientFactory::class);
    }

    /**
     * The reader, writer, lister and log reader use the API URL and the bot token
     *
     * @return void
     */
    public function testBuildsOnTheSettings(): void
    {
        $factory = $this->factory();

        $factory->reader()->read(1, 2);
        $factory->writer()->headSha('1', 2);
        $factory->lister()->open('ubixsys/ubixkelpie');
        $factory->jobLogReader()->failedJobs(1, 2);

        $this->assertCount(4, $this->requests);
        foreach ($this->requests as $request) {
            $this->assertSame('gitlab.test', $request->getUri()->getHost());
            $this->assertSame('tok-bot', $request->getHeaderLine('PRIVATE-TOKEN'));
        }
    }

    /**
     * Each call builds a new object
     *
     * @return void
     */
    public function testBuildsNewObjects(): void
    {
        $factory = $this->factory();

        $this->assertNotSame($factory->writer(), $factory->writer());
        $this->assertNotSame($factory->client(), $factory->client());
    }

    /**
     * Build the factory over a stubbed network
     *
     * @return GitlabClientFactory
     */
    private function factory(): GitlabClientFactory
    {
        $this->requests = [];
        $logger         = $this->createStub(Logger::class);
        $http           = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(function (Request $request): Psr7Response {
            $this->requests[] = $request;

            return new Psr7Response(404);
        });

        return new GitlabClientFactory($logger, $http, new Psr17Factory(), new Psr17Factory(), new JsonService($logger), new AutofixSettings('https://gitlab.test/api/v4', 'tok-bot'));
    }
}
