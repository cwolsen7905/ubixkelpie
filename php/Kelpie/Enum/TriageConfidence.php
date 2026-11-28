<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * How sure the triage model says it is of its verdict
 *
 * An answer with no confidence, or one that is not a known word, reads as `Low`: Kelpie never takes a missing
 * answer for a sure one.
 *
 * @see \Kelpie\Tests\Enum\TriageConfidenceTest PHPUnit test case
 */
enum TriageConfidence: string
{
    case Low    = 'low';
    case Medium = 'medium';
    case High   = 'high';

    /**
     * Read what a model answered
     *
     * @param mixed $answer The raw `confidence` field of the model's JSON
     *
     * @return self `Low` when it is missing or unknown
     */
    public static function fromAnswer(mixed $answer): self
    {
        return is_string($answer) ? (self::tryFrom(strtolower(trim($answer))) ?? self::Low) : self::Low;
    }

    /**
     * Whether this confidence is at least the given one
     *
     * @param self $minimum The least confidence that is enough
     *
     * @return bool
     */
    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    /**
     * Order of the cases, the higher the surer
     *
     * @return int
     */
    private function rank(): int
    {
        return match ($this) {
            self::Low    => 0,
            self::Medium => 1,
            self::High   => 2,
        };
    }
}
