<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\Enum\EnqueueOutcome;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeReaderInterface as ThreadOutcomeReader;
use Kelpie\Repository\ThreadOutcome\ThreadOutcomeWriterInterface as ThreadOutcomeWriter;
use Kelpie\Service\Gitlab\ThreadActorService;
use Psr\Log\LoggerInterface as Logger;

/**
 * Handle a "Triage:" reply in a thread Kelpie has already looked at: run the thread again with the text after the colon as steering
 *
 * Only the MR's author or a maintainer may. A "Triage:" note from anyone else is ignored whole, and the
 * webhook does not queue its ordinary re-triage either.
 *
 * @see \Kelpie\Tests\Service\Autofix\TriageReplyServiceTest PHPUnit test case
 */
final class TriageReplyService
{
    public const int MAX_STEERING = 1000;

    private const string COMMAND = '/^\s*Triage:(.*)$/is';

    /**
     * Constructor
     *
     * @param Logger              $logger      Logger
     * @param ThreadActorService  $actors      Who may direct Kelpie
     * @param ThreadOutcomeReader $reader      The record of what Kelpie did about each thread
     * @param ThreadOutcomeWriter $writer      Where the thread is marked reopened
     * @param AutofixJobService   $jobs        The job queue
     * @param bool                $enabled     Whether autofix may run (KELPIE_AUTOFIX_ENABLED)
     * @param string              $botUsername The bot's username; it cannot give the command
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ThreadActorService $actors,
        private readonly ThreadOutcomeReader $reader,
        private readonly ThreadOutcomeWriter $writer,
        private readonly AutofixJobService $jobs,
        private readonly bool $enabled = false,
        private readonly string $botUsername = 'kelpie-bot',
    ) {
    }

    /**
     * Whether a Note Hook is a "Triage:" command on an MR thread note
     *
     * @param array<int|string, mixed> $payload The decoded webhook payload
     *
     * @return bool
     */
    public function isCommand(array $payload): bool
    {
        $oa = $this->arr($payload, 'object_attributes');

        return ($oa['noteable_type'] ?? '') === 'MergeRequest'
        && ($oa['system'] ?? false) !== true
        && in_array($oa['type'] ?? null, ['DiffNote', 'DiscussionNote'], true)
        && $this->str($this->arr($payload, 'user'), 'username') !== $this->botUsername
        && preg_match(self::COMMAND, $this->str($oa, 'note')) === 1;
    }

    /**
     * Process a "Triage:" note
     *
     * @param array<int|string, mixed> $payload The decoded webhook payload
     *
     * @return string What happened; 'ignored: ...' or 'skipped: ...' when nothing was queued
     */
    public function handle(array $payload): string
    {
        if (!$this->isCommand($payload)) {
            return 'ignored: not a Triage: reply';
        }

        $oa      = $this->arr($payload, 'object_attributes');
        $mr      = $this->arr($payload, 'merge_request');
        $project = $this->arr($payload, 'project');
        $path    = $this->str($project, 'path_with_namespace');
        $mrIid   = $this->int($mr, 'iid');
        $thread  = $this->str($oa, 'discussion_id');
        $sha     = $this->str($this->arr($mr, 'last_commit'), 'id');

        if ($path === '' || $mrIid === 0 || $thread === '' || $sha === '') {
            return 'ignored: the note does not say which thread or head';
        }
        if ($this->reader->getByThread($path, $mrIid, $thread) === null) {
            return 'ignored: I have not looked at this thread';
        }

        $projectId = $this->int($project, 'id') ?: $this->int($payload, 'project_id');
        if (!$this->actors->may($projectId, $this->int($this->arr($payload, 'user'), 'id'), $this->int($mr, 'author_id'))) {
            $this->logger->info('Triage reply: not the author or a maintainer', ['mr_iid' => $mrIid, 'project_path' => $path]);

            return 'ignored: replier is not the MR author or a maintainer';
        }
        if (!$this->enabled) {
            return 'skipped: autofix disabled';
        }

        $steering  = $this->steering($this->str($oa, 'note'));
        [$outcome] = $this->jobs->pendingJob(new AutofixJob($path, $mrIid, $sha, 'note', $thread, false, $steering === '' ? null : $steering), new DateTimeImmutable());

        $this->logger->info('Triage reply: queued', ['discussion_id' => $thread, 'mr_iid' => $mrIid, 'outcome' => $outcome->value, 'steering_length' => strlen($steering)]);

        if ($outcome === EnqueueOutcome::Enqueued) {
            $this->writer->setOutcome($path, $mrIid, $thread, ThreadOutcome::Reopened);

            return 'queued: triage again ' . $path . '!' . $mrIid . '#' . $thread;
        }

        return $outcome === EnqueueOutcome::Coalesced ? 'skipped: a run is already queued on this thread, reply again when it is done' : 'skipped: autofix cap reached';
    }

    /**
     * The text after "Triage:", trimmed and capped
     *
     * @param string $note The note body
     *
     * @return string
     */
    private function steering(string $note): string
    {
        preg_match(self::COMMAND, $note, $found);

        return mb_substr(trim($found[1] ?? ''), 0, self::MAX_STEERING);
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
