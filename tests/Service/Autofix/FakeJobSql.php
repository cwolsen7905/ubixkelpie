<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Generator;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;

/**
 * A job table behind an SQL stub: what the queue does for one enqueue, and the parameters of every job inserted
 */
final class FakeJobSql
{
    public const string FREE = 'free';

    public const string BUSY = 'busy';

    public const string CAPPED = 'capped';

    /**
     * Script the stub for one enqueue and build the job service over it
     *
     * @param Logger          $logger  A logger
     * @param SqlService&Stub $sql     The SQL stub
     * @param InsertedJobs    $inserts Receives the parameters of every inserted job
     * @param string          $mode    FREE (an enqueue is pending), BUSY (an open job at this head covers it) or CAPPED
     * @param string          $sha     The head
     *
     * @return AutofixJobService
     */
    public static function service(Logger $logger, SqlService&Stub $sql, InsertedJobs $inserts, string $mode, string $sha): AutofixJobService
    {
        $sql->method('getColumn')->willReturn($mode === self::CAPPED ? '99' : '0');
        $sql->method('lastInsertId')->willReturn('7');
        $sql->method('query')->willReturnCallback(static function (string $statement, array $parameters = []) use ($inserts): int {
            if (str_contains($statement, 'INSERT INTO ubixkelpie.autofix_jobs')) {
                $bound = [];
                foreach ($parameters as $name => $value) {
                    $bound[(string) $name] = $value;
                }

                $inserts->add($bound);
            }

            return 1;
        });
        $sql->method('getRows')->willReturnOnConsecutiveCalls(
            $mode === self::BUSY ? self::rows($sha) : self::rows(null),
            self::rows($sha),
        );

        return new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql));
    }

    /**
     * @param ?string $sha The head of the one pending job, null for no rows
     *
     * @return Generator<int, array<string, mixed>>
     */
    private static function rows(?string $sha): Generator
    {
        if ($sha !== null) {
            yield self::row($sha);
        }
    }

    /**
     * @param string $sha The head of the pending job
     *
     * @return array<string, mixed>
     */
    private static function row(string $sha): array
    {
        return [
            'after_autofix' => '0',
            'attempt'       => '0',
            'commit_sha'    => null,
            'date_created'  => '2026-10-15 12:00:00',
            'discussion_id' => 'd1',
            'head_sha'      => $sha,
            'id'            => '7',
            'mr_iid'        => '3',
            'project_path'  => 'g/p',
            'source'        => 'note',
            'status'        => 'pending',
            'steering'      => null,
            'thread_key'    => 'd1',
            'triage_class'  => null,
        ];
    }
}
