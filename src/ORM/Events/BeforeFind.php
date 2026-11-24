<?php
declare(strict_types=1);

namespace Fyre\ORM\Events;

use Attribute;
use Override;

/**
 * Attribute that marks a method as an `ORM.beforeFind` event listener.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class BeforeFind extends On
{
    #[Override]
    protected const EVENT_KEY = 'beforeFind';
}
