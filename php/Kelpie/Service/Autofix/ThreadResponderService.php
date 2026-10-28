<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\Thread;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\Service\Triage\TriageService;
use Psr\Log\LoggerInterface as Logger;

/**
 * For each thread: skip it if answered or claimed, claim, triage, reply or label, release
 *
 * No code changes. An MR with `triage:off`, a draft, or one another run is working on is left alone. While
 * it works the MR carries `triage: working`, a summary note and 👀 on the threads; at the end the note says
 * what was done and one of `triage: pushed`, `proposed` or `needs you` stays (see `TriageStatusService`).
 *
 * @see \Kelpie\Tests\Service\Autofix\ThreadResponderServiceTest PHPUnit test case
 */
final class ThreadResponderService
{
    /**
     * Constructor
     *
     * @param Logger              $logger        Logger
     * @param DiscussionWriter    $writer        The GitLab writer
     * @param ClaimService        $claimService  The claim marker
     * @param ReplyService        $replyService  The reply and label step
     * @param TriageService       $triageService Classifies one thread
     * @param TriageStatusService $status        The status labels and the summary note
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly DiscussionWriter $writer,
        private readonly ClaimService $claimService,
        private readonly ReplyService $replyService,
        private readonly TriageService $triageService,
        private readonly TriageStatusService $status,
    ) {
    }

    /**
     * Respond to the given threads of one MR
     *
     * @param string   $project The project ID or URL-encodable path
     * @param int      $mrIid   The MR IID
     * @param string   $sha     The MR head sha the threads were read at
     * @param Thread[] $threads The unresolved threads
     *
     * @return array<string, ResponseOutcome> What was done, by discussion ID
     */
    public function respond(string $project, int $mrIid, string $sha, array $threads): array
    {
        $mr = $this->writer->mergeRequest($project, $mrIid);
        if ($mr === null) {
            return $this->all($threads, ResponseOutcome::Failed);
        }
        $skip = $this->status->skipReason($mr) ?? ($this->status->isClaimed($mr) ? 'another triage run is working on it' : null);
        if ($skip !== null) {
            $this->logger->info('Autofix skipped a merge request', ['mr_iid' => $mrIid, 'reason' => $skip]);

            return $this->all($threads, ResponseOutcome::Skipped);
        }

        $outcomes = [];
        $claims   = [];
        $picked   = [];

        foreach ($threads as $thread) {
            $id    = $thread->discussionId;
            $notes = $this->writer->notes($project, $mrIid, $id);

            if ($notes === null) {
                $outcomes[$id] = ResponseOutcome::Failed;
                continue;
            }
            if ($this->replyService->isAnswered($notes, $id)) {
                $outcomes[$id] = ResponseOutcome::AlreadyAnswered;
                continue;
            }

            $claim = $this->claimService->claim($project, $mrIid, $id, $sha);
            if ($claim instanceof ResponseOutcome) {
                $outcomes[$id] = $claim;
                continue;
            }

            $claims[$id] = $claim;
            $picked[]    = $thread;
        }

        if ($picked === []) {
            return $outcomes;
        }

        $run     = $this->status->start($project, $mrIid, $picked);
        $results = [];

        try {
            foreach ($picked as $thread) {
                $id = $thread->discussionId;

                try {
                    $verdict       = $this->triageService->triage($thread);
                    $outcomes[$id] = $this->replyService->respond($project, $mrIid, $id, $verdict, $thread);
                    $results[$id]  = $this->status->resultFor($verdict, $outcomes[$id]);
                } finally {
                    $this->claimService->release($project, $mrIid, $id, $claims[$id], $sha);
                }
            }
        } finally {
            $this->status->finish($project, $mrIid, $run, $results);
        }

        return $outcomes;
    }

    /**
     * The same outcome for every thread
     *
     * @param Thread[]        $threads The threads
     * @param ResponseOutcome $outcome The outcome
     *
     * @return array<string, ResponseOutcome>
     */
    private function all(array $threads, ResponseOutcome $outcome): array
    {
        $outcomes = [];
        foreach ($threads as $thread) {
            $outcomes[$thread->discussionId] = $outcome;
        }

        return $outcomes;
    }
}
