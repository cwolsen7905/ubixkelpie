<?php

declare(strict_types=1);

namespace Kelpie\Tests\Console\Command;

use Kelpie\Console\Command\FixCommand;
use Kelpie\DataTransferObject\AutofixSettings;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Tester\CommandTester;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Console\Command\FixCommand
 *
 * What must hold:
 * - It is named kelpie:fix
 * - It hands its arguments and options to the service and prints the service's lines
 * - An error from the service is printed as an error and exits 1
 *
 * @coversDefaultClass \Kelpie\Console\Command\FixCommand
 */
final class FixCommandTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(FixCommand::class);
    }

    /**
     * It is discovered as kelpie:fix
     *
     * @return void
     */
    public function testCommandName(): void
    {
        $this->assertSame('kelpie:fix', $this->command()->getName());
    }

    /**
     * What the service refuses is printed as an error and the exit code is 1
     *
     * @return void
     */
    public function testPrintsRefusalAsError(): void
    {
        $tester = new CommandTester($this->command());

        $this->assertSame(1, $tester->execute(['mr' => '3', 'discussion' => 'd1abc123', '--project' => '1', '--path' => 'other/project']));
        $this->assertStringContainsString('not in KELPIE_AUTOFIX_PROJECTS', $tester->getDisplay());
    }

    /**
     * Valid arguments reach the service and its lines are printed
     *
     * @return void
     */
    public function testPrintsTheServicesLines(): void
    {
        $tester = new CommandTester($this->command(false));

        $this->assertSame(0, $tester->execute(['mr' => '3', 'discussion' => 'd1abc123', '--project' => '1', '--path' => 'ubixsys/ubixkelpie']));
        $this->assertStringContainsString('Autofix is disabled', $tester->getDisplay());
    }

    /**
     * Build the command over the real service
     *
     * @param bool $enabled Whether autofix is on
     *
     * @return FixCommand
     */
    private function command(bool $enabled = true): FixCommand
    {
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'tok-not-real', enabled: $enabled);

        return new FixCommand($this->createStub(Logger::class), (new CommandServices($this->createStub(Logger::class), $this->notFoundHttpClient(), $this->createStub(SqlService::class), $settings))->fix());
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
