<?php
declare(strict_types=1);

namespace Fyre\Http\Exceptions;

use Override;

/**
 * Represents an Unauthorized HTTP exception (401).
 *
 * You can pass a `WWW-Authenticate` header via {@see HttpException::__construct()} `$headers`.
 */
class UnauthorizedException extends HttpException
{
    #[Override]
    protected const DEFAULT_CODE = 401;

    #[Override]
    protected const DEFAULT_MESSAGE = 'Unauthorized';
}
