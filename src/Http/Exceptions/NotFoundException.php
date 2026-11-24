<?php
declare(strict_types=1);

namespace Fyre\Http\Exceptions;

use Override;

/**
 * Represents a Not Found HTTP exception (404).
 */
class NotFoundException extends HttpException
{
    #[Override]
    protected const DEFAULT_CODE = 404;

    #[Override]
    protected const DEFAULT_MESSAGE = 'Not Found';
}
