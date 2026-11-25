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
 * - An open-source project (an OSI license detected) gets KELPIE_TRIAGE_MODEL_PUBLIC, default Muse Spark
 * - A model ending in -contributor is allowed only for an open-source project
 * - No license, another license, or an unreadable project refuses it (fails closed)
 *
 * @coversDefaultClass \Kelpie\Service\Triage\TriageLlmService
 */
final class TriageLlmServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const string PUBLIC_MODEL = 'upstage/solar-pro-4-contributor';

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
            return $path === 'ubixsys/replikate' ? new Project($path, triageModel: self::PUBLIC_MODEL) : null;
        });

        $choice = TriageLlms::make($this->logger(), 'private', $projects, 'ubixsys/replikate', 'bsd-3-clause')->choose('7', 'x/y');
        putenv('KELPIE_TRIAGE_MODEL');

        $this->assertSame(self::PUBLIC_MODEL, $choice->model);
        $this->assertSame('', $choice->refusal);
        $this->assertSame('', $choice->fallback, 'an override is what the project asked for: no fallback');
    }

    /**
     * The open-source model falls back to the regular one, which must not be a training tier itself
     *
     * @return void
     */
    public function testOpenSourceChoiceFallsBackToTheRegularModel(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=google/gemini-3.8-flash');
        $choice = TriageLlms::make($this->logger(), 'private', null, 'g/p', 'bsd-3-clause')->choose('g/p', 'x/y');
        $this->assertSame(self::PUBLIC_MODEL, $choice->model);
        $this->assertSame('google/gemini-3.8-flash', $choice->fallback);

        $this->assertSame('', TriageLlms::make($this->logger(), 'private')->choose('g/p', 'x/y')->fallback, 'not open source: no fallback');

        putenv('KELPIE_TRIAGE_MODEL=acme/other-contributor');
        $this->assertSame('', TriageLlms::make($this->logger(), 'private', null, 'g/p', 'mit')->choose('g/p', 'x/y')->fallback, 'a training tier is no fallback');
        putenv('KELPIE_TRIAGE_MODEL');
    }

    /**
     * The choice says whether the project is open source, so the family guard knows which models may have written the
     * review; null when the project could not be read, so the guard fails closed
     *
     * @return void
     */
    public function testChoiceCarriesOpenSource(): void
    {
        $this->assertTrue(TriageLlms::make($this->logger(), 'private', null, 'g/p', 'bsd-3-clause')->choose('g/p', 'x/y')->openSource);
        $this->assertFalse(TriageLlms::make($this->logger(), 'private')->choose('g/p', 'x/y')->openSource);
        $this->assertTrue(TriageLlms::make($this->logger(), 'private', null, 'g/p', 'mit')->choosePropose('g/p', 'x/y')->openSource);
        $this->assertNull(TriageLlms::make($this->logger(), null)->choose('g/p', 'x/y')->openSource, 'project unreadable');
        $this->assertNull(TriageLlms::make($this->logger(), null)->choosePropose('g/p', 'x/y')->openSource, 'project unreadable');
    }

    /**
     * An open-source project gets the public triage model, Solar Pro 4 unless KELPIE_TRIAGE_MODEL_PUBLIC says otherwise
     *
     * @return void
     */
    public function testOpenSourceGetsThePublicModel(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=google/gemini-3.8-flash');
        $this->assertSame(self::PUBLIC_MODEL, TriageLlms::make($this->logger(), 'private', null, 'g/p', 'bsd-3-clause')->choose('g/p', 'x/y')->model);
        $this->assertSame('google/gemini-3.8-flash', TriageLlms::make($this->logger(), 'public')->choose('g/p', 'x/y')->model);

        putenv('KELPIE_TRIAGE_MODEL_PUBLIC=acme/cheap');
        $this->assertSame('acme/cheap', TriageLlms::make($this->logger(), 'private', null, 'g/p', 'MIT')->choose('g/p', 'x/y')->model);
        putenv('KELPIE_TRIAGE_MODEL_PUBLIC');
        putenv('KELPIE_TRIAGE_MODEL');
    }

    /**
     * A training-tier model needs an open-source license; visibility alone is not enough
     *
     * @return void
     */
    public function testTrainingTierNeedsAnOpenSourceLicense(): void
    {
        putenv('KELPIE_TRIAGE_MODEL=' . self::PUBLIC_MODEL);
        foreach ([['bsd-3-clause', true], ['apache-2.0', true], ['', false], ['other', false]] as [$license, $allowed]) {
            $choice = TriageLlms::make($this->logger(), 'public', null, 'g/p', $license)->choose('g/p', 'x/y');
            $this->assertSame($allowed, $choice->refusal === '', 'license ' . ($license === '' ? 'none' : $license));
        }

        $this->assertNotSame('', TriageLlms::make($this->logger(), null, null, 'g/p', 'mit')->choose('g/p', 'x/y')->refusal);
        $this->assertNotSame('', TriageLlms::make($this->logger(), 'public', null, 'g/p', 'mit')->choose(null, 'x/y')->refusal);
        putenv('KELPIE_TRIAGE_MODEL');
    }

    /**
     * The proposer switches to KELPIE_PROPOSE_MODEL_PUBLIC only on an open-source project, and only when set
     *
     * @return void
     */
    public function testChoosePropose(): void
    {
        $this->assertSame('openai/gpt-5.5', TriageLlms::make($this->logger(), 'private', null, 'g/p', 'mit')->choosePropose('g/p', 'openai/gpt-5.5')->model);

        putenv('KELPIE_PROPOSE_MODEL_PUBLIC=' . self::PUBLIC_MODEL);
        $this->assertSame(self::PUBLIC_MODEL, TriageLlms::make($this->logger(), 'private', null, 'g/p', 'mit')->choosePropose('g/p', 'openai/gpt-5.5')->model);
        $this->assertSame('openai/gpt-5.5', TriageLlms::make($this->logger(), 'private')->choosePropose('g/p', 'openai/gpt-5.5')->model);
        putenv('KELPIE_PROPOSE_MODEL_PUBLIC');
    }

    /**
     * The shared guard: only a training tier is checked, and only a public project lets it through
     *
     * @return void
     */
    public function testRefusalFor(): void
    {
        $this->assertSame('', TriageLlms::make($this->logger(), 'private')->refusalFor('openai/gpt-5.5', 'g/p'));
        $this->assertSame('', TriageLlms::make($this->logger(), 'private', null, 'g/p', 'bsd-3-clause')->refusalFor(self::PUBLIC_MODEL, 'g/p'));
        $this->assertStringContainsString('not known to be open source', TriageLlms::make($this->logger(), 'public')->refusalFor(self::PUBLIC_MODEL, 'g/p'));
        $this->assertStringContainsString('not known to be open source', TriageLlms::make($this->logger(), 'public', null, 'g/p', 'mit')->refusalFor(self::PUBLIC_MODEL, null));
    }

    /**
     * Only a -contributor suffix counts as a training tier
     *
     * @return void
     */
    public function testTrainsOnPrompts(): void
    {
        $models = TriageLlms::make($this->logger());

        $this->assertTrue($models->trainsOnPrompts(self::PUBLIC_MODEL));
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
