<?php
declare(strict_types=1);

namespace Tests\TestCase\Utility\Collection;

use Closure;
use Fyre\Utility\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use SortDirection;

use function array_map;

trait SortByTestTrait
{
    /**
     * @return array<string, array{array<array<string, mixed>>, Closure|string, array<mixed>}>
     */
    public static function sortByPathProvider(): array
    {
        $items = [
            ['id' => 1, 'value' => 'Item 101'],
            ['id' => 2, 'value' => 'Item 10'],
            ['id' => 3, 'value' => 'Item 1'],
            ['id' => 4, 'value' => 'Item 11'],
            ['id' => 5, 'value' => 'Item 100'],
        ];

        $expected = [
            ['id' => 3, 'value' => 'Item 1'],
            ['id' => 2, 'value' => 'Item 10'],
            ['id' => 4, 'value' => 'Item 11'],
            ['id' => 5, 'value' => 'Item 100'],
            ['id' => 1, 'value' => 'Item 101'],
        ];

        return [
            'field path' => [$items, 'value', $expected],
            'callback' => [$items, static fn(array $item): string => $item['value'], $expected],
            'nested path' => [
                array_map(static fn(array $item): array => ['data' => $item], $items),
                'data.value',
                [
                    [
                        'data' => ['id' => 3, 'value' => 'Item 1'],
                    ],
                    [
                        'data' => ['id' => 2, 'value' => 'Item 10'],
                    ],
                    [
                        'data' => ['id' => 4, 'value' => 'Item 11'],
                    ],
                    [
                        'data' => ['id' => 5, 'value' => 'Item 100'],
                    ],
                    [
                        'data' => ['id' => 1, 'value' => 'Item 101'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<array<string, mixed>> $values
     * @param array<mixed> $expected
     */
    #[DataProvider('sortByPathProvider')]
    public function testSortBy(array $values, Closure|string $valuePath, array $expected): void
    {
        $collection = new Collection($values);

        $this->assertArraysAreIdentical(
            $expected,
            $collection->sortBy($valuePath)->values()->toArray()
        );
    }

    public function testSortByDescending(): void
    {
        $collection = new Collection([
            [
                'id' => 1,
                'value' => 1.5,
            ],
            [
                'id' => 2,
                'value' => 1.25,
            ],
            [
                'id' => 3,
                'value' => 2,
            ],
            [
                'id' => 4,
                'value' => 1.75,
            ],
            [
                'id' => 5,
                'value' => 1,
            ],
        ]);

        $this->assertArraysAreIdentical(
            [
                [
                    'id' => 3,
                    'value' => 2,
                ],
                [
                    'id' => 4,
                    'value' => 1.75,
                ],
                [
                    'id' => 1,
                    'value' => 1.5,
                ],
                [
                    'id' => 2,
                    'value' => 1.25,
                ],
                [
                    'id' => 5,
                    'value' => 1,
                ],
            ],
            $collection
                ->sortBy('value', Collection::SORT_NUMERIC, direction: SortDirection::Descending)
                ->values()
                ->toArray()
        );
    }

    public function testSortByFlag(): void
    {
        $collection = new Collection([
            [
                'id' => 1,
                'value' => 1.5,
            ],
            [
                'id' => 2,
                'value' => 1.25,
            ],
            [
                'id' => 3,
                'value' => 2,
            ],
            [
                'id' => 4,
                'value' => 1.75,
            ],
            [
                'id' => 5,
                'value' => 1,
            ],
        ]);

        $this->assertArraysAreIdentical(
            [
                [
                    'id' => 5,
                    'value' => 1,
                ],
                [
                    'id' => 2,
                    'value' => 1.25,
                ],
                [
                    'id' => 1,
                    'value' => 1.5,
                ],
                [
                    'id' => 4,
                    'value' => 1.75,
                ],
                [
                    'id' => 3,
                    'value' => 2,
                ],
            ],
            $collection
                ->sortBy('value', Collection::SORT_NUMERIC)
                ->values()
                ->toArray()
        );
    }
}
