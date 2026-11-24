<?php
declare(strict_types=1);

namespace Fyre\Http\Exceptions;

use Override;

/**
 * Represents a Forbidden HTTP exception (403).
 */
class ForbiddenException extends HttpException
{
    #[Override]
    protected const DEFAULT_CODE = 403;

    #[Override]
    protected const DEFAULT_MESSAGE = 'Forbidden';
}
