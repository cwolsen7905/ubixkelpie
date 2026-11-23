<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Settings;

use Kelpie\DataTransferObject\Project;
use Kelpie\Enum\ProjectMode;
use Kelpie\Service\Settings\ProjectSettingsService;
use Kelpie\Settings\Exception\ProjectNotFoundException;
use Kelpie\Settings\ProjectSettingsRepresentation;
use Kelpie\Settings\ProjectSettingsValidator;
use Kelpie\Settings\Exception\SettingsValidationException;
use Psr\Log\NullLogger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Settings\ProjectSettingsService
 *
 * @coversDefaultClass \Kelpie\Service\Settings\ProjectSettingsService
 */
final class ProjectSettingsServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectSettingsService::class);
    }

    /**
     * Test that the list holds every project, off ones included, in path order
     *
     * @return void
     */
    public function testListIncludesOffProjects(): void
    {
        $service = $this->service(new InMemoryProjectSettingsStore(new Project('b/b', ProjectMode::Off), new Project('a/a', ProjectMode::Push)));

        $this->assertSame(['a/a', 'b/b'], array_column($service->list(), 'path'));
    }

    /**
     * Test reading one project, and that a path with no row is not an empty off project
     *
     * @return void
     */
    public function testGetAndNotFound(): void
    {
        $service = $this->service(new InMemoryProjectSettingsStore(new Project('a/a', ProjectMode::Propose)));

        $this->assertSame('propose', $service->get('a/a')['mode']);
        $this->expectException(ProjectNotFoundException::class);
        $service->get('x/y');
    }

    /**
     * Test that an update writes and returns the new state, and a partial patch leaves the rest alone
     *
     * @return void
     */
    public function testUpdateAppliesAndReturnsNewState(): void
    {
        $store   = new InMemoryProjectSettingsStore(new Project('a/a', ProjectMode::Propose, maxCostUsdDayHard: '5.00'));
        $service = $this->service($store);

        $result = $service->update('a/a', ['mode' => 'push', 'settings' => ['max_cost_usd_day' => ['hard' => null], 'max_issues_per_day' => ['soft' => 12]]]);

        $this->assertSame('push', $result['mode']);
        $this->assertSame(['soft' => '2.00', 'hard' => null], $result['settings']['max_cost_usd_day']);
        $this->assertSame(['soft' => 12, 'hard' => null], $result['settings']['max_issues_per_day']);
        $this->assertSame(['soft' => 10, 'hard' => null], $result['settings']['max_issues_per_author_day']);
        $this->assertSame(1, $store->writeCount());
    }

    /**
     * Test that a patch that changes nothing writes nothing
     *
     * @return void
     */
    public function testNoOpWritesNothing(): void
    {
        $store = new InMemoryProjectSettingsStore(new Project('a/a', ProjectMode::Propose));

        $this->service($store)->update('a/a', ['mode' => 'propose']);

        $this->assertSame(0, $store->writeCount());
    }

    /**
     * Test that a rejected patch changes nothing, even its valid part
     *
     * @return void
     */
    public function testRejectedPatchWritesNothing(): void
    {
        $store = new InMemoryProjectSettingsStore(new Project('a/a', ProjectMode::Propose));

        try {
            $this->service($store)->update('a/a', ['mode' => 'push', 'settings' => ['max_issues_per_day' => ['soft' => 0]]]);
            $this->fail('Expected a validation error');
        } catch (SettingsValidationException $e) {
            $this->assertSame(0, $store->writeCount());
            $this->assertSame(ProjectMode::Propose, $store->getByPath('a/a')?->mode);
        }
    }

    /**
     * Test that updating a project with no row is a not-found, not a create
     *
     * @return void
     */
    public function testUpdateUnknownProject(): void
    {
        $store = new InMemoryProjectSettingsStore();

        try {
            $this->service($store)->update('x/y', ['mode' => 'push']);
            $this->fail('Expected not found');
        } catch (ProjectNotFoundException $e) {
            $this->assertSame(0, $store->writeCount());
        }
    }

    /**
     * @param InMemoryProjectSettingsStore $store The table
     *
     * @return ProjectSettingsService
     */
    private function service(InMemoryProjectSettingsStore $store): ProjectSettingsService
    {
        return new ProjectSettingsService(new NullLogger(), $store, $store, new ProjectSettingsValidator(new NullLogger()), new ProjectSettingsRepresentation(new NullLogger()));
    }
}
