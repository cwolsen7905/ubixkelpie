<?php

declare(strict_types=1);

namespace Kelpie\Tests\Controller\Gitlab;

use Kelpie\Controller\Gitlab\GitlabWebhookController;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Controller\Gitlab\GitlabWebhookController
 *
 * @coversDefaultClass \Kelpie\Controller\Gitlab\GitlabWebhookController
 */
final class GitlabWebhookControllerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(GitlabWebhookController::class);
    }
}
