<?php
declare(strict_types=1);

namespace Fyre\DB\Handlers\Mysql;

use Fyre\Core\Attributes\SensitivePropertyArray;
use Fyre\DB\Connection;
use Fyre\DB\DbFeature;
use Fyre\DB\Exceptions\DbException;
use Fyre\DB\QueryGenerator;
use Override;
use Pdo\Mysql;
use PDOException;

use function array_replace;
use function class_exists;
use function sprintf;
use function str_contains;
use function str_replace;
use function version_compare;

/**
 * Provides a MySQL {@see Connection} implementation.
 */
class MysqlConnection extends Connection
{
    #[Override]
    protected static array $defaults = [
        'host' => '127.0.0.1',
        'username' => '',
        'password' => '',
        'database' => '',
        'port' => '3306',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'compress' => false,
        'persist' => false,
        'timeout' => null,
        'ssl' => [
            'key' => null,
            'cert' => null,
            'ca' => null,
            'capath' => null,
            'cipher' => null,
        ],
        'flags' => [],
    ];

    #[Override]
    protected static array $retryDriverCodes = [
        1927, // connection killed
        2006, // server gone away
        2013, // server lost during query
        2055, // server lost
        4031, // inactivity timeout
    ];

    #[Override]
    protected static array $retryErrorCodes = [
        '08S01', // communication link failure
    ];

    #[Override]
    #[SensitivePropertyArray([
        'host',
        'username',
        'password',
        'database',
        'port',
        'ssl' => [
            'key',
            'cert',
            'ca',
            'capath',
            'cipher',
        ],
    ])]
    protected array $config;

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
            throw new DbException('Mysql connection requires PDO extension.');
        }

        $dsn = 'mysql:host='.$this->config['host'].';dbname='.$this->config['database'];

        if ($this->config['port']) {
            $dsn .= ';port='.((int) $this->config['port']);
        }

        if ($this->config['charset']) {
            $dsn .= ';charset='.$this->config['charset'];
        }

        $options = [
            Mysql::ATTR_ERRMODE => Mysql::ERRMODE_EXCEPTION,
        ];

        if ($this->config['timeout']) {
            $options[Mysql::ATTR_TIMEOUT] = $this->config['timeout'];
        }

        if ($this->config['collation']) {
            $options[Mysql::ATTR_INIT_COMMAND] = 'SET collation_connection = '.$this->config['collation'];
        }

        if ($this->config['compress']) {
            $options[Mysql::ATTR_COMPRESS] = true;
        }

        if ($this->config['persist']) {
            $options[Mysql::ATTR_PERSISTENT] = true;
        }

        if ($this->config['ssl']) {
            if ($this->config['ssl']['key']) {
                $options[Mysql::ATTR_SSL_KEY] = $this->config['ssl']['key'];
            }
            if ($this->config['ssl']['cert']) {
                $options[Mysql::ATTR_SSL_CERT] = $this->config['ssl']['cert'];
            }
            if ($this->config['ssl']['ca']) {
                $options[Mysql::ATTR_SSL_CA] = $this->config['ssl']['ca'];
            }
            if ($this->config['ssl']['capath']) {
                $options[Mysql::ATTR_SSL_CAPATH] = $this->config['ssl']['capath'];
            }
            if ($this->config['ssl']['cipher']) {
                $options[Mysql::ATTR_SSL_CIPHER] = $this->config['ssl']['cipher'];
            }
        }

        $options = array_replace($options, $this->config['flags']);

        try {
            $this->pdo = new Mysql($dsn, $this->config['username'], $this->config['password'], $options);
        } catch (PDOException $e) {
            throw new DbException(sprintf(
                'Database connection error: %s',
                $e->getMessage()
            ), previous: $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function disableForeignKeys(): static
    {
        $this->rawQuery('SET FOREIGN_KEY_CHECKS = 0');

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function enableForeignKeys(): static
    {
        $this->rawQuery('SET FOREIGN_KEY_CHECKS = 1');

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function generator(): QueryGenerator
    {
        return $this->generator ??= $this->container->build(MysqlQueryGenerator::class, ['connection' => $this]);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getCharset(): string
    {
        return (string) $this->rawQuery('SELECT @@character_set_client', retrySafe: true)->fetchColumn();
    }

    /**
     * Returns the connection collation.
     *
     * @return string The connection collation.
     */
    public function getCollation(): string
    {
        return (string) $this->rawQuery('SELECT @@collation_connection', retrySafe: true)->fetchColumn();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function quoteIdentifierPart(string $identifier): string
    {
        $identifier = str_replace('`', '``', $identifier);

        return '`'.$identifier.'`';
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function supports(DbFeature $feature): bool
    {
        if ($feature === DbFeature::OptimizerHints) {
            $version = $this->version();

            return !str_contains($version, 'MariaDB') || version_compare($version, '12.0', '>=');
        }

        return match ($feature) {
            DbFeature::DeleteAlias,
            DbFeature::DeleteJoin,
            DbFeature::DeleteLimit,
            DbFeature::DeleteMultipleTables,
            DbFeature::DeleteOrderBy,
            DbFeature::UpdateJoin,
            DbFeature::UpdateMultipleTables => true,
            default => false,
        };
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function truncate(string $tableName): static
    {
        $this->rawQuery('TRUNCATE TABLE '.$tableName);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected static function resultSetClass(): string
    {
        return MysqlStatementResultSet::class;
    }
}
