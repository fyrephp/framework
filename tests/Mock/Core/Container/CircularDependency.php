<?php
declare(strict_types=1);

namespace Tests\Mock\Core\Container;

class CircularDependency
{
    public function __construct(
        protected CircularService $service
    ) {}
}
