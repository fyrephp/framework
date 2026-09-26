<?php
declare(strict_types=1);

namespace Tests\TestCase\Form\Rules;

use Fyre\Form\Rule;
use PHPUnit\Framework\Attributes\DataProvider;

trait InTestTrait
{
    /**
     * @return array<string, array{array<bool|float|int|string>, array<string, mixed>, array<string, string[]>}>
     */
    public static function inProvider(): array
    {
        return [
            'value' => [['test', 'other'], ['test' => 'test'], []],
            'empty' => [['test', 'other'], ['test' => ''], []],
            'false' => [[false], ['test' => false], []],
            'float' => [[2.5], ['test' => 2.5], []],
            'floatString' => [[2.5], ['test' => '2.5'], ['test' => ['The test must be one of the values: 2.5']]],
            'integer' => [[1], ['test' => 1], []],
            'integerFloat' => [[1], ['test' => 1.0], ['test' => ['The test must be one of the values: 1']]],
            'integerString' => [[1], ['test' => '1'], ['test' => ['The test must be one of the values: 1']]],
            'integerTrue' => [[1], ['test' => true], ['test' => ['The test must be one of the values: 1']]],
            'invalid' => [['test', 'other'], ['test' => 'invalid'], ['test' => ['The test must be one of the values: test, other']]],
            'missing' => [['test', 'other'], [], []],
            'mixed' => [['test', 1, 2.5, false], ['test' => 1], []],
            'true' => [[true], ['test' => true], []],
            'zero' => [[0], ['test' => 0], []],
            'zeroFalse' => [[0], ['test' => false], ['test' => ['The test must be one of the values: 0']]],
        ];
    }

    /**
     * @param array<bool|float|int|string> $values
     * @param array<string, mixed> $data
     * @param array<string, string[]> $expected
     */
    #[DataProvider('inProvider')]
    public function testIn(array $values, array $data, array $expected): void
    {
        $this->validator->add('test', Rule::in($values));

        $this->assertArraysAreIdentical(
            $expected,
            $this->validator->validate($data)
        );
    }
}
