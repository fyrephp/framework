<?php
declare(strict_types=1);

namespace Tests\TestCase\ORM\Shared\Model;

use Fyre\ORM\Entity;
use Fyre\ORM\Queries\SelectQuery;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Mock\Entities\Item;
use Tests\Mock\Entities\Post;
use Tests\Mock\Entities\Tag;

use function array_map;

trait ManyToManyTestTrait
{
    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function manyToManyFindProvider(): array
    {
        return [
            'find' => [['Tags']],
            'strategy cte' => [
                [
                    'Tags' => ['strategy' => 'cte'],
                ],
            ],
            'strategy subquery' => [
                [
                    'Tags' => ['strategy' => 'subquery'],
                ],
            ],
        ];
    }

    public function testManyToManyAppend(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $Posts->Tags->setSaveStrategy('append');

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $Posts->patchEntity($post, [
            'tags' => [
                [
                    'tag' => 'test2',
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $this->assertArraysAreIdentical(
            [[1, 1], [1, 2]],
            $this->modelRegistry->use('PostsTags')
                ->find()
                ->all()
                ->map(static fn(Entity $item): array => [$item->post_id, $item->tag_id])
                ->toArray()
        );
    }

    public function testManyToManyAppendMany(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $Posts->Tags->setSaveStrategy('append');

        $posts = $Posts->newEntities([
            [
                'user_id' => 1,
                'title' => 'Test 1',
                'content' => 'This is the content.',
                'tags' => [
                    [
                        'tag' => 'test1',
                    ],
                ],
            ],
            [
                'user_id' => 1,
                'title' => 'Test 2',
                'content' => 'This is the content.',
                'tags' => [
                    [
                        'tag' => 'test3',
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->saveMany($posts)
        );

        $Posts->patchEntities($posts, [
            [
                'tags' => [
                    [
                        'tag' => 'test2',
                    ],
                ],
            ],
            [
                'tags' => [
                    [
                        'tag' => 'test4',
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->saveMany($posts)
        );

        $this->assertArraysAreIdentical(
            [[1, 1], [2, 2], [1, 3], [2, 4]],
            $this->modelRegistry->use('PostsTags')
                ->find()
                ->all()
                ->map(static fn(Entity $item): array => [$item->post_id, $item->tag_id])
                ->toArray()
        );
    }

    public function testManyToManyDelete(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                ],
                [
                    'tag' => 'test2',
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $this->assertTrue(
            $Posts->delete($post)
        );

        $this->assertSame(
            0,
            $Posts->find()->count()
        );

        $this->assertSame(
            0,
            $this->modelRegistry->use('PostsTags')->find()->count()
        );

        $this->assertSame(
            2,
            $this->modelRegistry->use('Tags')->find()->count()
        );
    }

    public function testManyToManyDeleteMany(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $posts = $Posts->newEntities([
            [
                'user_id' => 1,
                'title' => 'Test 1',
                'content' => 'This is the content.',
                'tags' => [
                    [
                        'tag' => 'test1',
                    ],
                    [
                        'tag' => 'test2',
                    ],
                ],
            ],
            [
                'user_id' => 1,
                'title' => 'Test 2',
                'content' => 'This is the content.',
                'tags' => [
                    [
                        'tag' => 'test3',
                    ],
                    [
                        'tag' => 'test4',
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->saveMany($posts)
        );

        $this->assertTrue(
            $Posts->deleteMany($posts)
        );

        $this->assertSame(
            0,
            $Posts->find()->count()
        );

        $this->assertSame(
            0,
            $this->modelRegistry->use('PostsTags')->find()->count()
        );

        $this->assertSame(
            4,
            $this->modelRegistry->use('Tags')->find()->count()
        );
    }

    /**
     * @param array<array-key, mixed> $contain
     */
    #[DataProvider('manyToManyFindProvider')]
    public function testManyToManyFind(array $contain): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                ],
                [
                    'tag' => 'test2',
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $post = $Posts->get(1, contain: $contain);

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $this->assertSame(
            1,
            $post->id
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Tag $tag): int|null => $tag->id,
                $post->tags
            )
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Tag $tag): int|null => $tag->_joinData->id,
                $post->tags
            )
        );

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $this->assertInstanceOf(
            Tag::class,
            $post->tags[0]
        );

        $this->assertInstanceOf(
            Tag::class,
            $post->tags[1]
        );

        $this->assertInstanceOf(
            Entity::class,
            $post->tags[0]->_joinData
        );

        $this->assertInstanceOf(
            Entity::class,
            $post->tags[1]->_joinData
        );

        $this->assertFalse(
            $post->isNew()
        );

        $this->assertFalse(
            $post->tags[0]->isNew()
        );

        $this->assertFalse(
            $post->tags[1]->isNew()
        );

        $this->assertFalse(
            $post->tags[0]->_joinData->isNew()
        );

        $this->assertFalse(
            $post->tags[1]->_joinData->isNew()
        );
    }

    public function testManyToManyFindCallback(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                ],
                [
                    'tag' => 'test2',
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $post = $Posts->get(1, contain: [
            'Tags' => [
                'callback' => static fn(SelectQuery $query): SelectQuery => $query->where(['Tags.id' => 2]),
            ],
        ]);

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $this->assertSame(
            1,
            $post->id
        );

        $this->assertArraysAreIdentical(
            [2],
            array_map(
                static fn(Tag $tag): int|null => $tag->id,
                $post->tags
            )
        );

        $this->assertArraysAreIdentical(
            [2],
            array_map(
                static fn(Tag $tag): int|null => $tag->_joinData->id,
                $post->tags
            )
        );

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $this->assertInstanceOf(
            Tag::class,
            $post->tags[0]
        );

        $this->assertInstanceOf(
            Entity::class,
            $post->tags[0]->_joinData
        );
        $this->assertFalse(
            $post->isNew()
        );

        $this->assertFalse(
            $post->tags[0]->isNew()
        );

        $this->assertFalse(
            $post->tags[0]->_joinData->isNew()
        );
    }

    public function testManyToManyFindGroupLimit(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $posts = $Posts->newEntities([
            [
                'title' => 'Test 1',
                'tags' => [
                    [
                        'tag' => 'test1',
                    ],
                    [
                        'tag' => 'test2',
                    ],
                ],
            ],
            [
                'title' => 'Test 2',
                'tags' => [
                    [
                        'tag' => 'test3',
                    ],
                    [
                        'tag' => 'test4',
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->saveMany($posts)
        );

        $posts = $Posts->find(contain: [
            'Tags' => [
                'orderBy' => [
                    'Tags.id' => 'DESC',
                ],
                'limit' => 1,
                'offset' => 1,
            ],
        ])
            ->orderBy('Posts.id')
            ->toArray();

        $this->assertArraysAreIdentical(
            [
                [1],
                [3],
            ],
            array_map(
                static fn(Post $post): array => array_map(
                    static fn(Tag $tag): int|null => $tag->id,
                    $post->tags
                ),
                $posts
            )
        );
    }

    public function testManyToManyFindRelated(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                ],
                [
                    'tag' => 'test2',
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $post = $Posts->get(1);

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $tags = $Posts->Tags->findRelated([$post])->toArray();

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Tag $tag): int|null => $tag->id,
                $tags
            )
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Tag $tag): int|null => $tag->_joinData->id,
                $tags
            )
        );

        $this->assertInstanceOf(
            Tag::class,
            $tags[0]
        );

        $this->assertInstanceOf(
            Tag::class,
            $tags[1]
        );

        $this->assertInstanceOf(
            Entity::class,
            $tags[0]->_joinData
        );

        $this->assertInstanceOf(
            Entity::class,
            $tags[1]->_joinData
        );
    }

    public function testManyToManyFindRelatedEmpty(): void
    {
        $Posts = $this->modelRegistry->use('Posts');
        $post = $Posts->newEmptyEntity();

        $this->assertArraysAreIdentical(
            [],
            $Posts->Tags->findRelated([$post])->toArray()
        );
    }

    public function testManyToManyFindRelatedGroupLimit(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $posts = $Posts->newEntities([
            [
                'title' => 'Test 1',
                'tags' => [
                    [
                        'tag' => 'test1',
                    ],
                    [
                        'tag' => 'test2',
                    ],
                ],
            ],
            [
                'title' => 'Test 2',
                'tags' => [
                    [
                        'tag' => 'test3',
                    ],
                    [
                        'tag' => 'test4',
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->saveMany($posts)
        );

        $tags = $Posts->Tags->findRelated(
            $posts,
            orderBy: [
                'Tags.id' => 'DESC',
            ],
            limit: 1,
            offset: 1
        )
            ->indexBy('_joinData.post_id')
            ->toArray();

        $this->assertSame(
            1,
            $tags[1]->id
        );

        $this->assertSame(
            3,
            $tags[2]->id
        );
    }

    public function testManyToManyInsert(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                ],
                [
                    'tag' => 'test2',
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $this->assertSame(
            1,
            $post->id
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Tag $tag): int|null => $tag->id,
                $post->tags
            )
        );

        $this->assertFalse(
            $post->isNew()
        );

        $this->assertFalse(
            $post->tags[0]->isNew()
        );

        $this->assertFalse(
            $post->tags[1]->isNew()
        );

        $this->assertFalse(
            $post->isDirty()
        );

        $this->assertFalse(
            $post->tags[0]->isDirty()
        );

        $this->assertFalse(
            $post->tags[1]->isDirty()
        );
    }

    public function testManyToManyInsertMany(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $posts = $Posts->newEntities([
            [
                'user_id' => 1,
                'title' => 'Test 1',
                'content' => 'This is the content.',
                'tags' => [
                    [
                        'tag' => 'test1',
                    ],
                    [
                        'tag' => 'test2',
                    ],
                ],
            ],
            [
                'user_id' => 1,
                'title' => 'Test 2',
                'content' => 'This is the content.',
                'tags' => [
                    [
                        'tag' => 'test3',
                    ],
                    [
                        'tag' => 'test4',
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->saveMany($posts)
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Post $post): int|null => $post->id,
                $posts
            )
        );

        $this->assertArraysAreIdentical(
            [
                [1, 2],
                [3, 4],
            ],
            array_map(
                static fn(Post $post): array => array_map(
                    static fn(Tag $tag): int|null => $tag->id,
                    $post->tags
                ),
                $posts
            )
        );

        $this->assertFalse(
            $posts[0]->isNew()
        );

        $this->assertFalse(
            $posts[1]->isNew()
        );

        $this->assertFalse(
            $posts[0]->tags[0]->isNew()
        );

        $this->assertFalse(
            $posts[0]->tags[1]->isNew()
        );

        $this->assertFalse(
            $posts[1]->tags[0]->isNew()
        );

        $this->assertFalse(
            $posts[1]->tags[1]->isNew()
        );

        $this->assertFalse(
            $posts[0]->isDirty()
        );

        $this->assertFalse(
            $posts[1]->isDirty()
        );

        $this->assertFalse(
            $posts[0]->tags[0]->isDirty()
        );

        $this->assertFalse(
            $posts[0]->tags[1]->isDirty()
        );

        $this->assertFalse(
            $posts[1]->tags[0]->isDirty()
        );

        $this->assertFalse(
            $posts[1]->tags[1]->isDirty()
        );
    }

    public function testManyToManyInvalidSaveStrategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Relationship save strategy `invalid` is not valid.');

        $this->modelRegistry->use('Posts')->Tags->setSaveStrategy('invalid');
    }

    public function testManyToManyJoinData(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                    '_joinData' => [
                        'value' => 11,
                    ],
                ],
                [
                    'tag' => 'test2',
                    '_joinData' => [
                        'value' => 22,
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $post = $Posts->get(1, contain: [
            'Tags',
        ]);

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $this->assertSame(
            1,
            $post->id
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Tag $tag): int|null => $tag->id,
                $post->tags
            )
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Tag $tag): int|null => $tag->_joinData->id,
                $post->tags
            )
        );

        $this->assertArraysAreIdentical(
            [11, 22],
            array_map(
                static fn(Tag $tag): int|null => $tag->_joinData->value,
                $post->tags
            )
        );

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $this->assertInstanceOf(
            Tag::class,
            $post->tags[0]
        );

        $this->assertInstanceOf(
            Tag::class,
            $post->tags[1]
        );

        $this->assertInstanceOf(
            Entity::class,
            $post->tags[0]->_joinData
        );

        $this->assertInstanceOf(
            Entity::class,
            $post->tags[1]->_joinData
        );

        $this->assertFalse(
            $post->isNew()
        );

        $this->assertFalse(
            $post->tags[0]->isNew()
        );

        $this->assertFalse(
            $post->tags[1]->isNew()
        );

        $this->assertFalse(
            $post->tags[0]->_joinData->isNew()
        );

        $this->assertFalse(
            $post->tags[1]->_joinData->isNew()
        );
    }

    public function testManyToManyJunction(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $Others = $this->modelRegistry->use('Others');

        $relationship = $Items->manyToMany('Alias', [
            'classAlias' => 'Items',
        ]);

        $relationship->setJunction($Others);

        $this->assertSame(
            $Others,
            $relationship->getJunction()
        );
    }

    public function testManyToManyJunctionForeignKeyMismatch(): void
    {
        $Contains = $this->modelRegistry->use('Contains');
        $Contains->belongsTo('LinkedOthers', [
            'classAlias' => 'Others',
            'foreignKey' => 'item_id',
        ]);

        $relationship = $this->modelRegistry->use('Items')->manyToMany('LinkedOthers', [
            'classAlias' => 'Others',
            'through' => 'Contains',
            'targetForeignKey' => 'contained_item_id',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Relationship `LinkedOthers` on `Contains` is incompatible with the many-to-many relationship on `Items`.');

        $relationship->getTargetRelationship();
    }

    public function testManyToManyJunctionRelationshipTypeMismatch(): void
    {
        $Contains = $this->modelRegistry->use('Contains');
        $Contains->hasMany('LinkedOthers', [
            'classAlias' => 'Others',
            'foreignKey' => 'contained_item_id',
        ]);

        $relationship = $this->modelRegistry->use('Items')->manyToMany('LinkedOthers', [
            'classAlias' => 'Others',
            'through' => 'Contains',
            'targetForeignKey' => 'contained_item_id',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Relationship `LinkedOthers` on `Contains` is incompatible with the many-to-many relationship on `Items`.');

        $relationship->getTargetRelationship();
    }

    public function testManyToManyJunctionTargetMismatch(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $Contains = $this->modelRegistry->use('Contains');
        $Contains->belongsTo('LinkedOthers', [
            'classAlias' => 'Others',
            'foreignKey' => 'contained_item_id',
        ])->setTarget($Items);

        $relationship = $Items->manyToMany('LinkedOthers', [
            'classAlias' => 'Others',
            'through' => 'Contains',
            'targetForeignKey' => 'contained_item_id',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Relationship `LinkedOthers` on `Contains` is incompatible with the many-to-many relationship on `Items`.');

        $relationship->getTargetRelationship();
    }

    public function testManyToManyLoadRelatedEmpty(): void
    {
        $Posts = $this->modelRegistry->use('Posts');
        $post = $Posts->newEmptyEntity();

        $Posts->Tags->loadRelated([$post]);

        $this->assertArraysAreIdentical([], $post->tags);
    }

    public function testManyToManyLoadRelatedEmptyClean(): void
    {
        $Posts = $this->modelRegistry->use('Posts');
        $post = $Posts->newEmptyEntity();

        $Posts->Tags->loadRelated([$post]);

        $this->assertFalse($post->isDirty('tags'));
    }

    public function testManyToManyOnlyIds(): void
    {
        $Tags = $this->modelRegistry->use('Tags');
        $Posts = $this->modelRegistry->use('Posts');

        $tags = $Tags->newEntities([
            [
                'tag' => 'test1',
            ],
            [
                'tag' => 'test2',
            ],
        ]);

        $this->assertTrue(
            $Tags->saveMany($tags)
        );

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [1, 2],
        ], associated: [
            'Tags' => [
                'onlyIds' => true,
            ],
        ]);

        $this->assertInstanceOf(
            Tag::class,
            $post->tags[0]
        );

        $this->assertInstanceOf(
            Tag::class,
            $post->tags[1]
        );

        $this->assertFalse(
            $post->tags[0]->isNew()
        );

        $this->assertFalse(
            $post->tags[1]->isNew()
        );

        $this->assertSame(
            'test1',
            $post->tags[0]->tag
        );

        $this->assertSame(
            'test2',
            $post->tags[1]->tag
        );
    }

    public function testManyToManyOnlyIdsInvalid(): void
    {
        $Tags = $this->modelRegistry->use('Tags');
        $Posts = $this->modelRegistry->use('Posts');

        $tags = $Tags->newEntities([
            [
                'tag' => 'test1',
            ],
            [
                'tag' => 'test2',
            ],
        ]);

        $this->assertTrue(
            $Tags->saveMany($tags)
        );

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                ],
                [
                    'tag' => 'test2',
                ],
            ],
        ], associated: [
            'Tags' => [
                'onlyIds' => true,
            ],
        ]);

        $this->assertArraysAreIdentical(
            [],
            $post->tags
        );
    }

    public function testManyToManyReplace(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                ],
                [
                    'tag' => 'test2',
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $post->tags = [];

        $this->assertTrue(
            $Posts->save($post)
        );

        $this->assertSame(
            0,
            $this->modelRegistry->use('PostsTags')->find()->count()
        );

        $this->assertSame(
            2,
            $this->modelRegistry->use('Tags')->find()->count()
        );
    }

    public function testManyToManyReplaceMany(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $posts = $Posts->newEntities([
            [
                'user_id' => 1,
                'title' => 'Test 1',
                'content' => 'This is the content.',
                'tags' => [
                    [
                        'tag' => 'test1',
                    ],
                    [
                        'tag' => 'test2',
                    ],
                ],
            ],
            [
                'user_id' => 1,
                'title' => 'Test 2',
                'content' => 'This is the content.',
                'tags' => [
                    [
                        'tag' => 'test3',
                    ],
                    [
                        'tag' => 'test4',
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->saveMany($posts)
        );

        $Posts->patchEntities($posts, [
            [
                'tags' => [],
            ],
            [
                'tags' => [],
            ],
        ]);

        $this->assertTrue(
            $Posts->saveMany($posts)
        );

        $this->assertSame(
            0,
            $this->modelRegistry->use('PostsTags')->find()->count()
        );

        $this->assertSame(
            4,
            $this->modelRegistry->use('Tags')->find()->count()
        );
    }

    public function testManyToManyReplacePreservesExistingLinks(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                    '_joinData' => [
                        'value' => 11,
                    ],
                ],
                [
                    'tag' => 'test2',
                    '_joinData' => [
                        'value' => 22,
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $post = $Posts->get(1, contain: [
            'Tags',
        ]);

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $tag = $post->tags[0];
        $joinId = $tag->_joinData->id;
        $tag->_joinData->value = 33;
        $post->tags = [$tag];

        $this->assertTrue(
            $Posts->save($post)
        );

        $this->assertArraysAreIdentical(
            [[$joinId, 1, 1, 33]],
            $this->modelRegistry->use('PostsTags')
                ->find()
                ->all()
                ->map(static fn(Entity $item): array => [$item->id, $item->post_id, $item->tag_id, $item->value])
                ->toArray()
        );
    }

    public function testManyToManyReplacePreservesUnloadedLinks(): void
    {
        $Posts = $this->modelRegistry->use('Posts');
        $PostsTags = $this->modelRegistry->use('PostsTags');

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'tags' => [
                ['tag' => 'test1', '_joinData' => ['value' => 11]],
                ['tag' => 'test2', '_joinData' => ['value' => 22]],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $tagId = $post->tags[0]->id;
        $joinId = $post->tags[0]->_joinData->id;

        $this->assertNotNull(
            $post->id
        );
        $this->assertNotNull(
            $joinId
        );

        $post = $Posts->get($post->id);

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $Posts->patchEntity($post, [
            'tags' => [
                ['id' => $tagId, 'tag' => 'test1', '_joinData' => ['id' => $joinId]],
            ],
        ]);

        $this->assertTrue(
            $post->tags[0]->_joinData->isNew()
        );
        $this->assertTrue(
            $Posts->save($post)
        );
        $this->assertSame(
            1,
            $PostsTags->find()->count()
        );
        $this->assertSame(
            11,
            $PostsTags->get($joinId)?->value
        );
    }

    public function testManyToManySaveStrategyOption(): void
    {
        $relationship = $this->modelRegistry->use('Items')->manyToMany('Alias', [
            'classAlias' => 'Items',
            'saveStrategy' => 'append',
        ]);

        $this->assertSame(
            'append',
            $relationship->getSaveStrategy()
        );
    }

    public function testManyToManySelfReferentialJunction(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $Contains = $this->modelRegistry->use('Contains');

        $relationship = $Items->manyToMany('ChildItems', [
            'classAlias' => 'Items',
            'through' => 'Contains',
            'targetForeignKey' => 'contained_item_id',
        ]);

        $item = $Items->newEntity([
            'name' => 'Parent',
            'child_items' => [
                ['name' => 'Child'],
            ],
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $this->assertSame(
            $Contains,
            $relationship->getJunction()
        );

        $item = $Items->find()
            ->where(['Items.name' => 'Parent'])
            ->contain('ChildItems')
            ->first();

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            'Child',
            $item->get('child_items')[0]->name
        );

        $this->assertSame(
            $item->get('child_items')[0]->id,
            $item->get('child_items')[0]->_joinData->contained_item_id
        );
    }

    public function testManyToManySort(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $Posts->Tags->setSort([
            'Tags.tag' => 'DESC',
        ]);

        $post = $Posts->newEntity([
            'user_id' => 1,
            'title' => 'Test',
            'content' => 'This is the content.',
            'tags' => [
                [
                    'tag' => 'test1',
                ],
                [
                    'tag' => 'test2',
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $post = $Posts->get(1, contain: [
            'Tags',
        ]);

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $this->assertSame(
            1,
            $post->id
        );

        $this->assertArraysAreIdentical(
            [2, 1],
            array_map(
                static fn(Tag $tag): int|null => $tag->id,
                $post->tags
            )
        );

        $this->assertArraysAreIdentical(
            [2, 1],
            array_map(
                static fn(Tag $tag): int|null => $tag->_joinData->id,
                $post->tags
            )
        );

        $this->assertInstanceOf(
            Post::class,
            $post
        );

        $this->assertInstanceOf(
            Tag::class,
            $post->tags[0]
        );

        $this->assertInstanceOf(
            Tag::class,
            $post->tags[1]
        );

        $this->assertInstanceOf(
            Entity::class,
            $post->tags[0]->_joinData
        );

        $this->assertInstanceOf(
            Entity::class,
            $post->tags[1]->_joinData
        );

        $this->assertFalse(
            $post->isNew()
        );

        $this->assertFalse(
            $post->tags[0]->isNew()
        );

        $this->assertFalse(
            $post->tags[1]->isNew()
        );

        $this->assertFalse(
            $post->tags[0]->_joinData->isNew()
        );

        $this->assertFalse(
            $post->tags[1]->_joinData->isNew()
        );
    }

    public function testManyToManySortOption(): void
    {
        $relationship = $this->modelRegistry->use('Items')->manyToMany('Alias', [
            'classAlias' => 'Items',
            'sort' => 'Alias.name',
        ]);

        $this->assertSame(
            'Alias.name',
            $relationship->getSort()
        );
    }

    public function testManyToManyTargetBindingKey(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $Contains = $this->modelRegistry->use('Contains');

        $targetRelationship = $Contains->belongsTo('LinkedOthers', [
            'classAlias' => 'Others',
            'foreignKey' => 'contained_item_id',
            'bindingKey' => 'value',
        ]);

        $relationship = $Items->manyToMany('LinkedOthers', [
            'classAlias' => 'Others',
            'through' => 'Contains',
            'foreignKey' => 'item_id',
            'targetForeignKey' => 'contained_item_id',
        ]);

        $item = $Items->newEntity([
            'name' => 'Test',
            'linked_others' => [
                ['value' => 42],
            ],
        ]);

        $this->assertTrue(
            $Items->save($item)
        );

        $this->assertSame(
            $Contains,
            $relationship->getJunction()
        );

        $this->assertSame(
            $targetRelationship,
            $relationship->getTargetRelationship()
        );

        $item = $Items->find()->contain('LinkedOthers')->first();

        $this->assertInstanceOf(
            Item::class,
            $item
        );

        $this->assertSame(
            42,
            $item->get('linked_others')[0]->value
        );

        $this->assertSame(
            42,
            $item->get('linked_others')[0]->_joinData->contained_item_id
        );
    }
}
