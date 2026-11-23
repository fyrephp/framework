<?php
declare(strict_types=1);

namespace Fyre\DB\Queries;

use Closure;
use Fyre\Core\Traits\MacroTrait;
use Fyre\DB\Connection;
use Fyre\DB\Pagination\CursorPage;
use Fyre\DB\Pagination\Page;
use Fyre\DB\Pagination\PageWithTotal;
use Fyre\DB\Queries\Traits\DistinctTrait;
use Fyre\DB\Queries\Traits\EpilogTrait;
use Fyre\DB\Queries\Traits\FromTrait;
use Fyre\DB\Queries\Traits\GroupByTrait;
use Fyre\DB\Queries\Traits\GroupLimitTrait;
use Fyre\DB\Queries\Traits\HavingTrait;
use Fyre\DB\Queries\Traits\JoinTrait;
use Fyre\DB\Queries\Traits\LimitOffsetTrait;
use Fyre\DB\Queries\Traits\OrderByTrait;
use Fyre\DB\Queries\Traits\SelectTrait;
use Fyre\DB\Queries\Traits\UnionTrait;
use Fyre\DB\Queries\Traits\WhereTrait;
use Fyre\DB\Queries\Traits\WithTrait;
use Fyre\DB\Query;
use Fyre\DB\ResultSet;
use Fyre\DB\ValueBinder;
use InvalidArgumentException;
use Override;

/**
 * Builds SELECT queries.
 *
 * @template TItem = array<string, mixed>
 */
class SelectQuery extends Query
{
    use DistinctTrait;
    use EpilogTrait;
    use FromTrait;
    use GroupByTrait;
    use GroupLimitTrait;
    use HavingTrait;
    use JoinTrait;
    use LimitOffsetTrait;
    use MacroTrait;
    use OrderByTrait;
    use SelectTrait;
    use UnionTrait;
    use WhereTrait;
    use WithTrait;

    public const GROUP_LIMIT_ROW = '__fyre_group_row';

    public const GROUP_LIMIT_TABLE = '__fyre_group';

    #[Override]
    protected static bool $tableAliases = true;

    #[Override]
    protected static bool $virtualTables = true;

    #[Override]
    protected bool $multipleTables = true;

    /**
     * @var (Closure(array<string, mixed>): array<string, mixed>)|null
     */
    protected Closure|null $resultCallback = null;

    /**
     * Constructs a SelectQuery.
     *
     * @param Connection $connection The Connection.
     * @param array<mixed>|string $fields The fields.
     */
    public function __construct(Connection $connection, array|string $fields = '*')
    {
        parent::__construct($connection);

        $this->select($fields);
    }

    /**
     * Returns the result count.
     *
     * Note: This counts the current query (including any applied LIMIT/OFFSET) by wrapping
     * the query as a subquery and removing ORDER BY.
     *
     * @return int The result count.
     */
    public function count(): int
    {
        $query = clone $this;

        $countQuery = $this->connection
            ->select([
                'count' => $query->func()->count(),
            ])
            ->from([
                'count_source' => $query->orderBy([], true),
            ]);

        return (int) ($countQuery->execute()->first()['count'] ?? 0);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function execute(ValueBinder|null $binder = null, bool $retrySafe = false): ResultSet
    {
        $result = parent::execute($binder, $retrySafe);

        if ($this->groupLimit !== null) {
            return $result->decorate(static function(array $row): array {
                unset($row[static::GROUP_LIMIT_ROW]);

                return $row;
            });
        }

        if ($this->resultCallback !== null) {
            return $result->decorate($this->resultCallback);
        }

        return $result;
    }

    /**
     * Paginates the query results.
     *
     * Note: The Page fetches one additional result to determine whether another page exists.
     * Any existing LIMIT/OFFSET clauses are replaced by the pagination values.
     *
     * @param int $page The page number.
     * @param int $perPage The maximum number of items per page.
     * @return Page<TItem> The paginated results.
     *
     * @throws InvalidArgumentException If the page, items per page, or query is not valid.
     */
    public function paginate(int $page = 1, int $perPage = 20): Page
    {
        return new Page($this, $page, $perPage);
    }

    /**
     * Paginates the query results by cursor.
     *
     * Note: Cursor pagination requires an ORDER BY clause containing simple fields or selected
     * aliases that resolve to fields or value expressions, with ASC or DESC directions. For
     * deterministic pagination, the final ordered field should be unique and all ordered fields
     * should contain non-null scalar values. DISTINCT queries require all ordered fields to be
     * explicitly selected. Set-operation queries are not supported.
     *
     * Any existing LIMIT/OFFSET clauses are replaced by the pagination values.
     *
     * @param string|null $cursor The pagination cursor.
     * @param int $perPage The maximum number of items per page.
     * @return CursorPage<TItem> The paginated results.
     *
     * @throws InvalidArgumentException If the cursor, ordering, items per page, or query is not valid.
     */
    public function paginateByCursor(string|null $cursor = null, int $perPage = 20): CursorPage
    {
        return new CursorPage($this, $cursor, $perPage);
    }

    /**
     * Paginates the query results with total-count metadata.
     *
     * Note: The PageWithTotal clones this query and lazily executes the item and count queries.
     * Any existing LIMIT/OFFSET clauses are replaced by the pagination values.
     *
     * @param int $page The page number.
     * @param int $perPage The maximum number of items per page.
     * @return PageWithTotal<TItem> The paginated results with total metadata.
     *
     * @throws InvalidArgumentException If the page, items per page, or query is not valid.
     */
    public function paginateWithTotal(int $page = 1, int $perPage = 20): PageWithTotal
    {
        return new PageWithTotal($this, $page, $perPage);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function sql(ValueBinder|null $binder = null): string
    {
        return $this->connection->generator()
            ->compileSelect($this, $binder);
    }

    /**
     * Returns the results as an array.
     *
     * @return array<TItem> The results.
     */
    public function toArray(): array
    {
        /** @var array<TItem> */
        return $this->execute()->all();
    }
}
