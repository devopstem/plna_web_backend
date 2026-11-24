<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ProductImportService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ImportProductsCommand extends Command
{
    protected $signature = 'products:import
                            {path : Absolute or relative path to CSV/TXT}
                            {--tenant= : Tenant UUID (default: first active tenant)}
                            {--global : Create/update global catalog SKUs (Super Admin / ops)}';

    protected $description = 'Import a large products CSV from disk (bypasses HTTP upload limits)';

    public function handle(ProductImportService $imports): int
    {
        $path = (string) $this->argument('path');

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        $tenantId = $this->option('tenant');

        if (! is_string($tenantId) || $tenantId === '') {
            $tenantId = Tenant::query()->where('is_active', true)->value('id');
        }

        if (! $tenantId) {
            $this->error('Нет активного арендатора. Укажите --tenant=UUID');

            return self::FAILURE;
        }

        $allowGlobal = (bool) $this->option('global');

        $this->info("Импорт: {$path}");
        $this->info("Tenant: {$tenantId}");
        $this->info($allowGlobal ? 'Режим: глобальный каталог' : 'Режим: приватные SKU арендатора');

        try {
            $result = $imports->importPath($path, (string) $tenantId, $allowGlobal);
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Готово: создано %d, обновлено %d, ошибок %d.',
            $result['created'],
            $result['updated'],
            $result['errors'],
        ));

        if ($result['errors'] > 0) {
            $shown = count($result['error_rows']);
            $this->warn("Показаны первые {$shown} строк с ошибками (всего ошибок: {$result['errors']}).");

            foreach ($result['error_rows'] as $row) {
                $this->line(sprintf(
                    '  row %d [%s]: %s',
                    $row['row'],
                    $row['barcode'] ?? '—',
                    implode('; ', $row['messages']),
                ));
            }
        }

        return self::SUCCESS;
    }
}
