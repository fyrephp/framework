<?php
declare(strict_types=1);

namespace Tests\TestCase\ORM\Shared\Model;

use Fyre\DB\Expressions\ConditionExpression;
use Fyre\DB\Query;
use Fyre\ORM\Exceptions\OrmException;
use Tests\Mock\Entities\Comment;
use Tests\Mock\Entities\Post;
use Tests\Mock\Entities\Tag;
use Tests\Mock\Entities\User;

use function array_map;

trait ContainTestTrait
{
    public function testContainAutoFields(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $user = $Users->newEntity([
            'name' => 'Test',
            'posts' => [
                [
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
            ],
            'address' => [
                'suburb' => 'Test',
            ],
        ], associated: [
            'Posts.Tags',
            'Addresses',
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $user = $Users->get(
            1,
            contain: [
                'Addresses',
                'Posts' => [
                    'Tags' => [
                        'autoFields' => false,
                    ],
                    'autoFields' => false,
                ],
            ],
            autoFields: false,
        );

        $this->assertInstanceOf(
            User::class,
            $user
        );

        $this->assertArraysAreIdentical(
            [
                'id' => 1,
                'address' => [
                    'id' => 1,
                ],
                'posts' => [
                    [
                        'id' => 1,
                        'user_id' => 1,
                        'tags' => [
                            [
                                'id' => 1,
                                '_joinData' => [
                                    'id' => 1,
                                    'post_id' => 1,
                                ],
                            ],
                            [
                                'id' => 2,
                                '_joinData' => [
                                    'id' => 2,
                                    'post_id' => 1,
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 2,
                        'user_id' => 1,
                        'tags' => [
                            [
                                'id' => 3,
                                '_joinData' => [
                                    'id' => 3,
                                    'post_id' => 2,
                                ],
                            ],
                            [
                                'id' => 4,
                                '_joinData' => [
                                    'id' => 4,
                                    'post_id' => 2,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $user->toArray()
        );
    }

    public function testContainFind(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $user = $Users->newEntity([
            'name' => 'Test',
            'address' => [
                'suburb' => 'Test',
            ],
            'posts' => [
                [
                    'title' => 'Test 1',
                    'content' => 'This is the content.',
                    'comments' => [
                        [
                            'content' => 'This is a comment',
                            'user' => [
                                'name' => 'Test 2',
                            ],
                        ],
                    ],
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
                    'content' => 'This is the content.',
                    'comments' => [
                        [
                            'content' => 'This is a comment',
                            'user' => [
                                'name' => 'Test 3',
                            ],
                        ],
                    ],
                    'tags' => [
                        [
                            'tag' => 'test3',
                        ],
                        [
                            'tag' => 'test4',
                        ],
                    ],
                ],
            ],
        ], associated: [
            'Posts.Comments.Users',
            'Posts.Tags',
            'Addresses',
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $user = $Users->get(1, contain: [
            'Addresses',
            'Posts' => [
                'Comments' => [
                    'Users',
                ],
                'Tags',
            ],
        ]);

        $this->assertInstanceOf(
            User::class,
            $user
        );

        $this->assertSame(
            1,
            $user->id
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Post $post): int|null => $post->id,
                $user->posts
            )
        );

        $this->assertSame(
            1,
            $user->address->id
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
                $user->posts
            )
        );

        $this->assertArraysAreIdentical(
            [
                [1],
                [2],
            ],
            array_map(
                static fn(Post $post): array => array_map(
                    static fn(Comment $comment): int|null => $comment->id,
                    $post->comments
                ),
                $user->posts
            )
        );

        $this->assertArraysAreIdentical(
            [
                [2],
                [3],
            ],
            array_map(
                static fn(Post $post): array => array_map(
                    static fn(Comment $comment): int|null => $comment->user->id,
                    $post->comments
                ),
                $user->posts
            )
        );
    }

    public function testContainFindBelongsToMissing(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $post = $Posts->newEntity([
            'title' => 'Test',
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $post = $Posts->get($post->id, contain: ['Users']);

        $this->assertNull(
            $post->user
        );
    }

    public function testContainFindHasOneMissing(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $user = $Users->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $user = $Users->get($user->id, contain: ['Addresses']);

        $this->assertNull(
            $user->address
        );
    }

    public function testContainFindNestedBelongsToMissing(): void
    {
        $Posts = $this->modelRegistry->use('Posts');

        $post = $Posts->newEntity([
            'title' => 'Test',
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $post = $Posts->get($post->id, contain: ['Users.Addresses']);

        $this->assertNull(
            $post->user
        );
    }

    public function testContainFindOptions(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $user = $Users->newEntity([
            'name' => 'Test',
            'posts' => [
                [
                    'title' => 'Test 1',
                    'content' => 'This is the content.',
                ],
                [
                    'title' => 'Test 2',
                    'content' => 'This is the content.',
                ],
            ],
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $user = $Users->get(1, contain: [
            'Posts' => [
                'conditions' => static fn(Query $query): ConditionExpression => $query->expr()
                    ->eq('Posts.title', 'Test 2'),
                'orderBy' => [
                    'title' => 'DESC',
                ],
            ],
        ]);

        $this->assertInstanceOf(
            User::class,
            $user
        );

        $this->assertArraysAreIdentical(
            [2],
            array_map(
                static fn(Post $post): int|null => $post->id,
                $user->posts
            )
        );
    }

    public function testContainInsert(): void
    {
        $Users = $this->modelRegistry->use('Users');

        $user = $Users->newEntity([
            'name' => 'Test',
            'posts' => [
                [
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
            ],
            'address' => [
                'suburb' => 'Test',
            ],
        ], associated: [
            'Posts.Tags',
            'Addresses',
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $this->assertSame(
            1,
            $user->id
        );

        $this->assertArraysAreIdentical(
            [1, 2],
            array_map(
                static fn(Post $post): int|null => $post->id,
                $user->posts
            )
        );

        $this->assertArraysAreIdentical(
            [1, 1],
            array_map(
                static fn(Post $post): int|null => $post->user_id,
                $user->posts
            )
        );

        $this->assertSame(
            1,
            $user->address->id
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
                $user->posts
            )
        );
    }

    public function testContainInvalid(): void
    {
        $this->expectException(OrmException::class);
        $this->expectExceptionMessageIs('Model `Users` does not have a relationship to `Invalid`.');

        $this->modelRegistry->use('Users')->find(contain: [
            'Invalid',
        ]);
    }

    public function testContainMerge(): void
    {
        $Posts = $this->modelRegistry->use('Posts');
        $Users = $this->modelRegistry->use('Users');

        $user = $Users->newEntity([
            'name' => 'Test',
        ]);

        $this->assertTrue(
            $Users->save($user)
        );

        $post = $Posts->newEntity([
            'user_id' => $user->id,
            'title' => 'Test',
            'content' => 'This is the content.',
            'comments' => [
                [
                    'user_id' => $user->id,
                    'content' => 'This is a comment',
                ],
            ],
            'tags' => [
                [
                    'tag' => 'test1',
                ],
            ],
        ]);

        $this->assertTrue(
            $Posts->save($post)
        );

        $user = $Users->find(conditions: [
            'Users.id' => 1,
        ])
            ->contain([
                'Posts' => [
                    'Comments',
                ],
            ])
            ->contain([
                'Posts' => [
                    'Tags',
                ],
            ])
            ->first();

        $this->assertInstanceOf(
            User::class,
            $user
        );

        $this->assertSame(
            1,
            $user->id
        );

        $this->assertSame(
            1,
            $user->posts[0]->id
        );

        $this->assertSame(
            1,
            $user->posts[0]->comments[0]->id
        );

        $this->assertSame(
            1,
            $user->posts[0]->tags[0]->id
        );
    }
}
