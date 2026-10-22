<?php

declare(strict_types=1);

namespace Kelpie\DataTransferObject;

use Ubix\DataTransferObject\DtoInterface as Dto;

/**
 * One model answer with what it cost
 *
 * @see \Kelpie\Tests\DataTransferObject\CompletionTest PHPUnit test case
 */
final readonly class Completion implements Dto
{
    /**
     * Constructor
     *
     * @param string $text             The model's text
     * @param string $model            The model id the call asked for
     * @param int    $promptTokens     Tokens in the prompt, 0 when the provider did not say
     * @param int    $completionTokens Tokens in the answer, 0 when the provider did not say
     * @param ?float $costUsd          The cost in US dollars, null when the provider did not say
     */
    public function __construct(
        public string $text,
        public string $model,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public ?float $costUsd = null,
    ) {
    }
}
