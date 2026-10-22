<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

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
        $this->assertSame('google', $service->family(' google/gemini-3-flash '));
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
        $this->assertSame('openai/gpt-5.5', $service->reviewModel());
        $this->assertSame('openai', $service->reviewFamily());

        putenv('AI_REVIEW_MODEL');
        $this->assertSame('anthropic', $service->reviewFamily());
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
        $this->assertFalse($service->sameAsReview('google/gemini-3-flash'));

        putenv('AI_REVIEW_MODEL=mystery');
        $this->assertTrue($service->sameAsReview('google/gemini-3-flash'));
        putenv('AI_REVIEW_MODEL');
    }
}
