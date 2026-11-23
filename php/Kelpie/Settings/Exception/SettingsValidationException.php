<?php

declare(strict_types=1);

namespace Kelpie\Settings\Exception;

use Exception;
use Throwable;

/**
 * A settings patch broke one or more rules; nothing was written
 *
 * @see \Kelpie\Tests\Settings\Exception\SettingsValidationExceptionTest PHPUnit test case
 */
final class SettingsValidationException extends Exception
{
    /**
     * Constructor
     *
     * @param string                                      $message  The message
     * @param int                                         $code     The exception code
     * @param list<array{field: string, message: string}> $errors   Every rule the patch broke
     * @param ?Throwable                                  $previous The previous throwable
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        private readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return list<array{field: string, message: string}> Every rule the patch broke
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
