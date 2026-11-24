<?php
declare(strict_types=1);

namespace Tests\TestCase\ORM\Shared\Model;

use Fyre\DB\Connection;
use Fyre\ORM\Model;

trait ModelTestTrait
{
    use BelongsToCallbacksTestTrait;
    use BelongsToTestTrait;
    use CallbacksBelongsToTestTrait;
    use CallbacksHasManyTestTrait;
    use CallbacksHasOneTestTrait;
    use CallbacksManyToManyTestTrait;
    use CallbacksTestTrait;
    use ContainTestTrait;
    use HasManyCallbacksTestTrait;
    use HasManyTestTrait;
    use HasOneCallbacksTestTrait;
    use HasOneTestTrait;
    use JoinTestTrait;
    use LoadIntoTestTrait;
    use ManyToManyCallbacksTestTrait;
    use ManyToManyTestTrait;
    use MatchingTestTrait;
    use NewEntityTestTrait;
    use PatchEntityTestTrait;
    use QueryTestTrait;
    use RelationshipTestTrait;
    use SchemaTestTrait;
    use TransactionTestTrait;

    public function testConnection(): void
    {
        $this->assertSame(
            $this->db,
            $this->modelRegistry->use('Test')->getConnection()
        );
    }

    public function testHasRelationship(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $Items->hasOne('Alias', [
            'classAlias' => 'Items',
        ]);

        $this->assertTrue(isset($Items->Alias));
    }

    public function testRemoveRelationship(): void
    {
        $Items = $this->modelRegistry->use('Items');

        $Items->hasOne('Alias', [
            'classAlias' => 'Items',
        ]);

        $this->assertSame(
            $Items,
            $Items->removeRelationship('Alias')
        );

        $this->assertFalse(isset($Items->Alias));
    }

    public function testSetConnection(): void
    {
        $Items = $this->modelRegistry->use('Items');
        $connection = $this->createStub(Connection::class);

        $this->assertSame(
            $Items,
            $Items->setConnection($connection, Model::READ)
        );

        $this->assertSame(
            $connection,
            $Items->getConnection(Model::READ)
        );
    }
}
