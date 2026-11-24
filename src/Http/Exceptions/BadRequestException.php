<?php
declare(strict_types=1);

namespace Fyre\Http\Exceptions;

use Override;

/**
 * Represents a Bad Request HTTP exception (400).
 */
class BadRequestException extends HttpException
{
    #[Override]
    protected const DEFAULT_CODE = 400;

    #[Override]
    protected const DEFAULT_MESSAGE = 'Bad Request';
}
