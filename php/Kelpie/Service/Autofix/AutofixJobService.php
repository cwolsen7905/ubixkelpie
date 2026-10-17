<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\DataTransferObject\AutofixCaps;
use Kelpie\Enum\EnqueueOutcome;
use Kelpie\Model\AutofixJobRecord;
use Kelpie\Repository\AutofixJob\AutofixJobReaderInterface as AutofixJobReader;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Repository\AutofixJob\AutofixJobWriterInterface as AutofixJobWriter;
use Psr\Log\LoggerInterface as Logger;

/**
 * The autofix job table as the fixer's callers see it: get the one pending job for a thread, or why not
 *
 * Coalescing and the caps are the coalescer's; this only adds the lookup of the job it enqueued
 * (or found already open). Hands out the reader and writer for the runner. Needs the SQL repository
 * itself, not its interfaces, so that the CLI container can build it without extra bindings.
 *
 * @see \Kelpie\Tests\Service\Autofix\AutofixJobServiceTest PHPUnit test case
 */
final class AutofixJobService
{
    private readonly AutofixJobCoalescerService $coalescer;

    /**
     * Constructor
     *
     * @param Logger                  $logger     Logger
     * @param AutofixJobSqlRepository $repository The job repository
     * @param AutofixCaps             $caps       The caps
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly AutofixJobSqlRepository $repository,
        AutofixCaps $caps = new AutofixCaps(),
    ) {
        $this->coalescer = new AutofixJobCoalescerService($this->logger, $this->repository, $this->repository, $caps);
    }

    /**
     * Enqueue a job (or coalesce into the open one) and return the open job when it is pending at this head
     *
     * @param AutofixJob        $input The filtered job
     * @param DateTimeImmutable $now   The current time, for the daily cap
     *
     * @return array{0: EnqueueOutcome, 1: ?AutofixJobRecord} What happened, and the pending job when there is one to run
     */
    public function pendingJob(AutofixJob $input, DateTimeImmutable $now): array
    {
        $outcome = $this->coalescer->enqueue($input, $now);
        if ($outcome === EnqueueOutcome::CapExceeded) {
            return [$outcome, null];
        }

        $job = $this->repository->getOpenJob($input->projectPath, $input->mrIid, $input->discussionId ?? '');
        if ($job === null || $job->getId() === null || $job->getStatus() !== 'pending' || $job->getHeadSha() !== $input->headSha) {
            return [$outcome, null];
        }

        return [$outcome, $job];
    }

    /**
     * The job reader
     *
     * @return AutofixJobReader
     */
    public function reader(): AutofixJobReader
    {
        return $this->repository;
    }

    /**
     * The job writer
     *
     * @return AutofixJobWriter
     */
    public function writer(): AutofixJobWriter
    {
        return $this->repository;
    }
}
