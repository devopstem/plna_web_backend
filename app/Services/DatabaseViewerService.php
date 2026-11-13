<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class DatabaseViewerService
{
    private const MASK = '********';

    /**
     * @var list<string>
     */
    private const ALLOWED_TABLES = [
        'activity_log',
        'bank_accounts',
        'chat_conversation_user',
        'chat_conversations',
        'chat_messages',
        'cooler_shelf_levels',
        'coolers',
        'departments',
        'invoices',
        'legal_documents',
        'model_has_permissions',
        'model_has_roles',
        'notifications',
        'payments',
        'permissions',
        'placements',
        'plans',
        'products',
        'role_has_permissions',
        'roles',
        'settings',
        'shelf_levels',
        'shelves',
        'stand_shelf_levels',
        'stands',
        'stores',
        'subscriptions',
        'tenants',
        'user_comments',
        'user_login_logs',
        'user_sessions',
        'users',
    ];

    /**
     * @var list<string>
     */
    private const BLOCKED_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'password_reset_tokens',
        'personal_access_tokens',
        'sessions',
    ];

    private const MAX_SQL_ROWS = 500;

    private const STATEMENT_TIMEOUT_MS = 5000;

    /**
     * @var list<string>
     */
    private const FORBIDDEN_SQL_KEYWORDS = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'TRUNCATE',
        'ALTER',
        'CREATE',
        'REPLACE',
        'MERGE',
        'GRANT',
        'REVOKE',
        'COPY',
        'CALL',
        'EXEC',
        'EXECUTE',
        'INTO',
        'DO',
        'SET',
        'RESET',
        'LISTEN',
        'NOTIFY',
        'LOAD',
        'VACUUM',
        'ANALYZE',
        'REINDEX',
        'CLUSTER',
        'COMMENT',
        'SECURITY',
        'OWNER',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_SQL_FUNCTIONS = [
        'pg_read_file',
        'pg_read_binary_file',
        'pg_ls_dir',
        'pg_stat_file',
        'pg_sleep',
        'pg_terminate_backend',
        'pg_cancel_backend',
        'pg_reload_conf',
        'pg_rotate_logfile',
        'lo_import',
        'lo_export',
        'lo_get',
        'lo_put',
        'lo_from_bytea',
        'dblink',
        'dblink_exec',
        'dblink_connect',
        'dblink_connect_u',
        'current_setting',
        'set_config',
        'pg_get_file',
        'pg_file_write',
        'pg_file_rename',
        'pg_file_unlink',
    ];

    /**
     * @return list<array{name: string, count: int}>
     */
    public function tablesWithCounts(): array
    {
        $tables = [];

        foreach (self::ALLOWED_TABLES as $table) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $tables[] = [
                'name' => $table,
                'count' => (int) DB::table($table)->count(),
            ];
        }

        usort(
            $tables,
            fn (array $left, array $right): int => strcmp($left['name'], $right['name']),
        );

        return $tables;
    }

    /**
     * @return list<string>
     */
    public function columns(string $table): array
    {
        $this->assertTableAllowed($table);

        return DB::getSchemaBuilder()->getColumnListing($table);
    }

    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function paginateTable(
        string $table,
        ?string $search = null,
        ?string $sort = null,
        string $direction = 'asc',
        int $perPage = 20,
    ): LengthAwarePaginator {
        $this->assertTableAllowed($table);

        $columns = $this->columns($table);
        $query = DB::table($table);

        if ($search !== null && $search !== '') {
            $textColumns = $this->textColumns($table);

            if ($textColumns !== []) {
                $query->where(function ($builder) use ($textColumns, $search): void {
                    foreach ($textColumns as $column) {
                        $builder->orWhere($column, 'ilike', '%'.$search.'%');
                    }
                });
            }
        }

        $sortColumn = $this->resolveSortColumn($columns, $sort);
        $sortDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortColumn, $sortDirection);

        $paginator = $query->paginate($perPage)->withQueryString();

        return $paginator->through(fn (object $row): array => $this->maskRow($table, (array) $row));
    }

    /**
     * @return array{
     *     columns: list<string>,
     *     rows: list<array<string, mixed>>,
     *     row_count: int
     * }
     */
    public function executeSelect(string $sql): array
    {
        $normalized = $this->normalizeSql($sql);
        $this->assertSelectOnly($normalized);
        $this->assertNoDangerousFunctions($normalized);
        $this->assertReferencedTablesAllowed($normalized);
        $limited = $this->enforceRowLimit($normalized);

        try {
            $rows = DB::transaction(function () use ($limited): array {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement('SET LOCAL statement_timeout = '.self::STATEMENT_TIMEOUT_MS);
                }

                return DB::select($limited);
            });
        } catch (\Throwable $exception) {
            throw new RuntimeException('Ошибка выполнения запроса: '.$exception->getMessage(), 0, $exception);
        }

        if ($rows === []) {
            return [
                'columns' => [],
                'rows' => [],
                'row_count' => 0,
            ];
        }

        $columns = array_keys((array) $rows[0]);
        $maskedRows = array_map(
            fn (object $row): array => $this->maskSqlRow((array) $row),
            $rows,
        );

        return [
            'columns' => $columns,
            'rows' => $maskedRows,
            'row_count' => count($maskedRows),
        ];
    }

    public function assertTableAllowed(string $table): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('Недопустимое имя таблицы.');
        }

        if (in_array($table, self::BLOCKED_TABLES, true)) {
            throw new InvalidArgumentException('Доступ к системной таблице запрещён.');
        }

        if (! in_array($table, self::ALLOWED_TABLES, true)) {
            throw new InvalidArgumentException('Таблица не входит в белый список.');
        }

        if (! $this->tableExists($table)) {
            throw new InvalidArgumentException('Таблица не найдена.');
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    /**
     * @param  list<string>  $columns
     */
    private function resolveSortColumn(array $columns, ?string $sort): string
    {
        if ($sort !== null && in_array($sort, $columns, true)) {
            return $sort;
        }

        if (in_array('created_at', $columns, true)) {
            return 'created_at';
        }

        if (in_array('id', $columns, true)) {
            return 'id';
        }

        return $columns[0] ?? 'id';
    }

    /**
     * @return list<string>
     */
    private function textColumns(string $table): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $columns = DB::select(
                'SELECT column_name, data_type
                 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = ?
                 ORDER BY ordinal_position',
                [$table],
            );

            return array_values(array_map(
                static fn (object $column): string => (string) $column->column_name,
                array_filter(
                    $columns,
                    static fn (object $column): bool => in_array(
                        (string) $column->data_type,
                        ['character varying', 'text', 'character', 'uuid', 'json', 'jsonb'],
                        true,
                    ),
                ),
            ));
        }

        return $this->columns($table);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function maskRow(string $table, array $row): array
    {
        foreach ($row as $column => $value) {
            if ($this->isSensitiveColumn($column)) {
                $row[$column] = self::MASK;

                continue;
            }

            if ($table === 'settings' && $column === 'value' && $this->isSensitiveSettingKey($row['key'] ?? null)) {
                $row[$column] = self::MASK;
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function maskSqlRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if ($this->isSensitiveColumn((string) $column)) {
                $row[$column] = self::MASK;
            }
        }

        return $row;
    }

    private function isSensitiveColumn(string $column): bool
    {
        $normalized = strtolower($column);

        return str_contains($normalized, 'password')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'api_key')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'remember_token');
    }

    private function isSensitiveSettingKey(mixed $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $normalized = strtolower($key);

        return str_contains($normalized, 'password')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'api_key')
            || str_contains($normalized, 'token');
    }

    private function normalizeSql(string $sql): string
    {
        $normalized = trim($sql);
        $normalized = preg_replace('/--.*$/m', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\/\*.*?\*\//s', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function assertSelectOnly(string $sql): void
    {
        if ($sql === '') {
            throw new InvalidArgumentException('SQL-запрос не может быть пустым.');
        }

        if (! preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new InvalidArgumentException('Разрешены только SELECT-запросы.');
        }

        foreach (self::FORBIDDEN_SQL_KEYWORDS as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $sql)) {
                throw new InvalidArgumentException('Запрос содержит запрещённую операцию: '.$keyword.'.');
            }
        }

        $withoutTrailingSemicolon = rtrim($sql, " \t\n\r\0\x0B;");

        if (str_contains($withoutTrailingSemicolon, ';')) {
            throw new InvalidArgumentException('Разрешён только один SQL-запрос.');
        }
    }

    private function assertNoDangerousFunctions(string $sql): void
    {
        if (preg_match('/\b(pg_catalog|information_schema|pg_temp)\b/i', $sql)) {
            throw new InvalidArgumentException('Обращение к системным схемам запрещено.');
        }

        if (preg_match('/\bpg_[a-z0-9_]+\b/i', $sql)) {
            throw new InvalidArgumentException('Вызов pg_* функций и отношений запрещён.');
        }

        foreach (self::FORBIDDEN_SQL_FUNCTIONS as $function) {
            if (preg_match('/\b'.preg_quote($function, '/').'\s*\(/i', $sql)) {
                throw new InvalidArgumentException('Запрос содержит запрещённую функцию: '.$function.'.');
            }
        }
    }

    private function assertReferencedTablesAllowed(string $sql): void
    {
        preg_match_all(
            '/\b(?:FROM|JOIN)\s+(?:ONLY\s+)?(?:"([^"]+)"|\'([^\']+)\'|([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?))/i',
            $sql,
            $matches,
        );

        $references = array_values(array_filter(array_merge($matches[1], $matches[2], $matches[3])));

        if ($references === []) {
            throw new InvalidArgumentException(
                'SELECT должен ссылаться на таблицу из белого списка (нужен FROM).',
            );
        }

        foreach ($references as $reference) {
            $table = str_contains($reference, '.')
                ? (string) str($reference)->afterLast('.')
                : $reference;

            $normalizedTable = strtolower($table);

            if (str_starts_with($normalizedTable, 'pg_')) {
                throw new InvalidArgumentException('Доступ к системным таблицам pg_* запрещён.');
            }

            $this->assertTableAllowed($normalizedTable);
        }
    }

    private function enforceRowLimit(string $sql): string
    {
        $withoutTrailingSemicolon = rtrim($sql, " \t\n\r\0\x0B;");

        if (preg_match('/\bLIMIT\s+(\d+)\b/i', $withoutTrailingSemicolon, $matches)) {
            $limit = (int) $matches[1];

            if ($limit > self::MAX_SQL_ROWS) {
                return (string) preg_replace(
                    '/\bLIMIT\s+\d+\b/i',
                    'LIMIT '.self::MAX_SQL_ROWS,
                    $withoutTrailingSemicolon,
                    1,
                );
            }

            return $withoutTrailingSemicolon;
        }

        return $withoutTrailingSemicolon.' LIMIT '.self::MAX_SQL_ROWS;
    }
}
