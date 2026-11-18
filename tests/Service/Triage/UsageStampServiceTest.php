<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use Kelpie\DataTransferObject\Completion;
use Kelpie\Service\Triage\UsageStampService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Triage\UsageStampService
 *
 * What must hold:
 * - The footer names model, effort, tokens and cost, in the shape of the review's stamp
 * - With no cost reported the tokens show and no cost is made up
 * - With no model call the text is left as it was
 *
 * @coversDefaultClass \Kelpie\Service\Triage\UsageStampService
 */
final class UsageStampServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(UsageStampService::class);
    }

    /**
     * A costed call shows everything
     *
     * @return void
     */
    public function testLineWithCost(): void
    {
        $line = $this->service()->line(new Completion('x', 'a/b', 12300, 999, 0.0123456));

        $this->assertSame('_Model `a/b`, effort default, 12.3k in / 999 out, $0.0123 (OpenRouter)._', $line);
    }

    /**
     * A call with no reported cost shows tokens only, a reported zero is still a cost
     *
     * @return void
     */
    public function testLineWithoutCostOmitsIt(): void
    {
        $this->assertSame('_Model `a/b`, effort default, 0 in / 0 out._', $this->service()->line(new Completion('x', 'a/b')));
        $this->assertStringContainsString('$0.0000 (OpenRouter)', $this->service()->line(new Completion('x', 'a/b', 1, 1, 0.0)));
    }

    /**
     * A backtick in the model id cannot break the footer
     *
     * @return void
     */
    public function testModelIdIsKeptInsideItsBackticks(): void
    {
        $this->assertStringContainsString('`ab`', $this->service()->line(new Completion('x', 'a`b')));
    }

    /**
     * Append adds the footer after a blank line, or nothing without a call
     *
     * @return void
     */
    public function testAppend(): void
    {
        $this->assertSame('hi', $this->service()->append('hi', null));
        $this->assertStringStartsWith("hi\n\n_Model `m`", $this->service()->append('hi', new Completion('x', 'm')));
    }

    /**
     * @return UsageStampService
     */
    private function service(): UsageStampService
    {
        return new UsageStampService($this->createStub(Logger::class));
    }
}
