<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use Kelpie\DataTransferObject\Project;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Service\Triage\TriageLlmService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Triage\TriageLlmService
 *
 * What must hold:
 * - The model is the project's override, else KELPIE_TRIAGE_MODEL, else the caller's default
 * - A model ending in -contributor is allowed only for a project GitLab reports as public
 * - Private, internal, unreadable or unknown visibility refuses it (fails closed)
 *
 * @coversDefaultClass \Kelpie\Service\Triage\TriageLlmService
 */
final class TriageLlmServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string MUSE = 'meta/muse-spark-1.3-contributor';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(TriageLlmService::class);
    }

    /**
     * Without an override or env, the caller's default is used
     *
     * @return void
     */
    public function testDefaultModel(): void
    {
        putenv('KELPIE_TRIAGE_MODEL');
        $choice = TriageLlms::make($this->logger())->choose('g/p', 'google/gemini-3.8-flash');

        $this->assertSame('google/gemini-3.8-flash', $choice->model);
        $this->assertSame('', $choice->refusal);
    }

    /**
     * KELPIE_TRIAGE_MODEL replaces the default
     *
     * @return void
     */
    public function testEnvModel(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=openai/gpt-5.5-mini');
        $choice = TriageLlms::make($this->logger())->choose('g/p', 'google/gemini-3.8-flash');
        putenv('KELPIE_TRIAGE_MODEL');

        $this->assertSame('openai/gpt-5.5-mini', $choice->model);
    }

    /**
     * The project's triage_model wins over the env, looked up by the path GitLab reports for an ID
     *
     * @return void
     */
    public function testProjectOverrideWins(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=google/gemini-3.8-flash');
        $projects = $this->createStub(ProjectReader::class);
        $projects->method('getByPath')->willReturnCallback(static function (string $path): ?Project {
            return $path === 'ubixsys/replikate' ? new Project($path, triageModel: self::MUSE) : null;
        });

        $choice = TriageLlms::make($this->logger(), 'public', $projects, 'ubixsys/replikate')->choose('7', 'x/y');
        putenv('KELPIE_TRIAGE_MODEL');

        $this->assertSame(self::MUSE, $choice->model);
        $this->assertSame('', $choice->refusal);
    }

    /**
     * A training-tier model is refused unless the project is public
     *
     * @return void
     */
    public function testTrainingTierNeedsPublicProject(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=' . self::MUSE);
        foreach ([['public', true], ['private', false], ['internal', false], [null, false]] as [$visibility, $allowed]) {
            $choice = TriageLlms::make($this->logger(), $visibility)->choose('g/p', 'x/y');
            $this->assertSame($allowed, $choice->refusal === '', 'visibility ' . ($visibility ?? 'unreadable'));
        }

        $this->assertNotSame('', TriageLlms::make($this->logger())->choose(null, 'x/y')->refusal);
        putenv('KELPIE_TRIAGE_MODEL');
    }

    /**
     * The shared guard: only a training tier is checked, and only a public project lets it through
     *
     * @return void
     */
    public function testRefusalFor(): void
    {
        $this->assertSame('', TriageLlms::make($this->logger(), 'private')->refusalFor('openai/gpt-5.5', 'g/p'));
        $this->assertSame('', TriageLlms::make($this->logger(), 'public')->refusalFor(self::MUSE, 'g/p'));
        $this->assertStringContainsString('not known to be public', TriageLlms::make($this->logger(), 'private')->refusalFor(self::MUSE, 'g/p'));
        $this->assertStringContainsString('not known to be public', TriageLlms::make($this->logger(), 'public')->refusalFor(self::MUSE, null));
    }

    /**
     * Only a -contributor suffix counts as a training tier
     *
     * @return void
     */
    public function testTrainsOnPrompts(): void
    {
        $models = TriageLlms::make($this->logger());

        $this->assertTrue($models->trainsOnPrompts(self::MUSE));
        $this->assertTrue($models->trainsOnPrompts(' Meta/Muse-Spark-1.3-CONTRIBUTOR '));
        $this->assertFalse($models->trainsOnPrompts('google/gemini-3.8-flash'));
        $this->assertFalse($models->trainsOnPrompts('acme/contributor-model'));
    }

    /**
     * @return Logger
     */
    private function logger(): Logger
    {
        return $this->createStub(Logger::class);
    }
}
