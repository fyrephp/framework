<?php
declare(strict_types=1);

namespace Tests\TestCase\View\Helpers\Form\Context\Shared;

use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\Form\Validator;
use Fyre\Http\ServerRequest;
use Fyre\ORM\Model;
use Fyre\ORM\ModelRegistry;
use Fyre\View\View;
use Override;
use Tests\TestCase\Shared\DatabaseLifecycleTrait;

trait ConnectionTrait
{
    use DatabaseLifecycleTrait;

    protected const TABLES = [
        'contexts',
        'parents',
        'children',
        'contexts_children',
    ];

    protected Connection $db;

    protected Model $model;

    protected Validator $validator;

    protected View $view;

    protected static function clearSchema(Connection $db): void
    {
        foreach (static::TABLES as $table) {
            $db->query('DROP TABLE IF EXISTS '.$table);
        }
    }

    protected static function createSchema(Connection $db): void {}

    #[Override]
    protected function setUp(): void
    {
        $container = static::buildContainer();

        $this->db = $container->use(ConnectionManager::class)->use();
        $this->model = $container->use(ModelRegistry::class)->use('Contexts');
        $this->validator = $container->build(Validator::class);

        $this->model->setValidator($this->validator);

        $request = $container->build(ServerRequest::class, [
            'options' => [
                'uri' => '/test',
            ],
        ]);

        $this->view = $container->build(View::class, ['request' => $request]);
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            static::clearSchema($this->db);
        } finally {
            $this->db->disconnect();
        }
    }
}
