<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\Enum\ResponseOutcome;
use Kelpie\Service\Autofix\ClaimService;
use Kelpie\Tests\GitLab\FakeGitlab;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\ClaimService
 *
 * What must hold:
 * - A free thread is claimed with the marker note
 * - A fresh claim from anyone blocks; an expired or released one does not
 * - When two workers race, the oldest claim wins and the loser releases its own
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\ClaimService
 */
final class ClaimServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * The fake GitLab
     */
    private FakeGitlab $gitlab;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ClaimService::class);
    }

    /**
     * A free thread is claimed, and the claim note carries the marker
     *
     * @return void
     */
    public function testFreeThreadIsClaimed(): void
    {
        $this->gitlab->note('d1', 'Please rename.');

        $id = $this->service()->claim('1', 3, 'd1', 'abc');

        $this->assertIsInt($id);
        $this->assertStringContainsString('<!-- kelpie:claim thread=d1 sha=abc -->', $this->gitlab->notes()['d1'][1]['body']);
    }

    /**
     * A fresh claim blocks, whoever wrote it, and nothing is posted
     *
     * @return void
     */
    public function testFreshClaimBlocks(): void
    {
        $this->gitlab->note('d1', "x\n<!-- kelpie:claim thread=d1 sha=abc -->", '-5 minutes', null, 'someone-else');

        $this->assertSame(ResponseOutcome::ClaimedByOther, $this->service()->claim('1', 3, 'd1', 'abc'));
        $this->assertCount(1, $this->gitlab->notes()['d1']);
    }

    /**
     * An expired claim and a released claim do not block
     *
     * @return void
     */
    public function testExpiredAndReleasedClaimsDoNotBlock(): void
    {
        $this->gitlab->note('d1', '<!-- kelpie:claim thread=d1 sha=abc -->', '-2 hours');
        $this->gitlab->note('d1', '<!-- kelpie:release thread=d1 sha=abc -->', '-1 minutes');

        $this->assertIsInt($this->service()->claim('1', 3, 'd1', 'abc'));
    }

    /**
     * The loser of a race releases its claim and backs off
     *
     * @return void
     */
    public function testRaceLoserBacksOff(): void
    {
        $this->gitlab->note('d1', 'Please rename.');
        $this->gitlab->onPost(function (string $discussionId): void {
            $this->gitlab->note($discussionId, '<!-- kelpie:claim thread=d1 sha=abc -->', 'now', 50, 'worker-b');
        });

        $this->assertSame(ResponseOutcome::ClaimedByOther, $this->service()->claim('1', 3, 'd1', 'abc'));
        $this->assertStringContainsString('kelpie:release', $this->gitlab->notes()['d1'][1]['body']);
        $this->assertStringNotContainsString('kelpie:claim', $this->gitlab->notes()['d1'][1]['body']);
    }

    /**
     * An unreadable thread is a failure, not a claim
     *
     * @return void
     */
    public function testUnreadableThreadFails(): void
    {
        $this->assertSame(ResponseOutcome::Failed, $this->service()->claim('1', 3, 'missing', 'abc'));
    }

    /**
     * Start every test with an empty GitLab
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->gitlab = new FakeGitlab();
    }

    /**
     * @return ClaimService
     */
    private function service(): ClaimService
    {
        $logger = $this->createStub(Logger::class);

        return new ClaimService($logger, $this->gitlab->writer($logger));
    }
}
