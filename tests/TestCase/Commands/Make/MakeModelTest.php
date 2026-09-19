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
use Fyre\Core\Make\ModelSourceBuilder;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Handlers\Sqlite\SqliteConnection;
use Fyre\DB\Schema\Column;
use Fyre\DB\Schema\Index;
use Fyre\DB\Schema\Schema;
use Fyre\DB\Schema\SchemaRegistry;
use Fyre\DB\TypeParser;
use Fyre\Event\EventManager;
use Fyre\ORM\EntityLocator;
use Fyre\ORM\ModelRegistry;
use Fyre\TestSuite\Fixture\FixtureRegistry;
use Fyre\Utility\Inflector;
use Fyre\Utility\Path;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function class_exists;
use function dirname;
use function fclose;
use function file_put_contents;
use function fopen;
use function glob;
use function implode;
use function mkdir;
use function rewind;
use function rmdir;
use function stream_get_contents;
use function unlink;

use const ROOT;

final class MakeModelTest extends TestCase
{
    protected CommandRunner $commandRunner;

    protected Container $container;

    protected Connection $db;

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

    protected Schema $schema;

    /**
     * @return array<string, array{string, string, string[]}>
     */
    public static function existingRelatedFileProvider(): array
    {
        return [
            'entity' => [
                'tmp/Entities/Example.php',
                "\033[0;31mEntity file already exists.\033[0m".PHP_EOL,
                [
                    'tmp/Models/ExampleModel.php',
                    'tmp/Fixtures/ExampleFixture.php',
                    'tmp/TestCase/ExampleModelTest.php',
                ],
            ],
            'fixture' => [
                'tmp/Fixtures/ExampleFixture.php',
                "\033[0;31mFixture file already exists.\033[0m".PHP_EOL,
                [
                    'tmp/Models/ExampleModel.php',
                    'tmp/Entities/Example.php',
                    'tmp/TestCase/ExampleModelTest.php',
                ],
            ],
            'test' => [
                'tmp/TestCase/ExampleModelTest.php',
                "\033[0;31mTest file already exists.\033[0m".PHP_EOL,
                [
                    'tmp/Models/ExampleModel.php',
                    'tmp/Entities/Example.php',
                    'tmp/Fixtures/ExampleFixture.php',
                ],
            ],
        ];
    }

    public function testInferRelationshipsAliasCollision(): void
    {
        $builder = $this->container->use(ModelSourceBuilder::class);
        $relationships = $builder->inferRelationships($this->schema->table('users'), 'Users');

        $this->assertArraysAreIdentical(
            [[
                'type' => ModelSourceBuilder::HAS_MANY,
                'alias' => 'Posts',
                'targetModel' => 'PostsModel',
                'foreignKey' => ['user_id'],
                'bindingKey' => ['id'],
                'nullable' => false,
                'options' => [],
            ]],
            $relationships
        );

        $this->assertSame(
            [
                'Cannot infer relationship `Audits` from `audits.audits_updated_by`: alias conflicts. Define this relationship manually with a distinct alias.',
                'Cannot infer relationship `Audits` from `audits.audits_created_by`: alias conflicts. Define this relationship manually with a distinct alias.',
            ],
            $builder->getWarnings()
        );
    }

    public function testInferRelationshipsMultipleJunctionsAliasCollision(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE members_posts (
                member_id INTEGER NOT NULL,
                post_id INTEGER NOT NULL,
                PRIMARY KEY (member_id, post_id),
                FOREIGN KEY (member_id) REFERENCES members(id),
                FOREIGN KEY (post_id) REFERENCES posts(id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE saved_posts (
                member_id INTEGER NOT NULL,
                post_id INTEGER NOT NULL,
                PRIMARY KEY (member_id, post_id),
                FOREIGN KEY (member_id) REFERENCES members(id),
                FOREIGN KEY (post_id) REFERENCES posts(id)
            )
        SQL);

        $builder = $this->container->use(ModelSourceBuilder::class);
        $relationships = $builder->inferRelationships($this->schema->table('members'), 'Members');

        $this->assertSame([], $relationships);

        $this->assertSame(
            [
                'Cannot infer relationship `BlockedMembers` from `blocked_members`: alias conflicts. Define this relationship manually with a distinct alias.',
                'Cannot infer relationship `Posts` from `members_posts`: alias conflicts. Define this relationship manually with a distinct alias.',
                'Cannot infer relationship `Posts` from `saved_posts`: alias conflicts. Define this relationship manually with a distinct alias.',
            ],
            $builder->getWarnings()
        );
    }

    public function testInferRelationshipsRequiresForeignKey(): void
    {
        $relationships = $this->container->use(ModelSourceBuilder::class)->inferRelationships(
            $this->schema->table('legacy_posts'),
            'LegacyPosts'
        );

        $this->assertArraysAreIdentical([], $relationships);
    }

    public function testInferRelationshipsSelfReferentialAliasCollision(): void
    {
        $builder = $this->container->use(ModelSourceBuilder::class);

        $this->assertSame(
            [],
            $builder->inferRelationships($this->schema->table('members'), 'Members')
        );

        $this->assertSame(
            ['Cannot infer relationship `BlockedMembers` from `blocked_members`: alias conflicts. Define this relationship manually with a distinct alias.'],
            $builder->getWarnings()
        );
    }

    public function testInferRelationshipsSelfReferentialJunction(): void
    {
        $this->db->query('ALTER TABLE blocked_members RENAME TO member_blocks');

        $relationships = $this->container->use(ModelSourceBuilder::class)->inferRelationships(
            $this->schema->table('members'),
            'Members'
        );

        $this->assertArraysAreIdentical(
            [[
                'type' => ModelSourceBuilder::MANY_TO_MANY,
                'alias' => 'BlockedMembers',
                'targetModel' => 'MembersModel',
                'foreignKey' => ['member_id'],
                'bindingKey' => ['id'],
                'nullable' => false,
                'options' => [
                    'through' => 'MemberBlocks',
                    'classAlias' => 'Members',
                ],
            ]],
            $relationships
        );
    }

    public function testInferRelationshipsSourceBindingKey(): void
    {
        $this->db->query('DROP TABLE blocked_members');
        $this->db->query('ALTER TABLE members ADD code INTEGER NOT NULL');
        $this->db->query('CREATE UNIQUE INDEX members_code ON members (code)');
        $this->db->query(<<<'SQL'
            CREATE TABLE member_blocks (
                member_id INTEGER NOT NULL,
                blocked_member_id INTEGER NOT NULL,
                PRIMARY KEY (member_id, blocked_member_id),
                FOREIGN KEY (member_id) REFERENCES members(code),
                FOREIGN KEY (blocked_member_id) REFERENCES members(id)
            )
        SQL);

        $relationships = $this->container->use(ModelSourceBuilder::class)->inferRelationships(
            $this->schema->table('members'),
            'Members'
        );

        $this->assertArraysAreIdentical(
            [[
                'type' => ModelSourceBuilder::MANY_TO_MANY,
                'alias' => 'BlockedMembers',
                'targetModel' => 'MembersModel',
                'foreignKey' => ['member_id'],
                'bindingKey' => ['code'],
                'nullable' => false,
                'options' => [
                    'through' => 'MemberBlocks',
                    'bindingKey' => 'code',
                    'classAlias' => 'Members',
                ],
            ]],
            $relationships
        );
    }

    public function testInferRelationshipsTargetBindingKeyRequiresJunction(): void
    {
        $this->db->query('DROP TABLE blocked_members');
        $this->db->query('ALTER TABLE members ADD code INTEGER NOT NULL');
        $this->db->query('CREATE UNIQUE INDEX members_code ON members (code)');
        $this->db->query(<<<'SQL'
            CREATE TABLE blocked_members (
                member_id INTEGER NOT NULL,
                blocked_member_id INTEGER NOT NULL,
                PRIMARY KEY (member_id, blocked_member_id),
                FOREIGN KEY (member_id) REFERENCES members(id),
                FOREIGN KEY (blocked_member_id) REFERENCES members(code)
            )
        SQL);

        $builder = $this->container->use(ModelSourceBuilder::class);

        $this->assertSame(
            [],
            $builder->inferRelationships($this->schema->table('members'), 'Members')
        );

        $this->assertContains(
            'Cannot infer many-to-many relationship through `blocked_members`: configure the target binding key `code` on the junction relationship manually.',
            $builder->getWarnings()
        );
    }

    public function testMakeModel(): void
    {
        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:model', ['Example'])
        );

        rewind($this->output);
        $this->assertSame(
            "\033[0;32mGenerated: tmp/Models/ExampleModel.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/Entities/Example.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/Fixtures/ExampleFixture.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/TestCase/ExampleModelTest.php\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
        rewind($this->error);
        $this->assertSame('', stream_get_contents($this->error));

        $filePath = 'tmp/Models/ExampleModel.php';

        $this->assertFileExists($filePath);

        $this->assertFileMatchesFormat(
            Make::loadStub('model', [
                '{namespace}' => 'Example\Models',
                '{uses}' => implode(PHP_EOL, [
                    'use Example\Entities\Example;',
                    'use Fyre\Form\Validator;',
                    'use Fyre\ORM\Model;',
                    'use Fyre\ORM\RuleSet;',
                    'use Override;',
                ]),
                '{docblock}' => implode(PHP_EOL, [
                    '/**',
                    ' * @extends Model<Example>',
                    ' */',
                ]),
                '{attributes}'.PHP_EOL => '',
                '{class}' => 'ExampleModel',
                '{traits}'.PHP_EOL => '',
                '{properties}'.PHP_EOL => '',
                '{rules}'.PHP_EOL => '',
                '{validator}'.PHP_EOL => '',
            ]),
            $filePath
        );
        $this->assertFileExists('tmp/Entities/Example.php');
        $this->assertFileExists('tmp/Fixtures/ExampleFixture.php');
        $this->assertFileExists('tmp/TestCase/ExampleModelTest.php');
    }

    public function testMakeModelConnection(): void
    {
        $this->container->use(ConnectionManager::class)->setConfig('alternate', [
            'className' => SqliteConnection::class,
        ]);

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:model', [
                'Example',
                'connection' => 'alternate',
            ])
        );

        $this->assertFileMatchesFormat(
            Make::loadStub('model', [
                '{namespace}' => 'Example\Models',
                '{uses}' => implode(PHP_EOL, [
                    'use Example\Entities\Example;',
                    'use Fyre\Form\Validator;',
                    'use Fyre\ORM\Model;',
                    'use Fyre\ORM\RuleSet;',
                    'use Override;',
                ]),
                '{docblock}' => implode(PHP_EOL, [
                    '/**',
                    ' * @extends Model<Example>',
                    ' */',
                ]),
                '{attributes}'.PHP_EOL => '',
                '{class}' => 'ExampleModel',
                '{traits}'.PHP_EOL => '',
                '{properties}'.PHP_EOL => implode(PHP_EOL, [
                    '    protected array $connectionKeys = [',
                    '        self::WRITE => \'alternate\',',
                    '    ];',
                    '',
                    '',
                ]),
                '{rules}'.PHP_EOL => '',
                '{validator}'.PHP_EOL => '',
            ]),
            'tmp/Models/ExampleModel.php'
        );
    }

    public function testMakeModelEnum(): void
    {
        $column = $this->schema->table('posts')->column('title');
        $comment = new ReflectionProperty(Column::class, 'comment');
        $comment->setValue($column, '[enum] Draft, Published:published');

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:model', [
                'BlogPost',
                'table' => 'posts',
            ])
        );

        rewind($this->output);
        $this->assertSame(
            "\033[0;32mGenerated: tmp/Models/BlogPostModel.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/Enums/BlogPostTitle.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/Entities/BlogPost.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/Fixtures/BlogPostFixture.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/TestCase/BlogPostModelTest.php\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
        rewind($this->error);
        $this->assertSame('', stream_get_contents($this->error));

        $this->assertFileMatchesFormat(
            Make::loadStub('enum', [
                '{namespace}' => 'Example\Enums',
                '{class}' => 'BlogPostTitle',
                '{type}' => ': string',
                '{cases}' => '    case Draft = \'draft\';'.PHP_EOL.
                    '    case Published = \'published\';',
            ]),
            'tmp/Enums/BlogPostTitle.php'
        );
        $this->assertFileMatchesFormat(
            Make::loadStub('model', [
                '{namespace}' => 'Example\Models',
                '{uses}' => implode(PHP_EOL, [
                    'use Example\Entities\BlogPost;',
                    'use Example\Enums\BlogPostTitle;',
                    'use Fyre\Form\Rule;',
                    'use Fyre\Form\Validator;',
                    'use Fyre\ORM\Attributes\BelongsTo;',
                    'use Fyre\ORM\Attributes\EnumField;',
                    'use Fyre\ORM\Attributes\HasMany;',
                    'use Fyre\ORM\Attributes\ManyToMany;',
                    'use Fyre\ORM\Model;',
                    'use Fyre\ORM\Relationships\BelongsTo as BelongsToRelationship;',
                    'use Fyre\ORM\Relationships\HasMany as HasManyRelationship;',
                    'use Fyre\ORM\Relationships\ManyToMany as ManyToManyRelationship;',
                    'use Fyre\ORM\RuleSet;',
                    'use Fyre\ORM\Traits\TimestampsTrait;',
                    'use Override;',
                ]),
                '{docblock}' => implode(PHP_EOL, [
                    '/**',
                    ' * @extends Model<BlogPost>',
                    ' *',
                    ' * @property BelongsToRelationship<static, UsersModel> $Users',
                    ' * @property HasManyRelationship<static, PostsCategoriesModel> $PostsCategories',
                    ' * @property ManyToManyRelationship<static, LabelsModel> $Labels',
                    ' * @property ManyToManyRelationship<static, TagsModel> $Tags',
                    ' * @use TimestampsTrait<BlogPost>',
                    ' */',
                ]),
                '{attributes}'.PHP_EOL => implode(PHP_EOL, [
                    '#[EnumField(\'title\', BlogPostTitle::class)]',
                    '#[BelongsTo(\'Users\')]',
                    '#[HasMany(\'PostsCategories\', [',
                    '    \'foreignKey\' => \'post_id\',',
                    '])]',
                    '#[ManyToMany(\'Labels\', [',
                    '    \'through\' => \'PostsLabels\',',
                    '    \'foreignKey\' => \'post_id\',',
                    '])]',
                    '#[ManyToMany(\'Tags\', [',
                    '    \'through\' => \'PostsTags\',',
                    '    \'foreignKey\' => \'post_id\',',
                    '])]',
                    '',
                ]),
                '{class}' => 'BlogPostModel',
                '{traits}'.PHP_EOL => '    use TimestampsTrait;'.PHP_EOL.PHP_EOL,
                '{properties}'.PHP_EOL => '    protected string $table = \'posts\';'.PHP_EOL.PHP_EOL,
                '{rules}'.PHP_EOL => '        $rules->add(RuleSet::existsIn([\'user_id\'], \'Users\'));'.PHP_EOL.PHP_EOL,
                '{validator}'.PHP_EOL => implode(PHP_EOL, [
                    '        $validator->add(\'user_id\', Rule::required(), on: \'create\', name: \'required\');',
                    '        $validator->add(\'user_id\', Rule::integer(), name: \'integer\');',
                    '',
                    '        $validator->add(\'title\', Rule::required(), on: \'create\', name: \'required\');',
                    '        $validator->add(\'title\', Rule::maxLength(255), name: \'maxLength\');',
                    '        $validator->add(\'title\', Rule::in([\'draft\', \'published\']), name: \'in\');',
                    '',
                    '        $validator->add(\'created\', Rule::dateTime(), name: \'datetime\');',
                    '',
                    '        $validator->add(\'modified\', Rule::dateTime(), name: \'datetime\');',
                    '',
                    '',
                ]),
            ]),
            'tmp/Models/BlogPostModel.php'
        );
    }

    public function testMakeModelExistingFile(): void
    {
        $filePath = 'tmp/Models/ExampleModel.php';
        @mkdir('tmp/Models', 0755, true);
        file_put_contents($filePath, 'changed');

        $this->assertSame(
            Command::CODE_ERROR,
            $this->commandRunner->run('make:model', ['Example'])
        );

        rewind($this->output);
        $this->assertSame('', stream_get_contents($this->output));
        rewind($this->error);
        $this->assertSame(
            "\033[0;31mModel file already exists.\033[0m".PHP_EOL,
            stream_get_contents($this->error)
        );
        $this->assertStringEqualsFile($filePath, 'changed');
    }

    /**
     * @param string[] $absentPaths
     */
    #[DataProvider('existingRelatedFileProvider')]
    public function testMakeModelExistingRelatedFile(string $filePath, string $expectedError, array $absentPaths): void
    {
        @mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, 'changed');

        $this->assertSame(
            Command::CODE_ERROR,
            $this->commandRunner->run('make:model', ['Example'])
        );

        rewind($this->error);
        $this->assertSame(
            $expectedError,
            stream_get_contents($this->error)
        );
        $this->assertStringEqualsFile($filePath, 'changed');

        foreach ($absentPaths as $absentPath) {
            $this->assertFileDoesNotExist($absentPath);
        }
    }

    public function testMakeModelForce(): void
    {
        $filePath = 'tmp/Models/ExampleModel.php';
        @mkdir('tmp/Models', 0755, true);
        file_put_contents($filePath, 'changed');

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:model', [
                'Example',
                'force' => true,
            ])
        );

        rewind($this->output);
        $this->assertSame(
            "\033[0;32mGenerated: tmp/Models/ExampleModel.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/Entities/Example.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/Fixtures/ExampleFixture.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/TestCase/ExampleModelTest.php\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
        rewind($this->error);
        $this->assertSame('', stream_get_contents($this->error));

        $this->assertFileMatchesFormat(
            Make::loadStub('model', [
                '{namespace}' => 'Example\Models',
                '{uses}' => implode(PHP_EOL, [
                    'use Example\Entities\Example;',
                    'use Fyre\Form\Validator;',
                    'use Fyre\ORM\Model;',
                    'use Fyre\ORM\RuleSet;',
                    'use Override;',
                ]),
                '{docblock}' => implode(PHP_EOL, [
                    '/**',
                    ' * @extends Model<Example>',
                    ' */',
                ]),
                '{attributes}'.PHP_EOL => '',
                '{class}' => 'ExampleModel',
                '{traits}'.PHP_EOL => '',
                '{properties}'.PHP_EOL => '',
                '{rules}'.PHP_EOL => '',
                '{validator}'.PHP_EOL => '',
            ]),
            $filePath
        );
    }

    public function testMakeModelNamespaceNotFound(): void
    {
        $this->assertSame(
            Command::CODE_ERROR,
            $this->commandRunner->run('make:model', [
                'Example',
                'namespace' => 'Missing\Models',
            ])
        );

        rewind($this->output);
        $this->assertSame('', stream_get_contents($this->output));
        rewind($this->error);
        $this->assertSame(
            "\033[0;31mNamespace path not found.\033[0m".PHP_EOL,
            stream_get_contents($this->error)
        );
        $this->assertFileDoesNotExist('tmp/Models/ExampleModel.php');
    }

    public function testMakeModelPreflightsEnumDestination(): void
    {
        $column = $this->schema->table('posts')->column('title');
        $comment = new ReflectionProperty(Column::class, 'comment');
        $comment->setValue($column, '[enum] Draft:draft, Published:published');

        @mkdir('tmp/Enums', 0755, true);
        file_put_contents('tmp/Enums/BlogPostTitle.php', 'changed');

        $this->assertSame(
            Command::CODE_ERROR,
            $this->commandRunner->run('make:model', [
                'BlogPost',
                'table' => 'posts',
            ])
        );

        rewind($this->error);
        $this->assertSame(
            "\033[0;31mEnum file already exists.\033[0m".PHP_EOL,
            stream_get_contents($this->error)
        );
        $this->assertStringEqualsFile('tmp/Enums/BlogPostTitle.php', 'changed');
        $this->assertFileDoesNotExist('tmp/Models/BlogPostModel.php');
        $this->assertFileDoesNotExist('tmp/Entities/BlogPost.php');
        $this->assertFileDoesNotExist('tmp/Fixtures/BlogPostFixture.php');
        $this->assertFileDoesNotExist('tmp/TestCase/BlogPostModelTest.php');
    }

    public function testMakeModelRelationshipImportCollision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Import name `AuthorsModel` collides between `First\Models\AuthorsModel` and `Second\Models\AuthorsModel`.'
        );

        $builder = $this->container->use(ModelSourceBuilder::class);
        $relationships = [
            [
                'type' => ModelSourceBuilder::BELONGS_TO,
                'alias' => 'Authors',
                'targetModel' => 'AuthorsModel',
                'targetModelClass' => 'First\Models\AuthorsModel',
                'foreignKey' => ['author_id'],
                'bindingKey' => ['id'],
                'nullable' => false,
                'options' => [],
            ],
            [
                'type' => ModelSourceBuilder::BELONGS_TO,
                'alias' => 'Editors',
                'targetModel' => 'AuthorsModel',
                'targetModelClass' => 'Second\Models\AuthorsModel',
                'foreignKey' => ['editor_id'],
                'bindingKey' => ['id'],
                'nullable' => false,
                'options' => [],
            ],
        ];

        $builder->build(
            namespace: 'Example\Models',
            className: 'PostsModel',
            entityNamespace: 'Example\Entities',
            entityClass: 'Post',
            enumNamespace: 'Example\Enums',
            fields: [],
            indexes: [],
            enums: [],
            relationships: $relationships,
            connection: ConnectionManager::DEFAULT,
            table: null,
            withValidation: false,
            withRules: false
        );
    }

    public function testMakeModelRelationshipImports(): void
    {
        $this->container->use(ModelRegistry::class)->addNamespace('Tests\Mock\Models');
        $builder = $this->container->use(ModelSourceBuilder::class);
        $relationships = [[
            'type' => ModelSourceBuilder::BELONGS_TO,
            'alias' => 'Users',
            'targetModel' => 'UsersModel',
            'foreignKey' => ['user_id'],
            'bindingKey' => ['id'],
            'nullable' => false,
            'options' => [],
        ]];
        $relationships = $builder->buildRelationshipData($relationships, 'Example\Models');
        $arguments = [
            'className' => 'PostsModel',
            'entityNamespace' => 'Example\Entities',
            'entityClass' => 'Post',
            'enumNamespace' => 'Example\Enums',
            'fields' => [],
            'indexes' => [],
            'enums' => [],
            'relationships' => $relationships,
            'connection' => ConnectionManager::DEFAULT,
            'table' => null,
            'withValidation' => false,
            'withRules' => false,
        ];
        $external = $builder->build(...[
            'namespace' => 'Example\Models',
            ...$arguments,
        ]);
        $same = $builder->build(...[
            'namespace' => 'Tests\Mock\Models',
            ...$arguments,
        ]);
        $expected = [
            '{class}' => 'PostsModel',
            '{docblock}' => implode(PHP_EOL, [
                '/**',
                ' * @extends Model<Post>',
                ' *',
                ' * @property BelongsToRelationship<static, UsersModel> $Users',
                ' */',
            ]),
            '{attributes}'.PHP_EOL => '#[BelongsTo(\'Users\')]'.PHP_EOL,
            '{traits}'.PHP_EOL => '',
            '{properties}'.PHP_EOL => '',
            '{rules}'.PHP_EOL => '',
            '{validator}'.PHP_EOL => '',
        ];
        $uses = [
            'use Example\Entities\Post;',
            'use Fyre\Form\Validator;',
            'use Fyre\ORM\Attributes\BelongsTo;',
            'use Fyre\ORM\Model;',
            'use Fyre\ORM\Relationships\BelongsTo as BelongsToRelationship;',
            'use Fyre\ORM\RuleSet;',
            'use Override;',
        ];

        $this->assertSame(
            Make::loadStub('model', [
                '{namespace}' => 'Example\Models',
                '{uses}' => implode(PHP_EOL, [
                    ...$uses,
                    'use Tests\Mock\Models\UsersModel;',
                ]),
                ...$expected,
            ]),
            $external
        );
        $this->assertSame(
            Make::loadStub('model', [
                '{namespace}' => 'Tests\Mock\Models',
                '{uses}' => implode(PHP_EOL, $uses),
                ...$expected,
            ]),
            $same
        );
    }

    public function testMakeModelRoleSpecificAliases(): void
    {
        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:model', [
                'Audit',
                'table' => 'audits',
                'noFixture' => true,
                'noTest' => true,
            ])
        );

        rewind($this->output);
        $this->assertSame(
            "\033[0;32mGenerated: tmp/Models/AuditModel.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/Entities/Audit.php\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
        rewind($this->error);
        $this->assertSame('', stream_get_contents($this->error));
    }

    public function testMakeModelRulesOrder(): void
    {
        $index = $this->createStub(Index::class);
        $index->method('isUnique')->willReturn(true);
        $index->method('isPrimary')->willReturn(false);
        $index->method('getColumns')->willReturn(['email']);

        $source = $this->container->use(ModelSourceBuilder::class)->build(
            namespace: 'Example\Models',
            className: 'PostsModel',
            entityNamespace: 'Example\Entities',
            entityClass: 'Post',
            enumNamespace: 'Example\Enums',
            fields: [],
            indexes: [$index],
            enums: [],
            relationships: [[
                'type' => ModelSourceBuilder::BELONGS_TO,
                'alias' => 'Users',
                'targetModel' => 'UsersModel',
                'targetModelClass' => 'Example\Models\UsersModel',
                'foreignKey' => ['user_id'],
                'bindingKey' => ['id'],
                'nullable' => false,
                'options' => [],
            ]],
            connection: ConnectionManager::DEFAULT,
            table: null,
            withValidation: false,
            withRules: true
        );

        $this->assertSame(
            Make::loadStub('model', [
                '{namespace}' => 'Example\Models',
                '{uses}' => implode(PHP_EOL, [
                    'use Example\Entities\Post;',
                    'use Fyre\Form\Validator;',
                    'use Fyre\ORM\Attributes\BelongsTo;',
                    'use Fyre\ORM\Model;',
                    'use Fyre\ORM\Relationships\BelongsTo as BelongsToRelationship;',
                    'use Fyre\ORM\RuleSet;',
                    'use Override;',
                ]),
                '{docblock}' => implode(PHP_EOL, [
                    '/**',
                    ' * @extends Model<Post>',
                    ' *',
                    ' * @property BelongsToRelationship<static, UsersModel> $Users',
                    ' */',
                ]),
                '{attributes}'.PHP_EOL => '#[BelongsTo(\'Users\')]'.PHP_EOL,
                '{class}' => 'PostsModel',
                '{traits}'.PHP_EOL => '',
                '{properties}'.PHP_EOL => '',
                '{rules}'.PHP_EOL => implode(PHP_EOL, [
                    '        $rules->add(RuleSet::existsIn([\'user_id\'], \'Users\'));',
                    '',
                    '        $rules->add(RuleSet::isUnique([\'email\']));',
                    '',
                    '',
                ]),
                '{validator}'.PHP_EOL => '',
            ]),
            $source
        );
    }

    public function testMakeModelRulesUseIndexes(): void
    {
        $index = $this->createStub(Index::class);
        $index->method('isUnique')->willReturn(true);
        $index->method('isPrimary')->willReturn(false);
        $index->method('getColumns')->willReturn(['email']);

        $source = $this->container->use(ModelSourceBuilder::class)->build(
            namespace: 'Example\Models',
            className: 'UsersModel',
            entityNamespace: 'Example\Entities',
            entityClass: 'User',
            enumNamespace: 'Example\Enums',
            fields: [],
            indexes: [$index],
            enums: [],
            relationships: [],
            connection: ConnectionManager::DEFAULT,
            table: null,
            withValidation: false,
            withRules: true
        );

        $this->assertSame(
            Make::loadStub('model', [
                '{namespace}' => 'Example\Models',
                '{uses}' => implode(PHP_EOL, [
                    'use Example\Entities\User;',
                    'use Fyre\Form\Validator;',
                    'use Fyre\ORM\Model;',
                    'use Fyre\ORM\RuleSet;',
                    'use Override;',
                ]),
                '{docblock}' => implode(PHP_EOL, [
                    '/**',
                    ' * @extends Model<User>',
                    ' */',
                ]),
                '{attributes}'.PHP_EOL => '',
                '{class}' => 'UsersModel',
                '{traits}'.PHP_EOL => '',
                '{properties}'.PHP_EOL => '',
                '{rules}'.PHP_EOL => implode(PHP_EOL, [
                    '        $rules->add(RuleSet::isUnique([\'email\']));',
                    '',
                    '',
                ]),
                '{validator}'.PHP_EOL => '',
            ]),
            $source
        );
    }

    public function testMakeModelSchema(): void
    {
        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:model', [
                'BlogPost',
                'table' => 'posts',
            ])
        );

        rewind($this->output);
        $this->assertSame(
            "\033[0;32mGenerated: tmp/Models/BlogPostModel.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/Entities/BlogPost.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/Fixtures/BlogPostFixture.php\033[0m".PHP_EOL.
            "\033[0;32mGenerated: tmp/TestCase/BlogPostModelTest.php\033[0m".PHP_EOL,
            stream_get_contents($this->output)
        );
        rewind($this->error);
        $this->assertSame('', stream_get_contents($this->error));

        $this->assertFileMatchesFormat(
            Make::loadStub('fixture', [
                '{namespace}' => 'Example\Fixtures',
                '{class}' => 'BlogPostFixture',
                '{data}' => '        //',
            ]),
            'tmp/Fixtures/BlogPostFixture.php'
        );
        $this->assertFileMatchesFormat(
            Make::loadStub('test', [
                '{namespace}' => 'Tests\TestCase',
                '{class}' => 'BlogPostModelTest',
                '{body}' => implode(PHP_EOL, [
                    '    protected array $fixtures = [',
                    '        \'BlogPost\',',
                    '    ];',
                ]),
            ]),
            'tmp/TestCase/BlogPostModelTest.php'
        );

        $loader = $this->container->use(Loader::class)->register();

        try {
            $this->assertTrue(class_exists('Example\Entities\BlogPost'));
            $this->assertTrue(class_exists('Example\Models\BlogPostModel'));
        } finally {
            $loader->unregister();
        }

        $model = $this->container->use(ModelRegistry::class)->use('BlogPost');

        $this->assertSame(
            'posts',
            $model->getTable()
        );

        $this->assertTrue(
            $model->hasRelationship('Users')
        );

        $this->assertTrue(
            $model->hasRelationship('Tags')
        );

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:entity', [
                'User',
                'table' => 'users',
            ])
        );
    }

    public function testMakeModelSelfReferentialJunction(): void
    {
        $this->db->query('ALTER TABLE blocked_members RENAME TO member_blocks');

        $this->assertSame(
            Command::CODE_SUCCESS,
            $this->commandRunner->run('make:model', [
                'Members',
                'noEntity' => true,
                'noFields' => true,
                'noFixture' => true,
                'noTest' => true,
            ])
        );

        $this->assertFileMatchesFormat(
            Make::loadStub('model', [
                '{namespace}' => 'Example\Models',
                '{uses}' => implode(PHP_EOL, [
                    'use Example\Entities\Member;',
                    'use Fyre\Form\Validator;',
                    'use Fyre\ORM\Attributes\ManyToMany;',
                    'use Fyre\ORM\Model;',
                    'use Fyre\ORM\Relationships\ManyToMany as ManyToManyRelationship;',
                    'use Fyre\ORM\RuleSet;',
                    'use Override;',
                ]),
                '{docblock}' => implode(PHP_EOL, [
                    '/**',
                    ' * @extends Model<Member>',
                    ' *',
                    ' * @property ManyToManyRelationship<static, MembersModel> $BlockedMembers',
                    ' */',
                ]),
                '{attributes}'.PHP_EOL => implode(PHP_EOL, [
                    '#[ManyToMany(\'BlockedMembers\', [',
                    '    \'through\' => \'MemberBlocks\',',
                    '    \'classAlias\' => \'Members\',',
                    '])]',
                    '',
                ]),
                '{class}' => 'MembersModel',
                '{traits}'.PHP_EOL => '',
                '{properties}'.PHP_EOL => '',
                '{rules}'.PHP_EOL => '',
                '{validator}'.PHP_EOL => '',
            ]),
            'tmp/Models/MembersModel.php'
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
        $container->singleton(FixtureRegistry::class);
        $container->singleton(ModelRegistry::class);
        $container->use(Config::class)->set('Database', [
            'default' => [
                'className' => SqliteConnection::class,
            ],
        ]);

        $this->container = $container;

        $tmpDir = Path::join(ROOT, 'tmp');

        $container->use(Loader::class)->addNamespaces([
            'Example\\' => $tmpDir,
            'Fyre\Commands\\' => Path::join(ROOT, 'src/Commands'),
            'Tests\\' => $tmpDir,
        ]);
        $container->use(EntityLocator::class)->addNamespace('Example\Entities');
        $container->use(FixtureRegistry::class)->addNamespace('Example\Fixtures');
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

        $this->db = $container->use(ConnectionManager::class)->use();
        $this->schema = $container->use(SchemaRegistry::class)->use($this->db);

        $this->db->query('DROP TABLE IF EXISTS blocked_members');
        $this->db->query('DROP TABLE IF EXISTS members');
        $this->db->query('DROP TABLE IF EXISTS posts_labels');
        $this->db->query('DROP TABLE IF EXISTS labels');
        $this->db->query('DROP TABLE IF EXISTS posts_categories');
        $this->db->query('DROP TABLE IF EXISTS categories');
        $this->db->query('DROP TABLE IF EXISTS posts_tags');
        $this->db->query('DROP TABLE IF EXISTS tags');
        $this->db->query('DROP TABLE IF EXISTS legacy_posts');
        $this->db->query('DROP TABLE IF EXISTS posts');
        $this->db->query('DROP TABLE IF EXISTS audits');
        $this->db->query('DROP TABLE IF EXISTS users');

        $this->db->query(<<<'SQL'
            CREATE TABLE members (
                id INTEGER NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE blocked_members (
                id INTEGER NOT NULL,
                member_id INTEGER NOT NULL,
                blocked_member_id INTEGER NOT NULL,
                created DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (member_id) REFERENCES members(id),
                FOREIGN KEY (blocked_member_id) REFERENCES members(id)
            )
        SQL);
        $this->db->query('CREATE UNIQUE INDEX blocked_members_pair ON blocked_members (member_id, blocked_member_id)');
        $this->db->query(<<<'SQL'
            CREATE TABLE users (
                id INTEGER NOT NULL,
                email VARCHAR(255) NOT NULL,
                password VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE posts (
                id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                title VARCHAR(255) NOT NULL,
                created DATETIME NULL DEFAULT NULL,
                modified DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE legacy_posts (
                id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE tags (
                id INTEGER NOT NULL,
                tag VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE posts_tags (
                post_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (post_id, tag_id),
                FOREIGN KEY (post_id) REFERENCES posts(id),
                FOREIGN KEY (tag_id) REFERENCES tags(id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE categories (
                id INTEGER NOT NULL,
                category VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE posts_categories (
                post_id INTEGER NOT NULL,
                category_id INTEGER NOT NULL,
                FOREIGN KEY (post_id) REFERENCES posts(id),
                FOREIGN KEY (category_id) REFERENCES categories(id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE labels (
                id INTEGER NOT NULL,
                label VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE posts_labels (
                post_id INTEGER NOT NULL,
                label_id INTEGER NOT NULL,
                note VARCHAR(255) NOT NULL,
                PRIMARY KEY (post_id, label_id),
                FOREIGN KEY (post_id) REFERENCES posts(id),
                FOREIGN KEY (label_id) REFERENCES labels(id)
            )
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE audits (
                id INTEGER NOT NULL,
                created_by INTEGER NOT NULL,
                updated_by INTEGER NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (created_by) REFERENCES users(id),
                FOREIGN KEY (updated_by) REFERENCES users(id)
            )
        SQL);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS member_blocks');
        $this->db->query('DROP TABLE IF EXISTS saved_posts');
        $this->db->query('DROP TABLE IF EXISTS members_posts');
        $this->db->query('DROP TABLE IF EXISTS blocked_members');
        $this->db->query('DROP TABLE IF EXISTS members');
        $this->db->query('DROP TABLE IF EXISTS posts_labels');
        $this->db->query('DROP TABLE IF EXISTS labels');
        $this->db->query('DROP TABLE IF EXISTS posts_categories');
        $this->db->query('DROP TABLE IF EXISTS categories');
        $this->db->query('DROP TABLE IF EXISTS posts_tags');
        $this->db->query('DROP TABLE IF EXISTS tags');
        $this->db->query('DROP TABLE IF EXISTS legacy_posts');
        $this->db->query('DROP TABLE IF EXISTS posts');
        $this->db->query('DROP TABLE IF EXISTS audits');
        $this->db->query('DROP TABLE IF EXISTS users');

        $this->db->disconnect();

        $connectionManager = $this->container->use(ConnectionManager::class);

        if ($connectionManager->isLoaded('alternate')) {
            $connectionManager->use('alternate')->disconnect();
        }

        foreach (glob('tmp/*/.fyre-*') ?: [] as $filePath) {
            @unlink($filePath);
        }

        @unlink('tmp/Enums/BlogPostTitle.php');
        @unlink('tmp/Entities/Audit.php');
        @unlink('tmp/Entities/BlogPost.php');
        @unlink('tmp/Entities/Example.php');
        @unlink('tmp/Entities/User.php');
        @unlink('tmp/Fixtures/BlogPostFixture.php');
        @unlink('tmp/Fixtures/ExampleFixture.php');
        @unlink('tmp/Models/AuditModel.php');
        @unlink('tmp/Models/BlogPostModel.php');
        @unlink('tmp/Models/ExampleModel.php');
        @unlink('tmp/Models/MembersModel.php');
        @unlink('tmp/Models/UserModel.php');
        @unlink('tmp/TestCase/BlogPostModelTest.php');
        @unlink('tmp/TestCase/ExampleModelTest.php');

        @rmdir('tmp/Enums');
        @rmdir('tmp/Entities');
        @rmdir('tmp/Fixtures');
        @rmdir('tmp/Models');
        @rmdir('tmp/TestCase');
        @rmdir('tmp');

        fclose($this->input);
        fclose($this->output);
        fclose($this->error);
    }
}
