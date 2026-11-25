<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use Kelpie\DataTransferObject\Review\ReviewSettings;
use Kelpie\Service\Triage\LlmFamilyService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Triage\LlmFamilyService
 *
 * @coversDefaultClass \Kelpie\Service\Triage\LlmFamilyService
 */
final class LlmFamilyServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(LlmFamilyService::class);
    }

    /**
     * The family is the vendor part of the id, after an openrouter/ routing prefix, in lower case
     *
     * @return void
     */
    public function testFamilyIsTheVendorPart(): void
    {
        $service = new LlmFamilyService($this->createStub(Logger::class));

        $this->assertSame('anthropic', $service->family('anthropic/claude-haiku-5.5'));
        $this->assertSame('anthropic', $service->family('openrouter/Anthropic/claude-haiku-5.5'));
        $this->assertSame('google', $service->family(' google/gemini-3.8-flash '));
    }

    /**
     * An id with no vendor part cannot be placed
     *
     * @return void
     */
    public function testFamilyOfABareIdIsUnknown(): void
    {
        $this->assertSame('', (new LlmFamilyService($this->createStub(Logger::class)))->family('claude-haiku-5.5'));
    }

    /**
     * The review model is AI_REVIEW_MODEL, else the review default
     *
     * @return void
     */
    public function testReviewModelFollowsTheEnvironment(): void
    {
        putenv('AI_REVIEW_MODEL=openai/gpt-5.5');
        $service = new LlmFamilyService($this->createStub(Logger::class));
        $this->assertSame(['openai/gpt-5.5'], $service->reviewModels());
        $this->assertSame('openai', $service->reviewFamily());

        putenv('AI_REVIEW_MODEL');
        $this->assertSame('anthropic', $service->reviewFamily());
    }

    /**
     * For an open-source project either model may have written the review (the public one, or the regular one it
     * falls back to), so both families are refused; a closed-source project only refuses the regular family
     *
     * @return void
     */
    public function testOpenSourceRefusesBothReviewFamilies(): void
    {
        putenv('AI_REVIEW_MODEL');
        putenv('AI_REVIEW_MODEL_PUBLIC=openrouter/meta/muse-spark-1.3-contributor');
        $service = new LlmFamilyService($this->createStub(Logger::class));

        $this->assertSame(['openrouter/meta/muse-spark-1.3-contributor', ReviewSettings::DEFAULT_MODEL], $service->reviewModels(true));
        $this->assertSame('meta+anthropic', $service->reviewFamily(true));
        $this->assertSame('anthropic', $service->reviewFamily(), 'not open source: the regular model');
        $this->assertTrue($service->sameAsReview('meta/muse-spark-1.3-contributor', true));
        $this->assertTrue($service->sameAsReview('anthropic/claude-haiku-5.5', true), 'the fallback may have written it');
        $this->assertFalse($service->sameAsReview('upstage/solar-pro-4-contributor', true));
        $this->assertFalse($service->sameAsReview('meta/muse-spark-1.3-contributor', false));

        putenv('AI_REVIEW_MODEL_PUBLIC');
        $this->assertSame('anthropic', $service->reviewFamily(true), 'no public model: the regular one');
    }

    /**
     * A project whose license could not be read fails closed: both families are refused while a public model is set
     *
     * @return void
     */
    public function testUnreadLicenseFailsClosed(): void
    {
        putenv('AI_REVIEW_MODEL');
        putenv('AI_REVIEW_MODEL_PUBLIC=openrouter/meta/muse-spark-1.3-contributor');
        $service = new LlmFamilyService($this->createStub(Logger::class));

        try {
            $this->assertTrue($service->sameAsReview('meta/llama-5', null));
            $this->assertTrue($service->sameAsReview('anthropic/claude-sonnet-5.5', null));
            $this->assertFalse($service->sameAsReview('deepseek/deepseek-v4.1-flash', null));
        } finally {
            putenv('AI_REVIEW_MODEL_PUBLIC');
        }
    }

    /**
     * Same family, or any family that is unknown, is refused; a different family is not
     *
     * @return void
     */
    public function testSameAsReview(): void
    {
        putenv('AI_REVIEW_MODEL');
        $service = new LlmFamilyService($this->createStub(Logger::class));

        $this->assertTrue($service->sameAsReview('anthropic/claude-sonnet-5.5'));
        $this->assertTrue($service->sameAsReview('openrouter/anthropic/claude-haiku-5.5'));
        $this->assertTrue($service->sameAsReview('mystery'));
        $this->assertFalse($service->sameAsReview('google/gemini-3.8-flash'));

        putenv('AI_REVIEW_MODEL=mystery');
        $this->assertTrue($service->sameAsReview('google/gemini-3.8-flash'));
        putenv('AI_REVIEW_MODEL');
    }
}
