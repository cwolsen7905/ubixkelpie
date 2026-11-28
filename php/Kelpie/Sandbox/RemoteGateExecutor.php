<?php

declare(strict_types=1);

namespace Kelpie\Sandbox;

use Kelpie\DataTransferObject\GateRun;
use Kelpie\DataTransferObject\GateStepResult;
use Kelpie\Sandbox\GateExecutorInterface as GateExecutor;
use Kelpie\Security\ActionGuard;
use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Http\Message\StreamInterface as Stream;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Runs the gate's steps in the gate pod: packs the checkout (vendor/ included), sends it with the steps, reads the answer
 *
 * Fails closed: when the checkout cannot be packed, the pod cannot be reached or times out, or it answers with anything
 * but a 200 and a well-formed result, the run is "gate unavailable" and never a pass. The answer is only data (exit
 * codes, a flag, capped output); nothing in it is run here. While the pod cannot be reached at all (it restarts after
 * every run) the request is retried for up to `$waitSeconds`. The protocol is in docs/security/gate-pod.md.
 *
 * @see \Kelpie\Tests\Sandbox\RemoteGateExecutorTest PHPUnit test case
 */
final class RemoteGateExecutor implements GateExecutor
{
    public const string STEPS_HEADER = 'X-Kelpie-Gate-Steps';

    public const string RUN_PATH = '/run';

    private const int MAX_ANSWER_BYTES = 1048576;

    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The HTTP client, with a timeout longer than a whole gate
     * @param RequestFactory $requestFactory Request factory
     * @param StreamFactory  $streamFactory  Stream factory
     * @param JsonService    $jsonService    JSON service
     * @param TarArchive     $archive        Packs the checkout
     * @param ActionGuard    $guard          Checks each step is a gate step before anything is sent
     * @param string         $url            The gate pod's base URL (KELPIE_GATE_URL), e.g. http://kelpie-gate:8090
     * @param int            $waitSeconds    How long to keep retrying while the pod cannot be reached
     * @param int            $retryPause     Seconds between attempts
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly StreamFactory $streamFactory,
        private readonly JsonService $jsonService,
        private readonly TarArchive $archive,
        private readonly ActionGuard $guard,
        private readonly string $url,
        private readonly int $waitSeconds = 300,
        private readonly int $retryPause = 5,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, string[]> $steps Each step's program and arguments, in order
     */
    public function run(string $directory, array $steps, int $timeoutSeconds): GateRun
    {
        foreach ($steps as $argv) {
            $refusal = $this->guard->gateRefusal($argv);
            if ($refusal !== null) {
                return new GateRun([], 'Refused: ' . $refusal);
            }
        }
        if ($steps === []) {
            return new GateRun([], 'gate unavailable: no steps to run');
        }

        $archive = tempnam(sys_get_temp_dir(), 'kelpie-gate-');
        if ($archive === false) {
            return new GateRun([], 'gate unavailable: could not pack the checkout');
        }

        try {
            if ($this->archive->pack($directory, $archive, ['.git']) !== '') {
                return new GateRun([], 'gate unavailable: could not pack the checkout');
            }

            return $this->send($archive, $steps, $timeoutSeconds);
        } finally {
            unlink($archive);
        }
    }

    /**
     * Send the archive and the steps, retrying while the pod cannot be reached, and read the answer
     *
     * @param string               $archive        The packed checkout
     * @param array<int, string[]> $steps          The steps
     * @param int                  $timeoutSeconds Each step's limit
     *
     * @return GateRun
     */
    private function send(string $archive, array $steps, int $timeoutSeconds): GateRun
    {
        $header   = $this->jsonService->encode(['steps' => $steps, 'timeout' => $timeoutSeconds], JSON_UNESCAPED_SLASHES);
        $deadline = microtime(true) + $this->waitSeconds;

        while (true) {
            $request = $this->requestFactory->createRequest('POST', rtrim($this->url, '/') . self::RUN_PATH)
                ->withHeader('Content-Type', 'application/gzip')
                ->withHeader(self::STEPS_HEADER, $header)
                ->withBody($this->streamFactory->createStreamFromFile($archive, 'rb'));

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientException $e) {
                $this->logger->warning('Gate pod not reached', ['error' => $e->getMessage()]);
                if (microtime(true) + $this->retryPause >= $deadline) {
                    return new GateRun([], 'gate unavailable: no answer from the gate pod');
                }
                sleep($this->retryPause);

                continue;
            }

            $body = $this->readCapped($response->getBody());
            if ($body === null) {
                return new GateRun([], 'gate unavailable: the gate pod\'s answer is too large');
            }
            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('Gate pod refused the run', ['status' => $response->getStatusCode()]);

                return new GateRun([], 'gate unavailable: the gate pod answered HTTP ' . $response->getStatusCode() . $this->errorOf($body));
            }

            $results = $this->results($body, count($steps));
            if ($results === null) {
                $this->logger->warning('Gate pod answer malformed');

                return new GateRun([], 'gate unavailable: the gate pod\'s answer is malformed');
            }

            return new GateRun($results);
        }
    }

    /**
     * The body, or null when it is larger than the cap
     *
     * @param Stream $stream The body
     *
     * @return ?string
     */
    private function readCapped(Stream $stream): ?string
    {
        $body   = '';
        $length = 0;
        while (!$stream->eof() && $length <= self::MAX_ANSWER_BYTES) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $body  .= $chunk;
            $length = strlen($body);
        }

        return $length > self::MAX_ANSWER_BYTES ? null : $body;
    }

    /**
     * The step results of a 200 answer, or null when it is not exactly what a run gives
     *
     * One entry per step that ran, in order; each before the last passed; fewer than were sent only when the last failed.
     *
     * @param string $body  The answer
     * @param int    $steps How many steps were sent
     *
     * @return ?GateStepResult[]
     */
    private function results(string $body, int $steps): ?array
    {
        try {
            $decoded = $this->jsonService->decode($body);
        } catch (DtoException $e) {
            $this->logger->debug('Gate pod answer is not JSON', ['error' => $e->getMessage()]);

            return null;
        }

        $entries = $decoded['steps'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || $entries === [] || count($entries) > $steps) {
            return null;
        }

        $results = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_int($entry['exit_code'] ?? null) || !is_bool($entry['timed_out'] ?? null) || !is_string($entry['output'] ?? null)) {
                return null;
            }
            $passed = $entry['exit_code'] === 0 && !$entry['timed_out'];
            if ($results !== [] && !$this->passed($results[count($results) - 1])) {
                return null;
            }
            if (!$passed && $entry['exit_code'] === 0) {
                $entry['exit_code'] = -1;
            }
            $results[] = new GateStepResult($entry['exit_code'], $entry['timed_out'], substr($entry['output'], -self::MAX_OUTPUT));
        }

        $last = $results[count($results) - 1];

        return count($results) === $steps || !$this->passed($last) ? $results : null;
    }

    /**
     * Whether a step passed
     *
     * @param GateStepResult $result The step
     *
     * @return bool
     */
    private function passed(GateStepResult $result): bool
    {
        return $result->exitCode === 0 && !$result->timedOut;
    }

    /**
     * The pod's own reason in a refusal, printable and short, as ": <reason>"; empty when there is none
     *
     * @param string $body The answer
     *
     * @return string
     */
    private function errorOf(string $body): string
    {
        try {
            $error = $this->jsonService->decode($body)['error'] ?? null;
        } catch (DtoException $e) {
            $this->logger->debug('Gate pod refusal is not JSON', ['error' => $e->getMessage()]);

            return '';
        }
        if (!is_string($error) || $error === '') {
            return '';
        }
        $printable = (string) preg_replace('/[^\x20-\x7e]/', '?', $error);

        return ': ' . substr($printable, 0, 200);
    }
}
