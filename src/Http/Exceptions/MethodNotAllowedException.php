<?php
declare(strict_types=1);

namespace Fyre\Http\Exceptions;

use Override;

/**
 * Represents a Method Not Allowed HTTP exception (405).
 *
 * You can pass an `Allow` header via {@see HttpException::__construct()} `$headers` to
 * indicate which methods are permitted.
 */
class MethodNotAllowedException extends HttpException
{
    #[Override]
    protected const DEFAULT_CODE = 405;

    #[Override]
    protected const DEFAULT_MESSAGE = 'Method Not Allowed';
}
