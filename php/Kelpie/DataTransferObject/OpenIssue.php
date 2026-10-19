<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One open issue as the project's issue list shows it
 *
 * @see \Kelpie\Tests\DataTransferObject\OpenIssueTest PHPUnit test case
 */
final readonly class OpenIssue implements Dto
{
    /**
     * Constructor
     *
     * @param int      $iid          The issue number in the project
     * @param string   $author       The author's username
     * @param bool     $confidential Whether the issue is confidential
     * @param string[] $labels       The label titles on the issue
     */
    public function __construct(
        public int $iid,
        public string $author,
        public bool $confidential,
        public array $labels = [],
    ) {
    }
}
