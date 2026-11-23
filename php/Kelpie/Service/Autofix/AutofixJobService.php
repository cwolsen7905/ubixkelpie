<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixCaps;
use Kelpie\DataTransferObject\AutofixJob;
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
     * @param ?AutofixCaps            $caps       The caps; null reads them from the environment (`AutofixCapsService`)
     * @param ?CapNoticeService       $notice     Tells an MR its job was refused for the hard cap; null builds one on the environment, which posts nothing when GitLab is not configured
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly AutofixJobSqlRepository $repository,
        ?AutofixCaps $caps = null,
        private ?CapNoticeService $notice = null,
    ) {
        $caps          ??= (new AutofixCapsService($this->logger))->fromEnvironment();
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
            $this->noticeDailyCap($input, $now);

            return [$outcome, null];
        }

        $this->noticeSoftCap($input, $now);

        $job = $this->repository->getOpenJob($input->projectPath, $input->mrIid, $this->coalescer->threadKey($input));
        if ($job === null || $job->getId() === null || $job->getStatus() !== 'pending' || $job->getHeadSha() !== $input->headSha) {
            return [$outcome, null];
        }

        return [$outcome, $job];
    }

    /**
     * Which cap, if any, a job would break right now
     *
     * @param AutofixJob        $input The job
     * @param DateTimeImmutable $now   The current time
     *
     * @return ?string `commits_per_mr`, `attempts_per_thread`, `jobs_per_project`, `jobs_per_mr`, or null
     */
    public function refusedCap(AutofixJob $input, DateTimeImmutable $now): ?string
    {
        return $this->coalescer->exceededCapFor($input, $now);
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

    /**
     * When the refusal was for a daily job cap (the MR's or the project's), say so once on the MR
     *
     * Reads the same windows the coalescer counted. Never throws.
     *
     * @param AutofixJob        $input The refused job
     * @param DateTimeImmutable $now   The current time
     *
     * @return void
     */
    private function noticeDailyCap(AutofixJob $input, DateTimeImmutable $now): void
    {
        // Only when a daily cap is the one that refused it: another cap keeps the MR blocked after the window frees
        $cap = $this->coalescer->exceededCapFor($input, $now);
        if ($cap !== 'jobs_per_mr' && $cap !== 'jobs_per_project') {
            return;
        }

        // The hard cap is the one that refused the job, so it is the number the note names; a null hard limit never refuses
        $limit = $cap === 'jobs_per_mr' ? $this->coalescer->caps()->jobsPerMrHard : $this->coalescer->caps()->jobsPerProjectHard;
        if ($limit === null) {
            return;
        }

        $since = $now->modify('-1 day');
        $mrIid = $cap === 'jobs_per_mr' ? $input->mrIid : null;
        $used  = $mrIid === null ? $this->repository->countJobsSince($input->projectPath, $since) : $this->repository->countJobsForMrSince($input->projectPath, $mrIid, $since);

        // The cap frees when enough of the oldest jobs age out of the window for the count to drop below it
        $freesAt        = $this->repository->getJobCreatedAt($input->projectPath, $since, $used - $limit, $mrIid);
        $this->notice ??= new CapNoticeService($this->logger);
        $this->notice->jobsExceeded($cap, $input->projectPath, $input->mrIid, $used, $limit, $freesAt?->modify('+1 day'), $now);
    }

    /**
     * When work is proceeding past a soft cap, say so once on the MR (or in the log for the project cap)
     *
     * A soft cap is a warning: the work keeps running. The MR-scoped caps (commits, attempts, MR jobs) get an MR note;
     * the project cap is not MR-scoped, so it only logs. Never throws.
     *
     * @param AutofixJob        $input The job
     * @param DateTimeImmutable $now   The current time
     *
     * @return void
     */
    private function noticeSoftCap(AutofixJob $input, DateTimeImmutable $now): void
    {
        $cap = $this->coalescer->softCapExceededFor($input, $now);
        if ($cap === null) {
            return;
        }

        $caps = $this->coalescer->caps();
        if ($cap === 'jobs_per_project') {
            $since = $now->modify('-1 day');
            $this->logger->warning('Soft cap passed: project daily job count', ['project_path' => $input->projectPath, 'used' => $this->repository->countJobsSince($input->projectPath, $since), 'soft_cap' => $caps->jobsPerProject]);

            return;
        }

        $since          = $now->modify('-1 day');
        $mrIid          = $input->mrIid;
        [$limit, $used] = match ($cap) {
            'commits_per_mr'      => [$caps->commitsPerMr, $this->repository->countFixCommitsForMr($input->projectPath, $mrIid)],
            'attempts_per_thread' => [$caps->attemptsPerThread, $this->repository->countAttemptsForThread($input->projectPath, $mrIid, $this->coalescer->threadKey($input))],
            default               => [$caps->jobsPerMr, $this->repository->countJobsForMrSince($input->projectPath, $mrIid, $since)],
        };

        $this->notice ??= new CapNoticeService($this->logger);
        $this->notice->softCapExceeded($cap, $input->projectPath, $mrIid, $used, $limit, $now);
    }
}
