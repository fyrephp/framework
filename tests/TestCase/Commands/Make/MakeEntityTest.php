<?php
declare(strict_types=1);

namespace Tests\TestCase\Commands\Make;

use Fyre\Console\Command;
use Fyre\Console\CommandRunner;
use Fyre\Console\Console;
use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Loader;
use Fyre\Core\Make;
use Fyre\Core\Make\EntitySourceBuilder;
use Fyre\Core\Make\ModelSourceBuilder;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Schema\Column;
use Fyre\DB\Schema\SchemaRegistry;
use Fyre\DB\TypeParser;
use Fyre\DB\Types\DateTimeType;
use Fyre\DB\Types\DateType;
use Fyre\DB\Types\TimeType;
use Fyre\Event\EventManager;
use Fyre\ORM\EntityLocator;
use Fyre\ORM\ModelRegistry;
use Fyre\Utility\Inflector;
use Fyre\Utility\Path;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;

use function fclose;
use function file_put_contents;
use function fopen;
use function implode;
use function mkdir;
use function rewind;
use function rmdir;
use function stream_get_contents;
use function unlink;

use const PHP_EOL;
use const ROOT;

final class MakeEntityTest extends TestCase
{
    protected CommandRunner $commandRunner;

    protected Container $container;

    /**
     * @var resource
     */
    protected $error;

    /**
     * @var resource
     */
    protected $input;

    /**
     * @var resource
     */
    protected $output;

    public function testMakeEntity(): void
    {
        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:entity', ['Example'])
        );

        rewind($this->output);
        $this->assertSame(
            "\033[0;32mGenerated: tmp/Entities/Example.php\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
        rewind($this->error);
        $this->assertSame('', stream_get_contents($this->error));

        $filePath = 'tmp/Entities/Example.php';

        $this->assertFileExists($filePath);

        $this->assertFileMatchesFormat(
            Make::loadStub('entity', [
                '{namespace}' => 'Example\Entities',
                '{class}' => 'Example',
                '{uses}' => 'use Fyre\ORM\Entity;',
                '{docblock}'.PHP_EOL => '',
                '{body}' => '    //',
            ]),
            $filePath
        );
    }

    public function testMakeEntityDateField(): void
    {
        $column = $this->createStub(Column::class);
        $column->method('getName')->willReturn('published_on');
        $column->method('type')->willReturn(new DateType());

        $source = $this->container->use(EntitySourceBuilder::class)
            ->build(
                'Example\Entities',
                'Post',
                [$column],
                []
            );

        $this->assertSame(
            Make::loadStub('entity', [
                '{namespace}' => 'Example\Entities',
                '{class}' => 'Post',
                '{uses}' => implode(PHP_EOL, [
                    'use Fyre\ORM\Entity;',
                    'use Fyre\Utility\DateTime\Date;',
                ]),
                '{docblock}'.PHP_EOL => implode(PHP_EOL, [
                    '/**',
                    ' * @property Date $published_on',
                    ' */',
                    '',
                ]),
                '{body}' => '    //',
            ]),
            $source
        );
    }

    public function testMakeEntityDateTimeField(): void
    {
        $column = $this->createStub(Column::class);
        $column->method('getName')->willReturn('created_at');
        $column->method('type')->willReturn(new DateTimeType());

        $source = $this->container->use(EntitySourceBuilder::class)
            ->build(
                'Example\Entities',
                'Post',
                [$column],
                []
            );

        $this->assertSame(
            Make::loadStub('entity', [
                '{namespace}' => 'Example\Entities',
                '{class}' => 'Post',
                '{uses}' => implode(PHP_EOL, [
                    'use Fyre\ORM\Entity;',
                    'use Fyre\Utility\DateTime\DateTime;',
                ]),
                '{docblock}'.PHP_EOL => implode(PHP_EOL, [
                    '/**',
                    ' * @property DateTime $created_at',
                    ' */',
                    '',
                ]),
                '{body}' => '    //',
            ]),
            $source
        );
    }

    public function testMakeEntityExistingFile(): void
    {
        $filePath = 'tmp/Entities/Example.php';
        @mkdir('tmp/Entities', 0755, true);
        file_put_contents($filePath, 'changed');

        $this->assertSame(
            Command::CODE_ERROR,
            $this->commandRunner->run('make:entity', ['Example'])
        );

        rewind($this->output);
        $this->assertSame('', stream_get_contents($this->output));
        rewind($this->error);
        $this->assertSame(
            "\033[0;31mEntity file already exists.\033[0m".PHP_EOL,
            stream_get_contents($this->error)
        );
        $this->assertStringEqualsFile($filePath, 'changed');
    }

    public function testMakeEntityForce(): void
    {
        $filePath = 'tmp/Entities/Example.php';
        @mkdir('tmp/Entities', 0755, true);
        file_put_contents($filePath, 'changed');

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:entity', [
                'Example',
                'force' => true,
            ])
        );

        rewind($this->output);
        $this->assertSame(
            "\033[0;32mGenerated: ".$filePath."\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
        rewind($this->error);
        $this->assertSame('', stream_get_contents($this->error));

        $this->assertFileMatchesFormat(
            Make::loadStub('entity', [
                '{namespace}' => 'Example\Entities',
                '{class}' => 'Example',
                '{uses}' => 'use Fyre\ORM\Entity;',
                '{docblock}'.PHP_EOL => '',
                '{body}' => '    //',
            ]),
            $filePath
        );
    }

    public function testMakeEntityInferredRelationshipImports(): void
    {
        $this->container->use(EntityLocator::class)->addNamespace('Tests\Mock\Entities');
        $builder = $this->container->use(EntitySourceBuilder::class);
        $relationships = [[
            'type' => ModelSourceBuilder::BELONGS_TO,
            'alias' => 'Users',
            'targetModel' => 'UsersModel',
            'foreignKey' => ['user_id'],
            'bindingKey' => ['id'],
            'nullable' => false,
            'options' => [],
        ]];

        $relationshipData = $builder->buildInferredRelationshipData($relationships, 'Example\Entities');
        $source = $builder->build('Example\Entities', 'Post', [], $relationshipData);

        $this->assertSame(
            Make::loadStub('entity', [
                '{namespace}' => 'Example\Entities',
                '{class}' => 'Post',
                '{uses}' => implode(PHP_EOL, [
                    'use Fyre\ORM\Entity;',
                    'use Tests\Mock\Entities\User;',
                ]),
                '{docblock}'.PHP_EOL => implode(PHP_EOL, [
                    '/**',
                    ' * @property User $user',
                    ' */',
                    '',
                ]),
                '{body}' => '    //',
            ]),
            $source
        );
    }

    public function testMakeEntityNamespaceNotFound(): void
    {
        $this->assertSame(
            Command::CODE_ERROR,
            $this->commandRunner->run('make:entity', [
                'Example',
                'namespace' => 'Missing\Entities',
            ])
        );

        rewind($this->output);
        $this->assertSame('', stream_get_contents($this->output));
        rewind($this->error);
        $this->assertSame(
            "\033[0;31mNamespace path not found.\033[0m".PHP_EOL,
            stream_get_contents($this->error)
        );
        $this->assertFileDoesNotExist('tmp/Entities/Example.php');
    }

    public function testMakeEntityPreservesLoadedModel(): void
    {
        $modelRegistry = $this->container->use(ModelRegistry::class);
        $model = $modelRegistry->use('Example');

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:entity', ['Example'])
        );

        $this->assertSame(
            $model,
            $modelRegistry->use('Example')
        );
    }

    public function testMakeEntityRelationshipImportCollision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Import name `Author` collides between `First\Entities\Author` and `Second\Entities\Author`.'
        );

        $builder = $this->container->use(EntitySourceBuilder::class);

        $builder->build('Example\Entities', 'Post', [], [
            [
                'property' => 'author',
                'type' => 'Author',
                'className' => 'First\Entities\Author',
            ],
            [
                'property' => 'editor',
                'type' => 'Author',
                'className' => 'Second\Entities\Author',
            ],
        ]);
    }

    public function testMakeEntityRelationshipImports(): void
    {
        $source = $this->container->use(EntitySourceBuilder::class)
            ->build('Example\Entities', 'Post', [], [
                [
                    'property' => 'author',
                    'type' => 'Author',
                    'className' => 'Other\Entities\Author',
                ],
                [
                    'property' => 'tags',
                    'type' => 'Tag[]',
                    'className' => 'Example\Entities\Tag',
                ],
            ]);

        $this->assertSame(
            Make::loadStub('entity', [
                '{namespace}' => 'Example\Entities',
                '{class}' => 'Post',
                '{uses}' => implode(PHP_EOL, [
                    'use Fyre\ORM\Entity;',
                    'use Other\Entities\Author;',
                ]),
                '{docblock}'.PHP_EOL => implode(PHP_EOL, [
                    '/**',
                    ' * @property Author $author',
                    ' * @property Tag[] $tags',
                    ' */',
                    '',
                ]),
                '{body}' => '    //',
            ]),
            $source
        );
    }

    public function testMakeEntityTimeField(): void
    {
        $column = $this->createStub(Column::class);
        $column->method('getName')->willReturn('starts_at');
        $column->method('type')->willReturn(new TimeType());

        $source = $this->container->use(EntitySourceBuilder::class)
            ->build(
                'Example\Entities',
                'Post',
                [$column],
                []
            );

        $this->assertSame(
            Make::loadStub('entity', [
                '{namespace}' => 'Example\Entities',
                '{class}' => 'Post',
                '{uses}' => implode(PHP_EOL, [
                    'use Fyre\ORM\Entity;',
                    'use Fyre\Utility\DateTime\Time;',
                ]),
                '{docblock}'.PHP_EOL => implode(PHP_EOL, [
                    '/**',
                    ' * @property Time $starts_at',
                    ' */',
                    '',
                ]),
                '{body}' => '    //',
            ]),
            $source
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $container = new Container();
        $container->singleton(Loader::class);
        $container->singleton(Inflector::class);
        $container->singleton(Config::class);
        $container->singleton(EventManager::class);
        $container->singleton(TypeParser::class);
        $container->singleton(ConnectionManager::class);
        $container->singleton(SchemaRegistry::class);
        $container->singleton(CommandRunner::class);
        $container->singleton(Make::class);
        $container->singleton(EntityLocator::class);
        $container->singleton(ModelRegistry::class);

        $this->container = $container;

        $tmpDir = Path::join(ROOT, 'tmp');

        $container->use(Loader::class)->addNamespaces([
            'Example\\' => $tmpDir,
            'Fyre\Commands\\' => Path::join(ROOT, 'src/Commands'),
        ]);
        $container->use(EntityLocator::class)->addNamespace('Example\Entities');
        $container->use(ModelRegistry::class)->addNamespace('Example\Models');

        $input = fopen('php://memory', 'r+b');
        $output = fopen('php://memory', 'r+b');
        $error = fopen('php://memory', 'r+b');

        $this->assertIsResource($input);
        $this->assertIsResource($output);
        $this->assertIsResource($error);

        $this->input = $input;
        $this->output = $output;
        $this->error = $error;

        $container->instance(Console::class, new Console($input, $output, $error));

        $this->commandRunner = $container->use(CommandRunner::class);
        $this->commandRunner->addNamespace('Fyre\Commands');

        @mkdir('tmp');
    }

    #[Override]
    protected function tearDown(): void
    {
        @unlink('tmp/Entities/Example.php');
        @rmdir('tmp/Entities');
        @rmdir('tmp');

        fclose($this->input);
        fclose($this->output);
        fclose($this->error);
    }
}
