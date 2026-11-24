<?php
declare(strict_types=1);

namespace Fyre\Http\Exceptions;

use Override;

/**
 * Represents a Conflict HTTP exception (409).
 */
class ConflictException extends HttpException
{
    #[Override]
    protected const DEFAULT_CODE = 409;

    #[Override]
    protected const DEFAULT_MESSAGE = 'Conflict';
}
