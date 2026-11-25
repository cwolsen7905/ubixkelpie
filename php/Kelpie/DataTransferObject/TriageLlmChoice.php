<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * The model a triage call should use, or why no model may be called
 *
 * @see \Kelpie\Tests\DataTransferObject\TriageLlmChoiceTest PHPUnit test case
 */
final readonly class TriageLlmChoice implements Dto
{
    /**
     * Constructor
     *
     * @param string $model      The model id
     * @param string $refusal    Why the model must not be called, safe to show to a human; empty when it may
     * @param string $fallback   The model to ask once when `$model` gives no answer; empty for none
     * @param ?bool  $openSource Whether the project is open source, null when not known (its license could not be
     *                           read); the family guard refuses both review families unless it is false
     */
    public function __construct(
        public string $model,
        public string $refusal = '',
        public string $fallback = '',
        public ?bool $openSource = null,
    ) {
    }
}
