<?php

declare(strict_types=1);

namespace Kelpie\Autofix;

use Kelpie\DataTransferObject\Thread;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\Triage\TriageService;
use Psr\Log\LoggerInterface as Logger;

/**
 * For each thread: skip it if answered or claimed, claim, triage, reply or label, release
 *
 * No code changes. The `kelpie::working` label is on the MR while it works and comes off at the end.
 *
 * @see \Kelpie\Tests\Autofix\ThreadResponderTest PHPUnit test case
 */
final class ThreadResponder
{
    /**
     * Constructor
     *
     * @param Logger           $logger        Logger
     * @param DiscussionWriter $writer        The GitLab writer
     * @param ClaimService     $claimService  The claim marker
     * @param ReplyService     $replyService  The reply and label step
     * @param TriageService    $triageService Classifies one thread
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly DiscussionWriter $writer,
        private readonly ClaimService $claimService,
        private readonly ReplyService $replyService,
        private readonly TriageService $triageService,
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
        $outcomes = [];
        $labelled = false;

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

            if (!$labelled) {
                $labelled = $this->writer->addLabel($project, $mrIid, ClaimService::LABEL);
            }

            try {
                $outcomes[$id] = $this->replyService->respond($project, $mrIid, $id, $this->triageService->triage($thread));
            } finally {
                $this->claimService->release($project, $mrIid, $id, $claim, $sha);
            }
        }

        if ($labelled && !$this->writer->removeLabel($project, $mrIid, ClaimService::LABEL)) {
            $this->logger->warning('Autofix working label could not be removed', ['mr_iid' => $mrIid]);
        }

        return $outcomes;
    }
}
