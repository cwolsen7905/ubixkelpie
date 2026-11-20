<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Service\Triage\TriageLlmService;
use Kelpie\Tests\Repository\Project\InMemoryProjects;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;

/**
 * Builds a TriageLlmService for tests over a fake GitLab project API
 *
 * By default the project has no license (not open source) and no override, so the model is `KELPIE_TRIAGE_MODEL` or
 * the caller's default.
 */
final class TriageLlms
{
    /**
     * A model service whose GitLab answers every project read with one path and visibility
     *
     * @param Logger         $logger     Logger
     * @param ?string        $visibility The visibility GitLab reports; null makes the read fail (HTTP 404)
     * @param ?ProjectReader $projects   The `projects` table; none when null
     * @param string         $path       The path GitLab reports
     * @param string         $license    The license key GitLab reports detecting; empty for none
     *
     * @return TriageLlmService
     */
    public static function make(Logger $logger, ?string $visibility = 'public', ?ProjectReader $projects = null, string $path = 'g/p', string $license = ''): TriageLlmService
    {
        $http = new class ($visibility, $path, new JsonService($logger), $license) implements HttpClient {
            /**
             * Constructor
             *
             * @param ?string     $visibility The visibility to report; null for a 404
             * @param string      $path       The path to report
             * @param JsonService $json       JSON service
             * @param string      $license    The license key to report; empty for none
             */
            public function __construct(
                private readonly ?string $visibility,
                private readonly string $path,
                private readonly JsonService $json,
                private readonly string $license,
            ) {
            }

            /**
             * {@inheritDoc}
             */
            public function sendRequest(Request $request): Response // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- every project read gets the same answer
            {
                if ($this->visibility === null) {
                    return new Psr7Response(404, [], '{"message":"404 Project Not Found"}');
                }

                return new Psr7Response(200, [], $this->json->encode(['path_with_namespace' => $this->path, 'visibility' => $this->visibility, 'license' => $this->license === '' ? null : ['key' => $this->license]]));
            }
        };

        $factory = new Psr17Factory();
        $gitlab  = new GitlabClientFactory($logger, $http, $factory, $factory, new JsonService($logger), new AutofixSettings('https://gitlab.test/api/v4', 'tok-bot'));

        return new TriageLlmService($logger, $projects ?? new InMemoryProjects(), $gitlab);
    }
}
