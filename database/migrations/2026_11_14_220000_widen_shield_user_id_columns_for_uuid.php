<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ozankurt/laravel-security creates user_id as bigint (foreignId / unsignedBigInteger).
 * PLNA users use UUID/ULID string primary keys — widen columns accordingly.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = (string) config('shield.database.table_prefix', 'security_');
        $connection = config('shield.database.connection') ?: config('database.default');
        $driver = Schema::connection($connection)->getConnection()->getDriverName();

        $tables = [
            $prefix.config('shield.database.log.table', 'logs'),
            $prefix.config('shield.database.auth_log.table', 'auth_logs'),
            'ls_live_traffic',
        ];

        foreach ($tables as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                continue;
            }

            if (! Schema::connection($connection)->hasColumn($table, 'user_id')) {
                continue;
            }

            $this->dropUserIdForeignKeys($connection, $table, $driver);

            match ($driver) {
                'pgsql' => DB::connection($connection)->statement(
                    "ALTER TABLE {$table} ALTER COLUMN user_id TYPE varchar(36) USING NULLIF(user_id::text, '')::varchar(36)",
                ),
                'mysql', 'mariadb' => DB::connection($connection)->statement(
                    "ALTER TABLE `{$table}` MODIFY `user_id` VARCHAR(36) NULL",
                ),
                'sqlite' => null, // SQLite is typeless enough for local smoke tests.
                default => null,
            };
        }
    }

    public function down(): void
    {
        // Irreversible safely: UUID strings cannot cast back to bigint.
    }

    private function dropUserIdForeignKeys(string $connection, string $table, string $driver): void
    {
        if ($driver === 'pgsql') {
            $constraints = DB::connection($connection)->select(
                'SELECT conname
                 FROM pg_constraint
                 WHERE contype = ?
                   AND conrelid = ?::regclass',
                ['f', $table],
            );

            foreach ($constraints as $constraint) {
                $name = (string) $constraint->conname;
                if (! str_contains($name, 'user_id')) {
                    continue;
                }

                DB::connection($connection)->statement(
                    "ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS \"{$name}\"",
                );
            }

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $database = Schema::connection($connection)->getConnection()->getDatabaseName();
            $constraints = DB::connection($connection)->select(
                'SELECT CONSTRAINT_NAME AS name
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$database, $table, 'user_id'],
            );

            foreach ($constraints as $constraint) {
                $name = (string) $constraint->name;
                DB::connection($connection)->statement(
                    "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`",
                );
            }
        }
    }
};
