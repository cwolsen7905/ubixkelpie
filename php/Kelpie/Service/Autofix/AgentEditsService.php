<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\Agent\FixerAgentInterface as FixerAgent;
use Kelpie\DataTransferObject\Completion;
use Kelpie\DataTransferObject\ProposedEdit;
use Kelpie\DataTransferObject\ProposedEdits;
use Kelpie\DataTransferObject\Thread;
use Kelpie\Sandbox\Checkout;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;

/**
 * Lets the fixing agent edit the scratch checkout, then writes what it changed as edits a push round can replay
 *
 * The one-shot propose model sees only the file the thread is on, so a fix that belongs in another file, or is a test,
 * is out of its reach. The agent reads the whole checkout and edits what the fix needs. What it changed is measured
 * from git, not from its words, and becomes one whole-file edit per file (old text at the head, new text after), or a
 * creation for a new file: the same exact-text form the propose model gives, so the proposal, the safety policy and
 * the push round treat both alike. A deleted or renamed file, too many files, a link, a very large file, an empty file
 * or one that is not UTF-8 text is refused, since it could not be stored or replayed.
 *
 * @see \Kelpie\Tests\Service\Autofix\AgentEditsServiceTest PHPUnit test case
 */
final class AgentEditsService
{
    private const int MAX_FILE_BYTES = 200000;

    private const int MAX_SUMMARY_LENGTH = 500;

    /**
     * Constructor
     *
     * @param Logger      $logger Logger
     * @param FixerAgent  $agent  The fixing agent
     * @param JsonService $json   JSON service
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly FixerAgent $agent,
        private readonly JsonService $json,
    ) {
    }

    /**
     * Run the agent in the checkout and read back its change
     *
     * @param Checkout $checkout The scratch checkout, open at the head; the agent's change is left in it, staged
     * @param Thread   $thread   The thread
     * @param string   $model    The model the agent runs on
     * @param string   $context  What triage and the author said
     *
     * @return ProposedEdits The edits, or why there are none
     */
    public function edit(Checkout $checkout, Thread $thread, string $model, string $context): ProposedEdits
    {
        $directory = $checkout->directory();

        // The agent never sees the repository; the pointer comes back clean whatever it did
        if (!$checkout->detachWorkTree()) {
            return new ProposedEdits([], '', 'The checkout could not be prepared for the fixing agent.');
        }

        try {
            $run = $this->agent->fix($directory, $thread, $model, $context);
        } finally {
            $attached = $checkout->attachWorkTree();
        }

        // A run that did not finish still spent: its cost goes back with the failure, for the spend caps
        $completion = $run->completion;
        if (!$attached) {
            return new ProposedEdits([], '', 'The checkout could not be restored after the fixing agent.', $completion);
        }
        if (!$run->finished || $completion === null) {
            return new ProposedEdits([], '', 'The fixing agent did not finish.', $completion);
        }

        $summary = mb_substr(trim((string) preg_replace('/\s+/', ' ', $completion->text)), 0, self::MAX_SUMMARY_LENGTH);

        $changes = $checkout->stageAndMeasure();
        if ($changes === null) {
            return $this->failure('git could not measure the fixing agent\'s change.', $summary, $completion);
        }
        if ($changes->paths === []) {
            return $this->failure($summary === '' ? 'The fixing agent made no change.' : 'The fixing agent made no change: ' . $summary, $summary, $completion);
        }
        if ($changes->deletesOrRenamesFiles) {
            return $this->failure('The fixing agent deleted or renamed a file.', $summary, $completion);
        }
        if (count($changes->paths) > EditApplyService::MAX_EDITS) {
            return $this->failure('The fixing agent changed ' . count($changes->paths) . ' files (limit ' . EditApplyService::MAX_EDITS . ').', $summary, $completion);
        }

        $edits = [];
        $rows  = [];
        foreach ($changes->paths as $path) {
            $edit = $this->editFor($checkout, $directory, $path);
            if (is_string($edit)) {
                return $this->failure($edit, $summary, $completion);
            }

            $edits[] = $edit;
            $rows[]  = ['file' => $edit->file, 'new' => $edit->new, 'old' => $edit->old];
        }

        $this->logger->info('Autofix agent made a change', ['discussion_id' => $thread->discussionId, 'files' => count($edits), 'lines' => $changes->changedLines]);

        return new ProposedEdits($edits, $summary, '', $completion, $this->json->encode($rows));
    }

    /**
     * One changed file as an edit
     *
     * @param Checkout $checkout  The checkout
     * @param string   $directory Its directory
     * @param string   $path      The repo-relative path git reported
     *
     * @return ProposedEdit|string The edit, or why it cannot be one
     */
    private function editFor(Checkout $checkout, string $directory, string $path): ProposedEdit|string
    {
        $file = $directory . '/' . $path;
        if (is_link($file) || !is_file($file)) {
            return 'The fixing agent left `' . $path . '` as something other than a plain file.';
        }

        $new = (string) file_get_contents($file);
        $old = $checkout->original($path);
        if ($old === null) {
            return 'git could not read `' . $path . '` at the head.';
        }
        if (strlen($new) > self::MAX_FILE_BYTES || strlen($old) > self::MAX_FILE_BYTES) {
            return 'The fixing agent changed `' . $path . '`, which is too large to store as a proposal.';
        }
        if ($new === '' || ($old === '' && $checkout->hasAtHead($path))) {
            return 'The fixing agent left `' . $path . '` empty, or changed a file that was empty, which a proposal cannot replay.';
        }
        if (!mb_check_encoding($new, 'UTF-8') || !mb_check_encoding($old, 'UTF-8')) {
            return 'The fixing agent changed `' . $path . '`, which is not UTF-8 text.';
        }

        return new ProposedEdit($path, $old, $new);
    }

    /**
     * No edits, with the reason and the agent's call (it was paid for)
     *
     * @param string     $reason     Why
     * @param string     $summary    The agent's closing words
     * @param Completion $completion The agent's call
     *
     * @return ProposedEdits
     */
    private function failure(string $reason, string $summary, Completion $completion): ProposedEdits
    {
        return new ProposedEdits([], $summary, $reason, $completion);
    }
}
