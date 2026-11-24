<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\Collection;

use Fyre\Utility\Collection;
use SortDirection;

trait SortTestTrait
{
    public function testSort(): void
    {
        $collection = new Collection(['Item 101', 'Item 10', 'Item 1', 'Item 11', 'Item 100']);

        $this->assertArraysAreIdentical(
            ['Item 1', 'Item 10', 'Item 11', 'Item 100', 'Item 101'],
            $collection->sort()->values()->toArray()
        );
    }

    public function testSortCallback(): void
    {
        $collection = new Collection([1.5, 1.25, 2, 1.75, 1]);

        $this->assertArraysAreIdentical(
            [1, 1.25, 1.5, 1.75, 2],
            $collection->sort(static fn(float|int $a, float|int $b): int => $a <=> $b)->values()->toArray()
        );
    }

    public function testSortDescending(): void
    {
        $collection = new Collection([1.5, 1.25, 2, 1.75, 1]);

        $this->assertArraysAreIdentical(
            [2, 1.75, 1.5, 1.25, 1],
            $collection->sort(Collection::SORT_NUMERIC, SortDirection::Descending)->values()->toArray()
        );
    }

    public function testSortFlag(): void
    {
        $collection = new Collection([1.5, 1.25, 2, 1.75, 1]);

        $this->assertArraysAreIdentical(
            [1, 1.25, 1.5, 1.75, 2],
            $collection->sort(Collection::SORT_NUMERIC)->values()->toArray()
        );
    }
}
