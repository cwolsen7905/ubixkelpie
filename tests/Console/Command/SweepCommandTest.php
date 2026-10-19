<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Console\Command\SweepCommand;
use Kelpie\DataTransferObject\AutofixSettings;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\SweepCommand
 *
 * What must hold:
 * - It is named kelpie:sweep
 * - It hands its arguments and options to the service and prints the service's lines
 * - An error from the service is printed as an error and exits 1
 *
 * @coversDefaultClass \Kelpie\Console\Command\SweepCommand
 */
final class SweepCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(SweepCommand::class);
    }

    /**
     * It is discovered as kelpie:sweep
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:sweep', $this->command()->getName());
    }

    /**
     * What the service refuses is printed as an error and the exit code is 1
     *
     * @return void
     */
    public function testPrintsRefusalAsError(): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('must be set', $tester->getDisplay());
    }

    /**
     * Valid arguments reach the service and its lines are printed
     *
     * @return void
     */
    public function testPrintsTheServicesLines(): void
    {
        $tester = new CommandTester($this->command(true));

        $this->assertSame(0, $tester->execute(['--max-runs' => '2']));
        $this->assertStringContainsString('Nothing needs resolution.', $tester->getDisplay());
    }

    /**
     * Build the command over the real service
     *
     * @param bool $configured Whether GitLab is configured
     *
     * @return SweepCommand
     */
    private function command(bool $configured = false): SweepCommand
    {
        $settings = $configured ? new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real') : new AutofixSettings();

        return new SweepCommand($this->createStub(Logger::class), (new CommandServices($this->createStub(Logger::class), $this->notFoundHttpClient(), $this->createStub(SqlService::class), $settings))->sweep());
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
