<?php
declare(strict_types=1);

namespace Tests\Mock\PhpStan;

use Fyre\ORM\Model;
use Tests\Mock\Entities\Item;
use Tests\Mock\Models\ItemsModel as OverrideItemsModel;
use Tests\Mock\Models\ORM\ItemsModel;

use function model;
use function model as loadModel;
use function PHPStan\Testing\assertType;

function modelFunction(): void
{
    assertType(ItemsModel::class, model('Items'));
    assertType(ItemsModel::class, loadModel('Items'));
    assertType(ItemsModel::class, model(alias: 'Items'));
    assertType(Item::class, model('Items')->newEmptyEntity());
}

function modelFunctionFallback(string $alias): void
{
    assertType(Model::class, model('Missing'));
    assertType(Model::class, model('Invalid'));
    assertType(Model::class, model($alias));
}

final class ModelFunctionOverride
{
    public function test(): void
    {
        assertType(OverrideItemsModel::class, model('Items'));
    }
}
