<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\Service\Autofix\DiffLineService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\DiffLineService
 *
 * What must hold:
 * - A removed line counts at its old line number, an added line at the old line it was put before
 * - Lines within the radius of a change are near; unchanged context is not a change
 * - A line far from every hunk, or text that is not a diff, is not near
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\DiffLineService
 */
final class DiffLineServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(DiffLineService::class);
    }

    /**
     * A removed line and the lines around it are near; unchanged context far from a change is not
     *
     * @return void
     */
    public function testARemovedLineIsNear(): void
    {
        $diff = "@@ -10,7 +10,6 @@\n a\n b\n c\n-gone\n d\n e\n f\n";

        $this->assertTrue($this->service()->touches($diff, 13));
        $this->assertTrue($this->service()->touches($diff, 10));
        $this->assertFalse($this->service()->touches($diff, 9));
        $this->assertFalse($this->service()->touches($diff, 17));
        $this->assertTrue($this->service()->touches($diff, 17, 4));
    }

    /**
     * An added line sits before the old line that follows it, and the old numbering carries on past it
     *
     * @return void
     */
    public function testAnAddedLineIsNearTheOldLineAfterIt(): void
    {
        $diff = "@@ -1,3 +1,4 @@\n a\n+new\n b\n c\n@@ -50,2 +51,3 @@\n x\n y\n+z\n";

        $this->assertTrue($this->service()->touches($diff, 2));
        $this->assertTrue($this->service()->touches($diff, 5));
        $this->assertFalse($this->service()->touches($diff, 6));
        $this->assertTrue($this->service()->touches($diff, 52));
        $this->assertFalse($this->service()->touches($diff, 20));
    }

    /**
     * Text that is not a diff, an empty diff and a no-newline marker change nothing
     *
     * @return void
     */
    public function testNothingToReadIsNotNear(): void
    {
        $this->assertFalse($this->service()->touches('', 1));
        $this->assertFalse($this->service()->touches("+not a diff\n-at all", 1));
        $this->assertFalse($this->service()->touches("@@ -1,1 +1,1 @@\n a\n\\ No newline at end of file\n", 1));
    }

    /**
     * The service under test
     *
     * @return DiffLineService
     */
    private function service(): DiffLineService
    {
        return new DiffLineService($this->createStub(Logger::class));
    }
}
