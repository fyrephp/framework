<?php
declare(strict_types=1);

namespace Fyre\DB\Handlers\Postgres;

use Fyre\Core\Attributes\SensitiveProperty;
use Fyre\Core\Attributes\SensitivePropertyArray;
use Fyre\DB\Connection;
use Fyre\DB\DbFeature;
use Fyre\DB\Exceptions\DbException;
use Fyre\DB\QueryGenerator;
use Override;
use Pdo\Pgsql;
use PDOException;

use function array_replace;
use function class_exists;
use function sprintf;

/**
 * Provides a PostgreSQL {@see Connection} implementation.
 */
class PostgresConnection extends Connection
{
    #[Override]
    protected static array $defaults = [
        'host' => '127.0.0.1',
        'username' => '',
        'password' => '',
        'database' => '',
        'port' => '5432',
        'charset' => 'utf8',
        'schema' => 'public',
        'persist' => false,
        'timeout' => null,
        'flags' => [],
    ];

    #[Override]
    protected static array $lockConstraintErrorCodes = [
        '23505', // unique violation
    ];

    #[Override]
    protected static array $retryErrorCodes = [
        '08003', // connection does not exist
        '08006', // connection failure
        '57P01', // admin shutdown
        '57P02', // crash shutdown
        '57P05', // idle session timeout
    ];

    #[Override]
    #[SensitivePropertyArray([
        'host',
        'username',
        'password',
        'database',
        'port',
        'schema',
    ])]
    protected array $config;

    #[SensitiveProperty]
    protected string $schema;

    /**
     * {@inheritDoc}
     *
     * @throws DbException If PDO extension is not installed, or the connection fails.
     */
    #[Override]
    public function connect(): void
    {
        if ($this->pdo) {
            return;
        }

        if (!class_exists('PDO')) {
            throw new DbException('Postgres connection requires PDO extension.');
        }

        $dsn = 'pgsql:host='.$this->config['host'].';dbname='.$this->config['database'];

        if ($this->config['port']) {
            $dsn .= ';port='.((int) $this->config['port']);
        }

        $options = [
            Pgsql::ATTR_ERRMODE => Pgsql::ERRMODE_EXCEPTION,
        ];

        if ($this->config['timeout']) {
            $options[Pgsql::ATTR_TIMEOUT] = $this->config['timeout'];
        }

        if ($this->config['persist']) {
            $options[Pgsql::ATTR_PERSISTENT] = true;
        }

        $options = array_replace($options, $this->config['flags']);

        try {
            $this->pdo = new Pgsql($dsn, $this->config['username'], $this->config['password'], $options);
        } catch (PDOException $e) {
            throw new DbException(sprintf(
                'Database connection error: %s',
                $e->getMessage()
            ), previous: $e);
        }

        if ($this->config['charset']) {
            $this->setCharset($this->config['charset']);
        }

        if ($this->config['schema']) {
            $this->setSchema($this->config['schema']);
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function disableForeignKeys(): static
    {
        $this->rawQuery('SET CONSTRAINTS ALL DEFERRED');

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function enableForeignKeys(): static
    {
        $this->rawQuery('SET CONSTRAINTS ALL IMMEDIATE');

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function generator(): QueryGenerator
    {
        return $this->generator ??= $this->container->build(PostgresQueryGenerator::class, ['connection' => $this]);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getCharset(): string
    {
        return (string) $this->rawQuery('SHOW CLIENT_ENCODING', retrySafe: true)->fetchColumn();
    }

    /**
     * Returns the connection schema.
     *
     * @return string The schema name.
     */
    public function getSchema(): string
    {
        return $this->schema ?? $this->config['schema'];
    }

    /**
     * Sets the connection schema.
     *
     * @param string $schema The schema name.
     * @return static The PostgresConnection instance.
     */
    public function setSchema(string $schema): static
    {
        $this->rawQuery('SET search_path TO '.$this->quote($schema));

        $this->schema = $schema;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function supports(DbFeature $feature): bool
    {
        return match ($feature) {
            DbFeature::DeleteUsing,
            DbFeature::InsertReturning,
            DbFeature::UpdateFrom => true,
            default => false,
        };
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function truncate(string $tableName): static
    {
        $this->rawQuery('TRUNCATE '.$tableName.' RESTART IDENTITY CASCADE');

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected static function resultSetClass(): string
    {
        return PostgresStatementResultSet::class;
    }
}
