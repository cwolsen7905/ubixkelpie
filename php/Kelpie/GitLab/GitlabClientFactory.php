<?php

declare(strict_types=1);

namespace Kelpie\GitLab;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\HttpClient\BotHttpClient;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;

/**
 * Builds the GitLab readers and the writer on the kelpie-bot client
 *
 * The bot's token and the API URL come from the settings, so the commands' services ask for a reader
 * or a writer instead of wiring a client by hand. Every call builds a new object; none holds state.
 *
 * @see \Kelpie\Tests\GitLab\GitlabClientFactoryTest PHPUnit test case
 */
final class GitlabClientFactory
{
    /**
     * Constructor
     *
     * @param Logger          $logger         Logger
     * @param HttpClient      $httpClient     The HTTP client
     * @param RequestFactory  $requestFactory The PSR-17 request factory
     * @param StreamFactory   $streamFactory  The PSR-17 stream factory
     * @param JsonService     $jsonService    The JSON service
     * @param AutofixSettings $settings       The settings
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly HttpClient $httpClient,
        private readonly RequestFactory $requestFactory,
        private readonly StreamFactory $streamFactory,
        private readonly JsonService $jsonService,
        private readonly AutofixSettings $settings,
    ) {
    }

    /**
     * The bot's HTTP client
     *
     * @return BotHttpClient
     */
    public function client(): BotHttpClient
    {
        return new BotHttpClient($this->logger, $this->httpClient, $this->settings->botToken);
    }

    /**
     * The discussion writer
     *
     * @return DiscussionWriter
     */
    public function writer(): DiscussionWriter
    {
        return new DiscussionWriter($this->logger, $this->client(), $this->requestFactory, $this->streamFactory, $this->settings->apiUrl, $this->jsonService);
    }

    /**
     * The discussion reader
     *
     * @return DiscussionReader
     */
    public function reader(): DiscussionReader
    {
        return new DiscussionReader($this->logger, $this->client(), $this->requestFactory, $this->settings->apiUrl, $this->jsonService);
    }

    /**
     * The merge request lister
     *
     * @return MergeRequestLister
     */
    public function lister(): MergeRequestLister
    {
        return new MergeRequestLister($this->logger, $this->client(), $this->requestFactory, $this->settings->apiUrl, $this->jsonService);
    }

    /**
     * The job log reader
     *
     * @return JobLogReader
     */
    public function jobLogReader(): JobLogReader
    {
        return new JobLogReader($this->logger, $this->client(), $this->requestFactory, $this->settings->apiUrl, $this->jsonService);
    }
}
