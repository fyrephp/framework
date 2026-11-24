<?php
declare(strict_types=1);

namespace Fyre\ORM\Events;

use Attribute;
use Override;

/**
 * Attribute that marks a method as an `ORM.buildJoin` event listener.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class BuildJoin extends On
{
    #[Override]
    protected const EVENT_KEY = 'buildJoin';
}
