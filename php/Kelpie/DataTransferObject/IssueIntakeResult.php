<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Kelpie\Enum\IssueIntake;
use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The classification of one Issue Hook event
 *
 * Every field is data taken from the event; none of it is ever interpreted as an instruction.
 *
 * @see \Kelpie\Tests\DataTransferObject\IssueIntakeResultTest PHPUnit test case
 */
final readonly class IssueIntakeResult implements Dto
{
    /**
     * Constructor
     *
     * @param IssueIntake $class       The class the event falls into
     * @param string      $reason      A short fixed reason
     * @param string      $projectPath The project path with namespace
     * @param int         $issueIid    The issue number in the project
     * @param string      $actor       The user who opened the issue or applied the label
     * @param ?int        $accessLevel The actor's project access level, set for a gate event
     */
    public function __construct(
        public IssueIntake $class,
        public string $reason,
        public string $projectPath = '',
        public int $issueIid = 0,
        public string $actor = '',
        public ?int $accessLevel = null,
    ) {
    }
}
