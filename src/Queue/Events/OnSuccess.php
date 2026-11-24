<?php
declare(strict_types=1);

namespace Fyre\Queue\Events;

use Attribute;
use Override;

/**
 * Attribute that marks a method as a `Queue.success` event listener.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class OnSuccess extends On
{
    #[Override]
    protected const EVENT_KEY = 'success';
}
