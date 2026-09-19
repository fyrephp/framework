<?php
declare(strict_types=1);

namespace Tests\TestCase\Core\Make;

use Fyre\Core\Container;
use Fyre\Core\Make;
use Fyre\Core\Make\ModelSourceBuilder;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Schema\Column;
use Fyre\DB\Schema\Index;
use Fyre\DB\Types\DateTimeType;
use Fyre\DB\Types\IntegerType;
use PHPUnit\Framework\TestCase;

use function implode;

use const PHP_EOL;

final class ModelSourceBuilderValidatorTest extends TestCase
{
    public function testNonAutoIncrementPrimaryKeyIsRequired(): void
    {
        $column = $this->createStub(Column::class);
        $column->method('getName')->willReturn('code');
        $column->method('type')->willReturn(new IntegerType());

        $index = $this->createStub(Index::class);
        $index->method('isPrimary')->willReturn(true);
        $index->method('getColumns')->willReturn(['code']);

        $source = new Container()->use(ModelSourceBuilder::class)->build(
            namespace: 'Example\Models',
            className: 'ItemsModel',
            entityNamespace: 'Example\Entities',
            entityClass: 'Item',
            enumNamespace: 'Example\Enums',
            fields: [$column],
            indexes: [$index],
            enums: [],
            relationships: [],
            connection: ConnectionManager::DEFAULT,
            table: null,
            withValidation: true,
            withRules: false
        );

        $this->assertSame(
            Make::loadStub('model', [
                '{namespace}' => 'Example\Models',
                '{uses}' => implode(PHP_EOL, [
                    'use Example\Entities\Item;',
                    'use Fyre\Form\Rule;',
                    'use Fyre\Form\Validator;',
                    'use Fyre\ORM\Model;',
                    'use Fyre\ORM\RuleSet;',
                    'use Override;',
                ]),
                '{docblock}' => implode(PHP_EOL, [
                    '/**',
                    ' * @extends Model<Item>',
                    ' */',
                ]),
                '{attributes}'.PHP_EOL => '',
                '{class}' => 'ItemsModel',
                '{traits}'.PHP_EOL => '',
                '{properties}'.PHP_EOL => '',
                '{rules}'.PHP_EOL => '',
                '{validator}'.PHP_EOL => implode(PHP_EOL, [
                    '        $validator->add(\'code\', Rule::required(), on: \'create\', name: \'required\');',
                    '        $validator->add(\'code\', Rule::integer(), name: \'integer\');',
                    '',
                    '',
                ]),
            ]),
            $source
        );
    }

    public function testTimestampFieldIsNotRequired(): void
    {
        $column = $this->createStub(Column::class);
        $column->method('getName')->willReturn('created');
        $column->method('getType')->willReturn('datetime');
        $column->method('type')->willReturn(new DateTimeType());

        $source = new Container()->use(ModelSourceBuilder::class)->build(
            namespace: 'Example\Models',
            className: 'ItemsModel',
            entityNamespace: 'Example\Entities',
            entityClass: 'Item',
            enumNamespace: 'Example\Enums',
            fields: [$column],
            indexes: [],
            enums: [],
            relationships: [],
            connection: ConnectionManager::DEFAULT,
            table: null,
            withValidation: true,
            withRules: false
        );

        $this->assertSame(
            Make::loadStub('model', [
                '{namespace}' => 'Example\Models',
                '{uses}' => implode(PHP_EOL, [
                    'use Example\Entities\Item;',
                    'use Fyre\Form\Rule;',
                    'use Fyre\Form\Validator;',
                    'use Fyre\ORM\Model;',
                    'use Fyre\ORM\RuleSet;',
                    'use Fyre\ORM\Traits\TimestampsTrait;',
                    'use Override;',
                ]),
                '{docblock}' => implode(PHP_EOL, [
                    '/**',
                    ' * @extends Model<Item>',
                    ' *',
                    ' * @use TimestampsTrait<Item>',
                    ' */',
                ]),
                '{attributes}'.PHP_EOL => '',
                '{class}' => 'ItemsModel',
                '{traits}'.PHP_EOL => implode(PHP_EOL, [
                    '    use TimestampsTrait;',
                    '',
                    '',
                ]),
                '{properties}'.PHP_EOL => '',
                '{rules}'.PHP_EOL => '',
                '{validator}'.PHP_EOL => implode(PHP_EOL, [
                    '        $validator->add(\'created\', Rule::dateTime(), name: \'datetime\');',
                    '',
                    '',
                ]),
            ]),
            $source
        );
    }
}
