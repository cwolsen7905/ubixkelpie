<?php

declare(strict_types=1);

namespace Kelpie\Enum;

/**
 * What triage decided about one issue
 *
 * @see \Kelpie\Tests\Enum\IssueTriageClassTest PHPUnit test case
 */
enum IssueTriageClass: string
{
    case Fix        = 'fix';
    case NeedsHuman = 'needs_human';
    case NotADefect = 'not_a_defect';

    /**
     * The one `kelpie::*` label an issue of this class gets
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Fix        => 'kelpie::fixable',
            self::NeedsHuman => 'kelpie::needs-human',
            self::NotADefect => 'kelpie::not-a-defect',
        };
    }

    /**
     * The words after "Kelpie triage:" in the comment heading
     *
     * @return string
     */
    public function heading(): string
    {
        return match ($this) {
            self::Fix        => 'Kelpie could attempt a fix',
            self::NeedsHuman => 'needs a human',
            self::NotADefect => 'not a defect',
        };
    }
}
