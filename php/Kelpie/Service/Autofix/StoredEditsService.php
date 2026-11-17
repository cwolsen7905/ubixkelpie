<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\DataTransferObject\ProposedEdit;
use Psr\Log\LoggerInterface as Logger;
use Throwable;
use Ubix\Service\JsonService;

/**
 * Reads back the edits a proposal stored as JSON
 *
 * @see \Kelpie\Tests\Service\Autofix\StoredEditsServiceTest PHPUnit test case
 */
final class StoredEditsService
{
    /**
     * Constructor
     *
     * @param Logger      $logger Logger
     * @param JsonService $json   JSON service
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly JsonService $json,
    ) {
    }

    /**
     * The stored edits
     *
     * @param string $editsJson The JSON the proposal stored
     *
     * @return list<ProposedEdit> Empty when the JSON is not a list of edits
     */
    public function decode(string $editsJson): array
    {
        try {
            $rows = $this->json->decode($editsJson);
        } catch (Throwable $e) {
            $this->logger->warning('Stored edits are not JSON', ['error' => $e->getMessage()]);

            return [];
        }

        $edits = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['file'] ?? null) || !is_string($row['old'] ?? null) || !is_string($row['new'] ?? null)) {
                return [];
            }

            $edits[] = new ProposedEdit($row['file'], $row['old'], $row['new']);
        }

        return $edits;
    }
}
