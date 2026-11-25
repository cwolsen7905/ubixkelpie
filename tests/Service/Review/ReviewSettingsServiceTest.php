<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewSettings;
use Kelpie\Service\Review\ReviewSettingsService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\ReviewSettingsService
 *
 * What must hold:
 * - An empty environment gives the shell review's defaults
 * - The AI_REVIEW_* and CI merge request variables map to the settings; a shadow run has its own summary marker
 * - The `openrouter/` prefix of a model id is dropped for the API and kept for the stamp
 * - A mode other than advisory or blocking is invalid
 * - An open-source license (GitLab's key) swaps in AI_REVIEW_MODEL_PUBLIC; anything else keeps the regular model
 *
 * @coversDefaultClass \Kelpie\Service\Review\ReviewSettingsService
 */
final class ReviewSettingsServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewSettingsService::class);
    }

    /**
     * The defaults
     *
     * @return void
     */
    public function testDefaults(): void
    {
        $settings = $this->service()->fromEnvironment([], repo: '/r');

        $this->assertFalse($settings->blocking);
        $this->assertFalse($settings->shadow);
        $this->assertSame('openrouter/anthropic/claude-haiku-5.5', $settings->model);
        $this->assertSame('anthropic/claude-haiku-5.5', $settings->apiModel);
        $this->assertSame([3000, 153600, 3], [$settings->maxLines, $settings->maxBytes, $settings->maxRounds]);
        $this->assertSame('review:extra', $settings->extraLabel);
        $this->assertSame('HEAD', $settings->headSha);
        $this->assertSame('/r', $settings->repo);
        $this->assertSame('<!-- ai-review:summary', $settings->summaryMarker);
    }

    /**
     * The variables map across
     *
     * @return void
     */
    public function testEnvironmentMapsToSettings(): void
    {
        $settings = $this->service()->fromEnvironment([
            'AI_REVIEW_EFFORT'               => 'high',
            'AI_REVIEW_GITLAB_TOKEN'         => 'tok',
            'AI_REVIEW_MAX_LINES'            => '10',
            'AI_REVIEW_MODE'                 => 'blocking',
            'AI_REVIEW_MODEL'                => 'openrouter/vendor/m',
            'AI_REVIEW_SHADOW'               => 'true',
            'CI_API_V4_URL'                  => 'https://gl.test/api/v4/',
            'CI_COMMIT_SHA'                  => 'abc',
            'CI_MERGE_REQUEST_DIFF_BASE_SHA' => 'base',
            'CI_MERGE_REQUEST_IID'           => '7',
            'CI_MERGE_REQUEST_LABELS'        => 'a, review:extra',
            'CI_MERGE_REQUEST_TITLE'         => 'T',
            'CI_PROJECT_DIR'                 => '/builds/x',
            'CI_PROJECT_ID'                  => '42',
        ]);

        $this->assertTrue($settings->blocking);
        $this->assertTrue($settings->shadow);
        $this->assertSame('<!-- kelpie-review:shadow', $settings->summaryMarker);
        $this->assertSame(['openrouter/vendor/m', 'vendor/m', 'high'], [$settings->model, $settings->apiModel, $settings->effort]);
        $this->assertSame([10, 'https://gl.test/api/v4', 'tok', '42', 7, 'base', 'abc'], [$settings->maxLines, $settings->apiUrl, $settings->token, $settings->project, $settings->mrIid, $settings->baseSha, $settings->headSha]);
        $this->assertSame(['a', 'review:extra'], $settings->labels);
        $this->assertSame('/builds/x', $settings->repo);
    }

    /**
     * A number that is not positive falls back to its default
     *
     * @return void
     */
    public function testBadNumbersFallBack(): void
    {
        $settings = $this->service()->fromEnvironment(['AI_REVIEW_MAX_ROUNDS' => 'x', 'AI_REVIEW_MAX_BYTES' => '-5']);

        $this->assertSame([3, 153600], [$settings->maxRounds, $settings->maxBytes]);
    }

    /**
     * Only advisory and blocking are modes
     *
     * @return void
     */
    public function testModeValidity(): void
    {
        $service = $this->service();

        $this->assertTrue($service->modeIsValid([]));
        $this->assertTrue($service->modeIsValid(['AI_REVIEW_MODE' => 'blocking']));
        $this->assertFalse($service->modeIsValid(['AI_REVIEW_MODE' => 'strict']));
    }

    /**
     * An open-source license swaps in AI_REVIEW_MODEL_PUBLIC; no license, an unread one or no public model keeps the regular model
     *
     * @return void
     */
    public function testOpenSourceLicenseGetsThePublicModel(): void
    {
        $service  = $this->service();
        $env      = ['AI_REVIEW_MODEL_PUBLIC' => 'openrouter/meta/muse-spark-1.3-contributor', 'CI_MERGE_REQUEST_IID' => '7'];
        $settings = $service->fromEnvironment($env, repo: '/r');

        $public = $service->forLicense($settings, $env, 'BSD-3-Clause');
        $this->assertSame(['openrouter/meta/muse-spark-1.3-contributor', 'meta/muse-spark-1.3-contributor'], [$public->model, $public->apiModel]);
        $this->assertSame([7, '/r'], [$public->mrIid, $public->repo]);
        $this->assertSame(ReviewSettings::DEFAULT_MODEL, $public->fallbackModel, 'the regular model stays behind the public one');
        $this->assertSame('', $settings->fallbackModel, 'none by default');

        $this->assertSame(ReviewSettings::DEFAULT_MODEL, $service->forLicense($settings, $env, '')->model, 'no license');
        $this->assertSame(ReviewSettings::DEFAULT_MODEL, $service->forLicense($settings, $env, null)->model, 'license not read');
        $this->assertSame(ReviewSettings::DEFAULT_MODEL, $service->forLicense($settings, $env, 'proprietary')->model, 'not open source');
        $this->assertSame(ReviewSettings::DEFAULT_MODEL, $service->forLicense($settings, ['CI_MERGE_REQUEST_IID' => '7'], 'mit')->model, 'no public model set');
    }

    /**
     * @return ReviewSettingsService
     */
    private function service(): ReviewSettingsService
    {
        return new ReviewSettingsService($this->createStub(Logger::class));
    }
}
