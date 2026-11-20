<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\AutofixCaps;
use Kelpie\Service\Autofix\AutofixCapsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\AutofixCapsService
 *
 * What must hold:
 * - With nothing set the caps are the defaults (30 jobs per MR and 200 per project a day), and nothing is logged
 * - A valid override is taken, per cap
 * - A value that is not a whole number of at least 1 gives the default and a warning
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\AutofixCapsService
 */
final class AutofixCapsServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const array NAMES = ['KELPIE_AUTOFIX_ATTEMPTS_PER_THREAD', 'KELPIE_AUTOFIX_COMMITS_PER_MR', 'KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY', 'KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY'];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(AutofixCapsService::class);
    }

    /**
     * Nothing set: the defaults, quietly
     *
     * @return void
     */
    public function testDefaults(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())->method('warning');

        $caps = (new AutofixCapsService($logger))->fromEnvironment();

        $this->assertSame(30, $caps->jobsPerMr);
        $this->assertSame(200, $caps->jobsPerProject);
        $this->assertSame(AutofixCaps::DEFAULT_JOBS_PER_MR, $caps->jobsPerMr);
        $this->assertSame(AutofixCaps::DEFAULT_JOBS_PER_PROJECT, $caps->jobsPerProject);
        $this->assertSame(AutofixCaps::DEFAULT_COMMITS_PER_MR, $caps->commitsPerMr);
        $this->assertSame(AutofixCaps::DEFAULT_ATTEMPTS_PER_THREAD, $caps->attemptsPerThread);
    }

    /**
     * Each cap is overridden by its own variable
     *
     * @return void
     */
    public function testOverride(): void
    {
        putenv('KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY=60');
        putenv('KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY=500');
        putenv('KELPIE_AUTOFIX_COMMITS_PER_MR= 5 ');
        putenv('KELPIE_AUTOFIX_ATTEMPTS_PER_THREAD=4');
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())->method('warning');

        $caps = (new AutofixCapsService($logger))->fromEnvironment();

        $this->assertSame(60, $caps->jobsPerMr);
        $this->assertSame(500, $caps->jobsPerProject);
        $this->assertSame(5, $caps->commitsPerMr);
        $this->assertSame(4, $caps->attemptsPerThread);
    }

    /**
     * An invalid value falls back to the default and logs a warning
     *
     * @param string $value The raw value
     *
     * @return void
     */
    #[DataProvider('invalidValues')]
    public function testInvalidFallsBackAndWarns(string $value): void
    {
        putenv('KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY=' . $value);
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('warning')->with($this->anything(), $this->callback(static function (array $context): bool {
            return $context['name'] === 'KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY';
        }));

        $caps = (new AutofixCapsService($logger))->fromEnvironment();

        $this->assertSame(AutofixCaps::DEFAULT_JOBS_PER_PROJECT, $caps->jobsPerProject);
        $this->assertSame(AutofixCaps::DEFAULT_JOBS_PER_MR, $caps->jobsPerMr);
    }

    /**
     * An empty value counts as not set: the default, no warning
     *
     * @return void
     */
    public function testEmptyIsNotSet(): void
    {
        putenv('KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY=');
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())->method('warning');

        $this->assertSame(30, (new AutofixCapsService($logger))->fromEnvironment()->jobsPerMr);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidValues(): array
    {
        return [
            'decimal'  => ['2.5'],
            'huge'     => ['99999999999999999999'],
            'negative' => ['-5'],
            'suffix'   => ['100 a day'],
            'words'    => ['lots'],
            'zero'     => ['0'],
        ];
    }

    /**
     * Start with no cap variable set
     *
     * @return void
     */
    protected function setUp(): void
    {
        foreach (self::NAMES as $name) {
            putenv($name);
        }
    }

    /**
     * Leave no cap variable set
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (self::NAMES as $name) {
            putenv($name);
        }
    }
}
