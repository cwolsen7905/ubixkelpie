<?php

declare(strict_types=1);

namespace Kelpie\Settings\Exception;

use Exception;

/**
 * A settings request named a project that has no row in `projects`
 *
 * The API does not create rows, so this is a 404, never an empty `off` project.
 *
 * @see \Kelpie\Tests\Settings\Exception\ProjectNotFoundExceptionTest PHPUnit test case
 */
final class ProjectNotFoundException extends Exception
{
}
