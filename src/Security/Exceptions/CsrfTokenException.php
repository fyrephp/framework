<?php
declare(strict_types=1);

namespace Fyre\Security\Exceptions;

use Fyre\Http\Exceptions\ForbiddenException;
use Override;

/**
 * Represents an exception thrown for invalid CSRF tokens.
 */
class CsrfTokenException extends ForbiddenException
{
    #[Override]
    protected const DEFAULT_MESSAGE = 'CSRF Token Mismatch';
}
