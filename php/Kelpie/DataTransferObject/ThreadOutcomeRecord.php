<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Kelpie\Enum\ThreadOutcome;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * What Kelpie did about one thread and what became of it (one row of `thread_outcomes`)
 *
 * @see \Kelpie\Tests\DataTransferObject\ThreadOutcomeRecordTest PHPUnit test case
 */
final readonly class ThreadOutcomeRecord implements Dto
{
    /**
     * Constructor
     *
     * @param string        $projectPath      The project path
     * @param int           $mrIid            The MR IID
     * @param string        $discussionId     The discussion ID
     * @param string        $headSha          The head the proposal was made at
     * @param string        $verdict          The triage class
     * @param string        $mode             The mode Kelpie acted in (`propose`)
     * @param ThreadOutcome $outcome          What became of it
     * @param string        $reason           The model's short reason, or why it failed
     * @param string        $triageModel      The triage model
     * @param string        $proposeModel     The propose model, empty when none was called
     * @param string        $reviewFamily     The family of the model that wrote the review
     * @param int           $promptTokens     Prompt tokens over both calls
     * @param int           $completionTokens Answer tokens over both calls
     * @param ?float        $costUsd          Cost over both calls, null when no call said
     * @param ?int          $proposalNoteId   The note carrying the proposal
     * @param string        $editsJson        The edits as JSON, empty when none
     * @param string        $diff             The diff posted, empty when none
     */
    public function __construct(
        public string $projectPath,
        public int $mrIid,
        public string $discussionId,
        public string $headSha,
        public string $verdict,
        public string $mode,
        public ThreadOutcome $outcome,
        public string $reason = '',
        public string $triageModel = '',
        public string $proposeModel = '',
        public string $reviewFamily = '',
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public ?float $costUsd = null,
        public ?int $proposalNoteId = null,
        public string $editsJson = '',
        public string $diff = '',
    ) {
    }
}
