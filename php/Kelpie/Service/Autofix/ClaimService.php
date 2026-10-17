<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\DiscussionNote;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\GitLab\DiscussionWriter;
use Psr\Log\LoggerInterface as Logger;

/**
 * Claims a thread so two workers never take the same one (design section 6)
 *
 * A claim is a note in the thread carrying `<!-- kelpie:claim thread=<id> sha=<sha> -->`. A claim from
 * anyone younger than the TTL blocks a new one. GitLab has no compare-and-set, so after posting
 * the claim the thread is read again and the oldest fresh claim wins; the loser releases its own.
 * A released claim is its note rewritten to a `kelpie:release` marker, so it no longer counts.
 *
 * @see \Kelpie\Tests\Autofix\ClaimServiceTest PHPUnit test case
 */
final class ClaimService
{
    public const string LABEL = 'kelpie::working';

    private const int DEFAULT_TTL_SECONDS = 1800;

    /**
     * Constructor
     *
     * @param Logger           $logger     Logger
     * @param DiscussionWriter $writer     The GitLab writer
     * @param int              $ttlSeconds How long a claim holds the thread
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly DiscussionWriter $writer,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    /**
     * Try to claim a thread
     *
     * @param string             $project      The project ID or URL-encodable path
     * @param int                $mrIid        The MR IID
     * @param string             $discussionId The discussion ID
     * @param string             $sha          The MR head sha the work is for
     * @param ?DateTimeImmutable $now          The current time (a test seam)
     *
     * @return int|ResponseOutcome The id of the claim note when held; ClaimedByOther or Failed otherwise
     */
    public function claim(string $project, int $mrIid, string $discussionId, string $sha, ?DateTimeImmutable $now = null): int|ResponseOutcome
    {
        $now ??= new DateTimeImmutable();
        $notes = $this->writer->notes($project, $mrIid, $discussionId);
        if ($notes === null) {
            return ResponseOutcome::Failed;
        }
        if ($this->freshClaims($notes, $discussionId, $now) !== []) {
            return ResponseOutcome::ClaimedByOther;
        }

        $body = sprintf("Kelpie is looking at this thread.\n\n<!-- kelpie:claim thread=%s sha=%s -->", $discussionId, $sha);
        $id   = $this->writer->reply($project, $mrIid, $discussionId, $body);
        if ($id === null) {
            return ResponseOutcome::Failed;
        }

        $after = $this->writer->notes($project, $mrIid, $discussionId);
        if ($after === null) {
            $this->release($project, $mrIid, $discussionId, $id, $sha);

            return ResponseOutcome::Failed;
        }

        $winner = min($this->freshClaims($after, $discussionId, $now) ?: [$id]);
        if ($winner !== $id) {
            $this->logger->info('Autofix claim lost', ['discussion_id' => $discussionId, 'mr_iid' => $mrIid]);
            $this->release($project, $mrIid, $discussionId, $id, $sha);

            return ResponseOutcome::ClaimedByOther;
        }

        return $id;
    }

    /**
     * Release a claim this worker holds
     *
     * @param string $project      The project ID or URL-encodable path
     * @param int    $mrIid        The MR IID
     * @param string $discussionId The discussion ID
     * @param int    $claimNoteId  The claim note ID
     * @param string $sha          The MR head sha
     *
     * @return void
     */
    public function release(string $project, int $mrIid, string $discussionId, int $claimNoteId, string $sha): void
    {
        $body = sprintf("Kelpie is done with this thread for now.\n\n<!-- kelpie:release thread=%s sha=%s -->", $discussionId, $sha);

        if (!$this->writer->editNote($project, $mrIid, $discussionId, $claimNoteId, $body)) {
            $this->logger->warning('Autofix claim could not be released; it expires on its own', ['claim_note_id' => $claimNoteId]);
        }
    }

    /**
     * The ids of the unexpired claims for a thread
     *
     * @param DiscussionNote[]  $notes        The thread's notes
     * @param string            $discussionId The discussion ID
     * @param DateTimeImmutable $now          The current time
     *
     * @return list<int>
     */
    private function freshClaims(array $notes, string $discussionId, DateTimeImmutable $now): array
    {
        $marker = '<!-- kelpie:claim thread=' . $discussionId . ' ';
        $oldest = $now->modify('-' . $this->ttlSeconds . ' seconds');
        $ids    = [];

        foreach ($notes as $note) {
            if (str_contains($note->body, $marker) && $note->createdAt >= $oldest) {
                $ids[] = $note->id;
            }
        }

        return $ids;
    }
}
