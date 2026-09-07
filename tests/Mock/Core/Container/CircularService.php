<?php
declare(strict_types=1);

namespace Tests\Mock\Core\Container;

class CircularService
{
    public function __construct(
        protected CircularDependency $dependency
    ) {}
}
