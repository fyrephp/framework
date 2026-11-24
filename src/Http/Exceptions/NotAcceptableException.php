<?php
declare(strict_types=1);

namespace Fyre\Http\Exceptions;

use Override;

/**
 * Represents a Not Acceptable HTTP exception (406).
 */
class NotAcceptableException extends HttpException
{
    #[Override]
    protected const DEFAULT_CODE = 406;

    #[Override]
    protected const DEFAULT_MESSAGE = 'Not Acceptable';
}
