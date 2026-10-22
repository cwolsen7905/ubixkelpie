<?php

declare(strict_types=1);

namespace Kelpie\Tests\Repository\ThreadOutcome;

use Generator;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeSqlRepository;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Repository\ThreadOutcome\ThreadOutcomeSqlRepository
 *
 * What must hold:
 * - A run is one upsert keyed by the thread, so a second run replaces the first row
 * - Empty edits and diff are stored as NULL, and an unknown cost stays NULL
 * - A row reads back as the record it was written from
 * - A later reaction changes only the outcome
 *
 * @coversDefaultClass \Kelpie\Repository\ThreadOutcome\ThreadOutcomeSqlRepository
 */
final class ThreadOutcomeSqlRepositoryTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ThreadOutcomeSqlRepository::class);
    }

    /**
     * Recording is an upsert on the thread with every field bound
     *
     * @return void
     */
    public function testRecordUpsertsTheThreadRow(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('query')->with(
            $this->logicalAnd($this->stringContains('INSERT INTO ubixkelpie.thread_outcomes'), $this->stringContains('ON DUPLICATE KEY UPDATE')),
            $this->callback(
                static function (array $p): bool {
                    return $p['project_path'] === 'g/p' && $p['mr_iid'] === 3 && $p['discussion_id'] === 'd1' && $p['outcome'] === 'proposed'
                        && $p['cost_usd'] === 0.002 && $p['prompt_tokens'] === 200 && $p['proposal_note_id'] === 105
                        && $p['edits_json'] === '[]' && is_string($p['diff']) && str_contains($p['diff'], '+two') && $p['review_family'] === 'anthropic';
                },
            ),
        )->willReturn(1);

        (new ThreadOutcomeSqlRepository($this->createStub(Logger::class), $sql))->record($this->record());
    }

    /**
     * Nothing to store is NULL, not an empty string or a zero cost
     *
     * @return void
     */
    public function testEmptyValuesAreNull(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('query')->with(
            $this->anything(),
            $this->callback(
                static function (array $p): bool {
                    return $p['cost_usd'] === null && $p['diff'] === null && $p['edits_json'] === null && $p['proposal_note_id'] === null && is_string($p['reason']) && strlen($p['reason']) === 500;
                },
            ),
        )->willReturn(1);

        (new ThreadOutcomeSqlRepository($this->createStub(Logger::class), $sql))->record(new ThreadOutcomeRecord('g/p', 3, 'd1', str_repeat('a', 40), 'needs_human', 'propose', ThreadOutcome::Answered, str_repeat('r', 600)));
    }

    /**
     * A row reads back as a record
     *
     * @return void
     */
    public function testGetByThreadHydrates(): void
    {
        $sql = $this->createStub(SqlService::class);
        $sql->method('getRows')->willReturnCallback(static function (): Generator {
            yield [
                'completion_tokens' => '40',
                'cost_usd'          => '0.002000',
                'diff'              => '+two',
                'discussion_id'     => 'd1',
                'edits_json'        => '[]',
                'head_sha'          => str_repeat('a', 40),
                'mode'              => 'propose',
                'mr_iid'            => '3',
                'outcome'           => 'declined',
                'project_path'      => 'g/p',
                'prompt_tokens'     => '200',
                'propose_model'     => 'openai/gpt-5.5',
                'proposal_note_id'  => '105',
                'reason'            => 'Adds a line.',
                'review_family'     => 'anthropic',
                'triage_model'      => 'google/gemini-3-flash',
                'verdict'           => 'fix',
            ];
        });

        $record = (new ThreadOutcomeSqlRepository($this->createStub(Logger::class), $sql))->getByThread('g/p', 3, 'd1');

        $this->assertNotNull($record);
        $this->assertSame(ThreadOutcome::Declined, $record->outcome);
        $this->assertSame(0.002, $record->costUsd);
        $this->assertSame(105, $record->proposalNoteId);
        $this->assertSame('openai/gpt-5.5', $record->proposeModel);
        $this->assertSame(200, $record->promptTokens);
        $this->assertSame(3, $record->mrIid);
    }

    /**
     * A thread Kelpie never touched has no row
     *
     * @return void
     */
    public function testGetByThreadWithoutARowIsNull(): void
    {
        $sql = $this->createStub(SqlService::class);
        $sql->method('getRows')->willReturnCallback(static function (): Generator {
            yield from [];
        });

        $this->assertNull((new ThreadOutcomeSqlRepository($this->createStub(Logger::class), $sql))->getByThread('g/p', 3, 'd1'));
    }

    /**
     * A later reaction updates the outcome of that thread's row only
     *
     * @return void
     */
    public function testSetOutcomeUpdatesOnlyTheOutcome(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('query')->with(
            $this->logicalAnd($this->stringContains('UPDATE ubixkelpie.thread_outcomes SET outcome = :outcome'), $this->stringContains('discussion_id = :discussion_id')),
            ['discussion_id' => 'd1', 'mr_iid' => 3, 'outcome' => 'applied', 'project_path' => 'g/p'],
        )->willReturn(1);

        (new ThreadOutcomeSqlRepository($this->createStub(Logger::class), $sql))->setOutcome('g/p', 3, 'd1', ThreadOutcome::Applied);
    }

    /**
     * @return ThreadOutcomeRecord
     */
    private function record(): ThreadOutcomeRecord
    {
        return new ThreadOutcomeRecord(
            'g/p',
            3,
            'd1',
            str_repeat('a', 40),
            'fix',
            'propose',
            ThreadOutcome::Proposed,
            'Adds a line.',
            'google/gemini-3-flash',
            'openai/gpt-5.5',
            'anthropic',
            200,
            40,
            0.002,
            105,
            '[]',
            "+two\n",
        );
    }
}
