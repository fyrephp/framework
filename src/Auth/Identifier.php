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

use function array_fill_keys;
use function array_replace;
use function in_array;
use function is_string;
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
     * Note: If the password changes during a rehash, the current hash is checked before returning the user.
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

        if (!is_string($passwordHash) || !password_verify($password, $passwordHash)) {
            return null;
        }

        if (!password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
            return $user;
        }

        $Model = $this->getModel();
        $primaryValues = $Model->getPrimaryKey() |> $user->extract(...);

        if ($primaryValues === [] || in_array(null, $primaryValues, true)) {
            return null;
        }

        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $updated = $Model->updateAll(
            [$passwordField => $newHash],
            $primaryValues + [$passwordField => $passwordHash]
        );

        if ($updated) {
            $user->set($passwordField, $newHash);

            return $user;
        }

        $user = $this->findIdentity($primaryValues, connectionType: Model::WRITE);
        $currentHash = $user?->get($passwordField);

        if (!is_string($currentHash) || $currentHash === $passwordHash) {
            return null;
        }

        return password_verify($password, $currentHash) ? $user : null;
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
        return $this->findIdentity(array_fill_keys($fields, $value), 'OR');
    }

    /**
     * Finds an identity matching the field values and the configured query callback.
     *
     * @param array<int|string, mixed> $fieldValues The field values to match.
     * @param string $conjunction The condition conjunction.
     * @param string $connectionType The connection type.
     * @return Entity|null The matching identity.
     */
    protected function findIdentity(
        array $fieldValues,
        string $conjunction = 'AND',
        string $connectionType = Model::READ
    ): Entity|null {
        if ($fieldValues === []) {
            return null;
        }

        $Model = $this->getModel();
        $conditions = new ConditionExpression($conjunction);
        foreach ($fieldValues as $field => $value) {
            $conditions->eq($Model->aliasField((string) $field), $value);
        }

        $query = $Model->find(conditions: $conditions, connectionType: $connectionType);

        if ($this->queryCallback) {
            $query = ($this->queryCallback)($query);
        }

        return $query->first();
    }
}
