<?php

declare(strict_types=1);

namespace Kelpie\Service\Gitlab;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\Enum\EnqueueOutcome;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Service\Autofix\AutofixJobService;
use Psr\Log\LoggerInterface as Logger;

/**
 * Handle an Emoji Hook: a thumbs-up or thumbs-down on one of Kelpie's proposals
 *
 * Only a reaction from the MR's author or a maintainer counts, only on a note that is the proposal of an
 * open ("proposed") outcome, and never Kelpie's own. The webhook does not push: it writes a job that
 * `kelpie:work` runs (apply the stored edits, or mark the proposal declined), because checking out and
 * running the gate takes far longer than a webhook may.
 *
 * @see \Kelpie\Tests\Service\Gitlab\ProposalReactionWebhookServiceTest PHPUnit test case
 */
final class ProposalReactionWebhookService
{
    public const string THUMBS_UP = 'thumbsup';

    public const string THUMBS_DOWN = 'thumbsdown';

    /**
     * Constructor
     *
     * @param Logger              $logger      Logger
     * @param ThreadActorService  $actors      Who may direct Kelpie
     * @param ThreadOutcomeReader $outcomes    The record of what Kelpie proposed
     * @param AutofixJobService   $jobs        The job queue
     * @param bool                $enabled     Whether autofix may run (KELPIE_AUTOFIX_ENABLED)
     * @param string              $botUsername The bot's username; its own reactions are ignored
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ThreadActorService $actors,
        private readonly ThreadOutcomeReader $outcomes,
        private readonly AutofixJobService $jobs,
        private readonly bool $enabled = false,
        private readonly string $botUsername = 'kelpie-bot',
    ) {
    }

    /**
     * Process an Emoji Hook payload
     *
     * @param array<int|string, mixed> $payload The decoded webhook payload
     *
     * @return string What happened; 'ignored: ...' or 'skipped: ...' when nothing was queued
     */
    public function handle(array $payload): string
    {
        $award = $this->arr($payload, 'object_attributes');
        $user  = $this->arr($payload, 'user');
        $mr    = $this->arr($payload, 'merge_request');
        $name  = $this->str($award, 'name');

        if (!in_array($name, [self::THUMBS_UP, self::THUMBS_DOWN], true)) {
            return 'ignored: not a thumbs reaction';
        }
        if ($this->str($award, 'awardable_type') !== 'Note') {
            return 'ignored: not on a note';
        }
        if ($this->int($mr, 'iid') === 0) {
            return 'ignored: not on a merge request';
        }
        if ($this->str($user, 'username') === $this->botUsername) {
            return 'ignored: the bot\'s own reaction';
        }

        $project   = $this->arr($payload, 'project');
        $path      = $this->str($project, 'path_with_namespace');
        $projectId = $this->int($project, 'id') ?: $this->int($payload, 'project_id');
        $noteId    = $this->int($award, 'awardable_id');
        $mrIid     = $this->int($mr, 'iid');

        $row = $path === '' || $noteId === 0 ? null : $this->outcomes->getByProposalNote($path, $mrIid, $noteId);
        if ($row === null) {
            return 'ignored: not a proposal of mine';
        }
        if ($row->outcome !== ThreadOutcome::Proposed) {
            return 'ignored: the proposal is already ' . $row->outcome->value;
        }
        if ($name === self::THUMBS_UP && $row->editsJson === '') {
            return 'ignored: nothing to apply, this proposal has no edits';
        }
        if (!$this->actors->may($projectId, $this->int($user, 'id'), $this->int($mr, 'author_id'))) {
            $this->logger->info('Proposal reaction: not the author or a maintainer', ['mr_iid' => $mrIid, 'project_path' => $path]);

            return 'ignored: reactor is not the MR author or a maintainer';
        }
        if (!$this->enabled) {
            return 'skipped: autofix disabled';
        }

        $source    = $name === self::THUMBS_UP ? AutofixJob::APPLY : AutofixJob::DECLINE;
        [$outcome] = $this->jobs->pendingJob(new AutofixJob($path, $mrIid, $row->headSha, $source, $row->discussionId), new DateTimeImmutable());

        $this->logger->info('Proposal reaction: queued', ['discussion_id' => $row->discussionId, 'mr_iid' => $mrIid, 'outcome' => $outcome->value, 'source' => $source]);

        return match ($outcome) {
            EnqueueOutcome::Enqueued    => 'queued: ' . $source . ' ' . $path . '!' . $mrIid . '#' . $row->discussionId,
            EnqueueOutcome::Coalesced   => 'skipped: another run is already queued on this thread, react again when it is done',
            EnqueueOutcome::CapExceeded => 'skipped: autofix cap reached',
        };
    }

    /**
     * @param array<int|string, mixed> $data The data
     * @param string                   $key  The key
     *
     * @return array<int|string, mixed>
     */
    private function arr(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<int|string, mixed> $data The data
     * @param string                   $key  The key
     *
     * @return string
     */
    private function str(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @param array<int|string, mixed> $data The data
     * @param string                   $key  The key
     *
     * @return int
     */
    private function int(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : 0;
    }
}
