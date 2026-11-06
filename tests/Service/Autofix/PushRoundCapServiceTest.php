<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\Service\Autofix\PushRoundCapService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\PushRoundCapService
 *
 * What must hold:
 * - By default a round may fix 5 threads and change 80 lines; one more of either is over
 * - The figures can be set, and the notice names the one that was passed
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\PushRoundCapService
 */
final class PushRoundCapServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(PushRoundCapService::class);
    }

    /**
     * The defaults are the safety policy's figures
     *
     * @return void
     */
    public function testDefaults(): void
    {
        $cap = new PushRoundCapService($this->createStub(Logger::class));

        $this->assertFalse($cap->threadsOver(5));
        $this->assertTrue($cap->threadsOver(6));
        $this->assertFalse($cap->linesOver(80));
        $this->assertTrue($cap->linesOver(81));
    }

    /**
     * Set figures, and a notice that names what was passed
     *
     * @return void
     */
    public function testCustomFiguresAndNotice(): void
    {
        $cap = new PushRoundCapService($this->createStub(Logger::class), 2, 10);

        $this->assertTrue($cap->threadsOver(3));
        $this->assertTrue($cap->linesOver(11));
        $this->assertStringContainsString('3 fixes and I push at most 2', $cap->notice(3));
        $this->assertStringContainsString('changes 11 lines and I push at most 10', $cap->notice(2, 11));
        $this->assertStringContainsString('👍', $cap->notice(3));
    }
}
