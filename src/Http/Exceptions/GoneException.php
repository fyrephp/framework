<?php
declare(strict_types=1);

namespace Fyre\Http\Exceptions;

use Override;

/**
 * Represents a Gone HTTP exception (410).
 */
class GoneException extends HttpException
{
    #[Override]
    protected const DEFAULT_CODE = 410;

    #[Override]
    protected const DEFAULT_MESSAGE = 'Gone';
}
