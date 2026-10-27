<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\ProposalReactionBuilderService;
use Kelpie\Tests\Repository\Project\InMemoryProjects;
use Kelpie\Tests\Sandbox\ScriptedProcessRunner;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\ProposalReactionBuilderService
 *
 * What must hold:
 * - It builds a reaction service for each project, each a new object
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\ProposalReactionBuilderService
 */
final class ProposalReactionBuilderServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProposalReactionBuilderService::class);
    }

    /**
     * A service per call
     *
     * @return void
     */
    public function testMakesAServicePerCall(): void
    {
        $logger   = $this->createStub(Logger::class);
        $sql      = $this->createStub(SqlService::class);
        $http     = $this->createStub(HttpClient::class);
        $json     = new JsonService($logger);
        $factory  = new Psr17Factory();
        $settings = new AutofixSettings('https://gitlab.test/api/v4', 'tok-bot', gitBaseUrl: 'https://gitlab.test');
        $outcomes = new ThreadOutcomeSqlRepository($logger, $sql);
        $builder  = new ProposalReactionBuilderService(
            $logger,
            new GitlabClientFactory($logger, $http, $factory, $factory, $json, $settings),
            new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql)),
            new ScriptedProcessRunner(),
            $outcomes,
            $outcomes,
            $json,
            $settings,
            InMemoryProjects::service(),
        );

        $this->assertNotSame($builder->make('ubixsys/ubixkelpie'), $builder->make('ubixsys/ubixkelpie'));
    }
}
