<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Console\Command\TriageCommand;
use Kelpie\DataTransferObject\AutofixSettings;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\TriageCommand
 *
 * What must hold:
 * - It is named kelpie:triage
 * - It hands its arguments and options to the service and prints the service's lines
 * - An error from the service is printed as an error and exits 1
 *
 * @coversDefaultClass \Kelpie\Console\Command\TriageCommand
 */
final class TriageCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
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
     * What the service refuses is printed as an error and the exit code is 1
     *
     * @return void
     */
    public function testPrintsRefusalAsError(): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(1, $tester->execute(['mr' => 'x', '--project' => '1']));
        $this->assertStringContainsString('must be numbers', $tester->getDisplay());
    }

    /**
     * Valid arguments reach the service and its lines are printed
     *
     * @return void
     */
    public function testPrintsTheServicesLines(): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(0, $tester->execute(['mr' => '3', '--project' => '1']));
        $this->assertStringContainsString('No unresolved threads.', $tester->getDisplay());
    }

    /**
     * Build the command over the real service
     *
     * @return TriageCommand
     */
    private function command(): TriageCommand
    {
        $settings = new AutofixSettings('https://gitlab.test/api/v4', apiToken: 'tok-not-real');

        return new TriageCommand($this->createStub(Logger::class), (new CommandServices($this->createStub(Logger::class), $this->notFoundHttpClient(), $this->createStub(SqlService::class), $settings))->triage());
    }

    /**
     * An HTTP client that answers 404 to everything
     *
     * @return HttpClient
     */
    private function notFoundHttpClient(): HttpClient
    {
        $http = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturn(new Psr7Response(404));

        return $http;
    }
}
