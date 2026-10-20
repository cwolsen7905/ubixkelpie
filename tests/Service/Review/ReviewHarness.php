<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\GitLab\Review\ReviewGitlab;
use Kelpie\OpenRouter\Review\ReviewChatClient;
use Kelpie\Prompt\Review\ReviewPrompt;
use Kelpie\Service\Review\FindingMemoryService;
use Kelpie\Service\Review\RepoGitService;
use Kelpie\Service\Review\RepoToolsService;
use Kelpie\Service\Review\ReviewAgentService;
use Kelpie\Service\Review\ReviewParserService;
use Kelpie\Service\Review\ReviewService;
use Kelpie\Service\Review\ReviewUsageService;
use Kelpie\Service\Review\SkipPolicyService;
use Kelpie\Service\Review\StandardsService;
use Kelpie\Service\Review\SummaryRenderService;
use Kelpie\Service\Review\ThreadPostService;
use Kelpie\Tests\GitLab\Review\FakeReviewGitlab;
use Kelpie\Tests\OpenRouter\Review\ScriptedModelHttp;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;

/**
 * The review service wired over a fake GitLab, a scripted model API and a fake git
 */
final class ReviewHarness
{
    private FakeReviewGitlab $gitlab;

    private ScriptedModelHttp $model;

    private FakeGitRunner $git;

    private ReviewService $service;

    private FindingMemoryService $memory;

    private ThreadPostService $poster;

    private ReviewGitlab $reviewGitlab;

    private ReviewAgentService $modelService;

    /**
     * Constructor
     *
     * @param Logger $logger A logger stub
     */
    public function __construct(
        Logger $logger,
    ) {
        $json    = new JsonService($logger);
        $factory = new Psr17Factory();

        $this->gitlab       = new FakeReviewGitlab();
        $this->model        = new ScriptedModelHttp();
        $this->git          = new FakeGitRunner();
        $this->reviewGitlab = new ReviewGitlab($logger, $this->gitlab, $factory, $factory, $json);
        $repoGit            = new RepoGitService($logger, $this->git);
        $this->memory       = new FindingMemoryService($logger);
        $render             = new SummaryRenderService($logger);
        $this->poster       = new ThreadPostService($logger, $this->reviewGitlab, $this->memory, $render, $repoGit);
        $this->modelService = new ReviewAgentService($logger, $json, new ReviewChatClient($logger, $this->model, $factory, $factory, $json), new RepoToolsService($logger), new ReviewUsageService($logger));

        $this->service = new ReviewService(
            $logger,
            $json,
            $this->reviewGitlab,
            $repoGit,
            new SkipPolicyService($logger),
            new StandardsService($logger, $repoGit),
            $this->memory,
            new ReviewPrompt($logger),
            $this->modelService,
            new ReviewParserService($logger, $json),
            $this->poster,
            $render,
            new ReviewUsageService($logger),
        );
    }

    /**
     * @return FakeReviewGitlab
     */
    public function gitlab(): FakeReviewGitlab
    {
        return $this->gitlab;
    }

    /**
     * @return ScriptedModelHttp
     */
    public function model(): ScriptedModelHttp
    {
        return $this->model;
    }

    /**
     * @return FakeGitRunner
     */
    public function git(): FakeGitRunner
    {
        return $this->git;
    }

    /**
     * @return ReviewService
     */
    public function service(): ReviewService
    {
        return $this->service;
    }

    /**
     * @return FindingMemoryService
     */
    public function memory(): FindingMemoryService
    {
        return $this->memory;
    }

    /**
     * @return ThreadPostService
     */
    public function poster(): ThreadPostService
    {
        return $this->poster;
    }

    /**
     * @return ReviewGitlab
     */
    public function reviewGitlab(): ReviewGitlab
    {
        return $this->reviewGitlab;
    }

    /**
     * @return ReviewAgentService
     */
    public function modelService(): ReviewAgentService
    {
        return $this->modelService;
    }
}
