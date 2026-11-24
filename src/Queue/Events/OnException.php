<?php
declare(strict_types=1);

namespace Fyre\Queue\Events;

use Attribute;
use Override;

/**
 * Attribute that marks a method as a `Queue.exception` event listener.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class OnException extends On
{
    #[Override]
    protected const EVENT_KEY = 'exception';
}
