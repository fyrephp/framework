<?php
declare(strict_types=1);

namespace Fyre\Http\Exceptions;

use Override;

/**
 * Represents a Service Unavailable HTTP exception (503).
 */
class ServiceUnavailableException extends HttpException
{
    #[Override]
    protected const DEFAULT_CODE = 503;

    #[Override]
    protected const DEFAULT_MESSAGE = 'Service Unavailable';
}
