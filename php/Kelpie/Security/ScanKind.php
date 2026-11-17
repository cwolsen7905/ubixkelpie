<?php

declare(strict_types=1);

namespace Kelpie\Security;

/**
 * What a context scan can find in text on its way to a model
 *
 * @see \Kelpie\Tests\Security\ScanKindTest PHPUnit test case
 */
enum ScanKind: string
{
    case HtmlComment     = 'html_comment';
    case InvisibleChar   = 'invisible_char';
    case BidiControl     = 'bidi_control';
    case InjectionPhrase = 'injection_phrase';
    case SecretPath      = 'secret_path';
}
