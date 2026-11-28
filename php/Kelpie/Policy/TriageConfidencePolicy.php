<?php

declare(strict_types=1);

namespace Kelpie\Policy;

use Kelpie\DataTransferObject\IssueTriageVerdict;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\IssueTriageClass;
use Kelpie\Enum\TriageClass;
use Kelpie\Enum\TriageConfidence;
use Psr\Log\LoggerInterface as Logger;

/**
 * Lets a fix through only when the model was sure enough of it
 *
 * A `fix` verdict below the threshold becomes `needs_human`, with the model's reason kept and the confidence named,
 * so nothing is proposed or pushed on a guess. Every other class passes unchanged: only a fix is a risk.
 *
 * @see \Kelpie\Tests\Policy\TriageConfidencePolicyTest PHPUnit test case
 */
final class TriageConfidencePolicy
{
    /**
     * Constructor
     *
     * @param Logger           $logger  Logger
     * @param TriageConfidence $minimum The least confidence a fix needs
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly TriageConfidence $minimum = TriageConfidence::Medium,
    ) {
    }

    /**
     * Gate a thread verdict
     *
     * @param TriageVerdict $verdict The verdict
     *
     * @return TriageVerdict The same verdict, or `needs_human` when a fix is not sure enough
     */
    public function apply(TriageVerdict $verdict): TriageVerdict
    {
        if ($verdict->class !== TriageClass::Fix || $verdict->confidence->atLeast($this->minimum)) {
            return $verdict;
        }

        $this->logger->info('Triage: fix downgraded to needs_human, not confident enough', ['confidence' => $verdict->confidence->value]);

        return new TriageVerdict(TriageClass::NeedsHuman, $this->reason($verdict->confidence, $verdict->reason), $verdict->completion, $verdict->confidence);
    }

    /**
     * Gate an issue verdict
     *
     * @param IssueTriageVerdict $verdict The verdict
     *
     * @return IssueTriageVerdict The same verdict, or `needs_human` when a fix is not sure enough
     */
    public function applyToIssue(IssueTriageVerdict $verdict): IssueTriageVerdict
    {
        if ($verdict->class !== IssueTriageClass::Fix || $verdict->confidence->atLeast($this->minimum)) {
            return $verdict;
        }

        $this->logger->info('Issue triage: fix downgraded to needs_human, not confident enough', ['confidence' => $verdict->confidence->value]);

        return new IssueTriageVerdict(IssueTriageClass::NeedsHuman, $this->reason($verdict->confidence, $verdict->reason), $verdict->completion, $verdict->confidence);
    }

    /**
     * @param TriageConfidence $confidence The model's confidence
     * @param string           $reason     The model's own reason
     *
     * @return string
     */
    private function reason(TriageConfidence $confidence, string $reason): string
    {
        return 'Not sure enough to fix automatically (' . $confidence->value . ' confidence): ' . $reason;
    }
}
