<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The parts of a GitLab issue that triage reads
 *
 * The title and description are untrusted text and are never logged or stored.
 *
 * @see \Kelpie\Tests\DataTransferObject\IssueDetailsTest PHPUnit test case
 */
final readonly class IssueDetails implements Dto
{
    /**
     * Constructor
     *
     * @param string   $title        The issue title
     * @param string   $description  The issue description
     * @param string   $author       The author's username
     * @param bool     $confidential Whether the issue is confidential
     * @param string[] $labels       The label titles on the issue
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $author,
        public bool $confidential,
        public array $labels = [],
    ) {
    }
}
