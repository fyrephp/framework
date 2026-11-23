<?php
declare(strict_types=1);

namespace Fyre\Auth;

use Closure;
use Fyre\Core\Config;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\DB\Expressions\ConditionExpression;
use Fyre\ORM\Entity;
use Fyre\ORM\Model;
use Fyre\ORM\ModelRegistry;
use Fyre\ORM\Queries\SelectQuery;

use function array_replace;
use function password_hash;
use function password_needs_rehash;
use function password_verify;

use const PASSWORD_DEFAULT;

/**
 * Identifies users using configured fields and verifies passwords.
 *
 * Note: If a stored password hash needs rehashing, it is upgraded and persisted as part of a successful attempt.
 */
class Identifier
{
    use DebugTrait;
    use MacroTrait;

    /**
     * @var array<string, mixed>
     */
    protected static array $defaults = [
        'identifierFields' => ['email'],
        'passwordField' => 'password',
        'modelAlias' => 'Users',
        'queryCallback' => null,
    ];

    /**
     * @var string[]
     */
    protected array $identifierFields;

    protected Model $model;

    protected string $passwordField;

    /**
     * @var (Closure(SelectQuery): SelectQuery)|null
     */
    protected Closure|null $queryCallback;

    /**
     * Constructs an Identifier.
     *
     * @param Config $config The Config.
     * @param ModelRegistry $modelRegistry The ModelRegistry.
     */
    public function __construct(Config $config, ModelRegistry $modelRegistry)
    {
        $options = array_replace(static::$defaults, $config->get('Auth.identifier', []));

        $this->model = $modelRegistry->use($options['modelAlias']);

        $this->identifierFields = (array) $options['identifierFields'];
        $this->passwordField = $options['passwordField'];
        $this->queryCallback = $options['queryCallback'];
    }

    /**
     * Attempts to identify a user.
     *
     * Note: If the stored password hash needs rehashing, it is upgraded and persisted before returning the user.
     *
     * @param string $identifier The user identifier.
     * @param string $password The user password.
     * @return Entity|null The Entity instance for the identified user or null if identification fails.
     */
    public function attempt(string $identifier, string $password): Entity|null
    {
        if (!$identifier || !$password) {
            return null;
        }

        $user = $this->identify($identifier);

        if (!$user) {
            return null;
        }

        $passwordField = $this->getPasswordField();
        $passwordHash = $user->get($passwordField);

        if (!password_verify($password, $passwordHash)) {
            return null;
        }

        if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $user->set($passwordField, $passwordHash);

            $Model = $this->getModel();

            $primaryValues = $Model->getPrimaryKey() |> $user->extract(...);

            $Model->updateAll([
                $passwordField => $passwordHash,
            ], $primaryValues);
        }

        return $user;
    }

    /**
     * Returns the user identifier fields.
     *
     * @return string[] The user identifier fields.
     */
    public function getIdentifierFields(): array
    {
        return $this->identifierFields;
    }

    /**
     * Returns the identity Model.
     *
     * @return Model The Model instance.
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Returns the user password field.
     *
     * @return string The user password field.
     */
    public function getPasswordField(): string
    {
        return $this->passwordField;
    }

    /**
     * Finds an identity by identifier.
     *
     * Note: The identifier is matched against the configured identifier fields using an `or` condition,
     * and the query may be customised using the configured query callback.
     *
     * @param string $identifier The identifier.
     * @return Entity|null The Entity instance for the identifier or null if none is found.
     */
    public function identify(string $identifier): Entity|null
    {
        return $this->identifyBy($this->identifierFields, $identifier);
    }

    /**
     * Finds the first identity matching the value in any of the given fields.
     *
     * @param string[] $fields The identity model fields to match.
     * @param mixed $value The value to match.
     * @return Entity|null The matching identity after applying the configured query callback.
     */
    public function identifyBy(array $fields, mixed $value): Entity|null
    {
        if ($fields === []) {
            return null;
        }

        $Model = $this->getModel();

        $conditions = new ConditionExpression('OR');
        foreach ($fields as $field) {
            $conditions->eq($Model->aliasField($field), $value);
        }

        $query = $Model->find()->where($conditions);

        if ($this->queryCallback) {
            $query = ($this->queryCallback)($query);
        }

        return $query->first();
    }
}
