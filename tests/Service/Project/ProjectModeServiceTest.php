<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Project;

use Kelpie\DataTransferObject\Project;
use Kelpie\Enum\ProjectMode;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Service\Project\ProjectModeService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Project\ProjectModeService
 *
 * What must hold:
 * - A project with no row is off
 * - A project's mode is its row's mode, and an action is allowed when the mode reaches the one it needs
 * - The enrolled paths are those the reader returns, in its order
 *
 * @coversDefaultClass \Kelpie\Service\Project\ProjectModeService
 */
final class ProjectModeServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectModeService::class);
    }

    /**
     * No row is off
     *
     * @return void
     */
    public function testUnknownProjectIsOff(): void
    {
        $reader = $this->createStub(ProjectReader::class);
        $reader->method('getByPath')->willReturn(null);
        $service = new ProjectModeService($this->createStub(Logger::class), $reader);

        $this->assertSame(ProjectMode::Off, $service->modeOf('g/none'));
        $this->assertFalse($service->allows('g/none', ProjectMode::Propose));
    }

    /**
     * The row's mode decides
     *
     * @return void
     */
    public function testModeDecides(): void
    {
        $reader = $this->createStub(ProjectReader::class);
        $reader->method('getByPath')->willReturn(new Project('g/p', ProjectMode::Propose));
        $service = new ProjectModeService($this->createStub(Logger::class), $reader);

        $this->assertSame(ProjectMode::Propose, $service->modeOf('g/p'));
        $this->assertTrue($service->allows('g/p', ProjectMode::Propose));
        $this->assertFalse($service->allows('g/p', ProjectMode::Push));
    }

    /**
     * The enrolled paths
     *
     * @return void
     */
    public function testEnrolledPaths(): void
    {
        $reader = $this->createStub(ProjectReader::class);
        $reader->method('getEnrolled')->willReturn([new Project('g/a', ProjectMode::Push), new Project('g/b', ProjectMode::Propose)]);

        $this->assertSame(['g/a', 'g/b'], (new ProjectModeService($this->createStub(Logger::class), $reader))->enrolledPaths());
    }
}
