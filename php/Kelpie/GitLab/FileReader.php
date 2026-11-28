<?php

declare(strict_types=1);

namespace Kelpie\GitLab;

use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;

/**
 * Reads one file of a repository at a commit, raw, with the bot token
 *
 * @see \Kelpie\Tests\GitLab\FileReaderTest PHPUnit test case
 */
final class FileReader
{
    /**
     * Files larger than this are not read: triage needs the code near a line, not a bundle
     */
    public const int MAX_BYTES = 512 * 1024;

    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     The bot's HTTP client
     * @param RequestFactory $requestFactory PSR-17 request factory
     * @param string         $apiUrl         The GitLab API URL, no trailing slash
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly string $apiUrl,
    ) {
    }

    /**
     * The file's text at the commit, or null when it cannot be read (missing, too large, a failure)
     *
     * @param string $project The project ID or path
     * @param string $path    The file's path in the repository
     * @param string $sha     The commit
     *
     * @return ?string
     */
    public function read(string $project, string $path, string $sha): ?string
    {
        $url = sprintf('%s/projects/%s/repository/files/%s/raw?ref=%s', $this->apiUrl, rawurlencode($project), rawurlencode($path), rawurlencode($sha));

        try {
            $response = $this->httpClient->sendRequest($this->requestFactory->createRequest('GET', $url));
        } catch (ClientException $e) {
            $this->logger->warning('GitLab file: request failed', ['error' => $e->getMessage(), 'path' => $path]);

            return null;
        }
        if ($response->getStatusCode() !== 200) {
            $this->logger->info('GitLab file: not read', ['status' => $response->getStatusCode(), 'path' => $path]);

            return null;
        }
        $contentLength = $response->getHeaderLine('Content-Length');
        if (ctype_digit($contentLength) && (int) $contentLength > self::MAX_BYTES) {
            $this->logger->info('GitLab file: too large for triage', ['bytes' => (int) $contentLength, 'path' => $path]);

            return null;
        }

        $body   = '';
        $stream = $response->getBody();
        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(min(8192, self::MAX_BYTES + 1 - strlen($body)));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
                if (strlen($body) > self::MAX_BYTES) {
                    $this->logger->info('GitLab file: too large for triage', ['bytes' => strlen($body), 'path' => $path]);

                    return null;
                }
            }
        } catch (RuntimeException $e) {
            $this->logger->warning('GitLab file: read failed', ['error' => $e->getMessage(), 'path' => $path]);

            return null;
        }

        return $body;
    }
}
