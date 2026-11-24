<?php
declare(strict_types=1);

namespace Fyre\Http\Exceptions;

use Override;

/**
 * Represents a Not Implemented HTTP exception (501).
 */
class NotImplementedException extends HttpException
{
    #[Override]
    protected const DEFAULT_CODE = 501;

    #[Override]
    protected const DEFAULT_MESSAGE = 'Not Implemented';
}
