<?php
declare(strict_types=1);

namespace Tests\TestCase\Auth;

use Closure;
use Fyre\Auth\PolicyRegistry;
use Fyre\Http\Exceptions\ForbiddenException;
use Fyre\ORM\ModelRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Mock\Entities\Post;
use Tests\Mock\Entities\User;
use Tests\Mock\Models\PostsModel;

final class PolicyTest extends TestCase
{
    use ConnectionTrait;

    /**
     * @return array<string, array{Closure(ModelRegistry): (PostsModel|string)}>
     */
    public static function policyCreateProvider(): array
    {
        return [
            'alias' => [static fn(ModelRegistry $modelRegistry): string => 'Posts'],
            'class name' => [static fn(ModelRegistry $modelRegistry): string => PostsModel::class],
            'model' => [static fn(ModelRegistry $modelRegistry): PostsModel => $modelRegistry->use('Posts')],
        ];
    }

    /**
     * @return array<string, array{Closure(ModelRegistry): array{Post|PostsModel|string|null, 1?: int}}>
     */
    public static function policyUpdateProvider(): array
    {
        return [
            'alias' => [static fn(ModelRegistry $modelRegistry): array => ['Posts', 1]],
            'class name' => [static fn(ModelRegistry $modelRegistry): array => [PostsModel::class, 1]],
            'entity' => [static fn(ModelRegistry $modelRegistry): array => [$modelRegistry->use('Posts')->get(1)]],
            'model' => [static fn(ModelRegistry $modelRegistry): array => [$modelRegistry->use('Posts'), 1]],
        ];
    }

    /**
     * @param Closure(ModelRegistry): (PostsModel|string) $resourceFactory
     */
    #[DataProvider('policyCreateProvider')]
    public function testPolicyCreate(Closure $resourceFactory): void
    {
        $this->login();

        $resource = $resourceFactory($this->modelRegistry);

        $this->access->authorize('create', $resource);
    }

    /**
     * @param Closure(ModelRegistry): (PostsModel|string) $resourceFactory
     */
    #[DataProvider('policyCreateProvider')]
    public function testPolicyCreateFail(Closure $resourceFactory): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessageIs('Forbidden');

        $resource = $resourceFactory($this->modelRegistry);

        $this->access->authorize('create', $resource);
    }

    public function testPolicyCreateRequiresUser(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessageIs('Forbidden');

        $policy = new class ()
        {
            public function create(User $user): bool
            {
                return true;
            }
        };
        $this->container->use(PolicyRegistry::class)->map('Posts', $policy::class);

        $this->access->authorize('create', 'Posts');
    }

    /**
     * @param Closure(ModelRegistry): array{Post|PostsModel|string|null, 1?: int} $argsFactory
     */
    #[DataProvider('policyUpdateProvider')]
    public function testPolicyUpdate(Closure $argsFactory): void
    {
        $this->login();

        $args = $argsFactory($this->modelRegistry);

        $this->access->authorize('update', ...$args);
    }

    /**
     * @param Closure(ModelRegistry): array{Post|PostsModel|string|null, 1?: int} $argsFactory
     */
    #[DataProvider('policyUpdateProvider')]
    public function testPolicyUpdateFail(Closure $argsFactory): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessageIs('Forbidden');

        $args = $argsFactory($this->modelRegistry);

        $this->access->authorize('update', ...$args);
    }

    public function testPolicyUpdateRequiresEntity(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessageIs('Forbidden');

        $this->login();

        $this->access->authorize('update', 'Posts');
    }
}
