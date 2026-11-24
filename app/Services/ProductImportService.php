<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\ProductBarcode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductImportService
{
    public const HEADERS = [
        'barcode',
        'name',
        'category',
        'volume_ml',
        'package_type',
        'width_mm',
        'height_mm',
        'depth_mm',
        'weight_g',
    ];

    /** Laravel `max:` is kilobytes. 128 MB for large catalog CSVs. */
    public const MAX_UPLOAD_KB = 131072;

    /** Keep session/UI light even if thousands of rows fail. */
    public const MAX_ERROR_ROWS = 100;

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     errors: int,
     *     error_rows: list<array{row: int, barcode: string|null, messages: list<string>}>
     * }
     */
    public function import(UploadedFile $file, string $tenantId, bool $allowGlobalMutation = false): array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => 'Не удалось прочитать файл.',
            ]);
        }

        return $this->importPath($path, $tenantId, $allowGlobalMutation);
    }

    /**
     * Stream CSV from disk (CLI / large files) — no full-file memory load.
     *
     * @return array{
     *     created: int,
     *     updated: int,
     *     errors: int,
     *     error_rows: list<array{row: int, barcode: string|null, messages: list<string>}>
     * }
     */
    public function importPath(string $path, string $tenantId, bool $allowGlobalMutation = false): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'file' => 'Не удалось прочитать файл.',
            ]);
        }

        @set_time_limit(0);

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => 'Не удалось прочитать файл.',
            ]);
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => 'CSV-файл пуст.',
            ]);
        }

        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
        $delimiter = $this->detectDelimiter($firstLine);
        rewind($handle);

        $created = 0;
        $updated = 0;
        $errors = 0;
        $errorRows = [];
        $rowNumber = 0;
        $headerValidated = false;

        try {
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $rowNumber++;

                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $row = $this->normalizeRow($row);

                if (! $headerValidated) {
                    if ($this->looksLikeHeader($row)) {
                        $headerValidated = true;

                        continue;
                    }

                    $headerValidated = true;
                }

                $parsed = $this->parseRow($row);
                $validator = Validator::make($parsed, $this->rowRules(), $this->rowMessages());

                if ($validator->fails()) {
                    $errors++;
                    if (count($errorRows) < self::MAX_ERROR_ROWS) {
                        $errorRows[] = [
                            'row' => $rowNumber,
                            'barcode' => $parsed['barcode'] !== '' ? $parsed['barcode'] : null,
                            'messages' => $validator->errors()->all(),
                        ];
                    }

                    continue;
                }

                $attributes = $this->attributesFromValidated($validator->validated());

                $product = Product::withTrashed()
                    ->withoutGlobalScopes()
                    ->where('barcode', $attributes['barcode'])
                    ->first();

                if ($product) {
                    if (
                        $product->owner_tenant_id !== null
                        && $product->owner_tenant_id !== $tenantId
                    ) {
                        $errors++;
                        if (count($errorRows) < self::MAX_ERROR_ROWS) {
                            $errorRows[] = [
                                'row' => $rowNumber,
                                'barcode' => $attributes['barcode'],
                                'messages' => [
                                    'Штрихкод занят приватным товаром другого арендатора.',
                                ],
                            ];
                        }

                        continue;
                    }

                    if ($product->owner_tenant_id === null && ! $allowGlobalMutation) {
                        $errors++;
                        if (count($errorRows) < self::MAX_ERROR_ROWS) {
                            $errorRows[] = [
                                'row' => $rowNumber,
                                'barcode' => $attributes['barcode'],
                                'messages' => [
                                    'Глобальный товар каталога может изменить только Super Admin.',
                                ],
                            ];
                        }

                        continue;
                    }

                    if ($product->trashed()) {
                        $product->restore();
                    }

                    $product->update($attributes);
                    $updated++;

                    continue;
                }

                Product::query()->create([
                    ...$attributes,
                    // Tenant CSV import creates private SKUs; Super Admin may seed globals.
                    'owner_tenant_id' => $allowGlobalMutation ? null : $tenantId,
                    'checked' => false,
                ]);
                $created++;
            }
        } finally {
            fclose($handle);
        }

        if ($rowNumber === 0 || (! $headerValidated && $created === 0 && $updated === 0 && $errors === 0)) {
            throw ValidationException::withMessages([
                'file' => 'CSV-файл пуст.',
            ]);
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'error_rows' => $errorRows,
        ];
    }

    public function templateCsv(): string
    {
        $lines = [
            implode(';', self::HEADERS),
            '4810014018641;Квас Старажытны;Квас Белорусский;1500;ПЭТ;80;250;80;1500',
            '4607025392585;Вода негазированная;Вода;1500;ПЭТ;80;280;80;1500',
            '008421369041;Пример UPC-A 12 цифр;Мягкие игрушки;;Плюш;130;230;110;201',
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, list<string>>
     */
    private function rowRules(): array
    {
        return [
            'barcode' => ProductBarcode::rules(),
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'volume_ml' => ['nullable', 'integer', 'min:0'],
            'package_type' => ['nullable', 'string', 'max:255'],
            'width_mm' => ['nullable', 'integer', 'min:0'],
            'height_mm' => ['nullable', 'integer', 'min:0'],
            'depth_mm' => ['nullable', 'integer', 'min:0'],
            'weight_g' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function rowMessages(): array
    {
        return [
            ...ProductBarcode::messages(),
            'name.required' => 'Название обязательно.',
            'volume_ml.integer' => 'volume_ml должно быть целым числом.',
            'width_mm.integer' => 'width_mm должно быть целым числом.',
            'height_mm.integer' => 'height_mm должно быть целым числом.',
            'depth_mm.integer' => 'depth_mm должно быть целым числом.',
            'weight_g.integer' => 'weight_g должно быть целым числом.',
        ];
    }

    /**
     * @param  list<string|null>  $row
     * @return array<string, mixed>
     */
    private function parseRow(array $row): array
    {
        $values = [];

        foreach (self::HEADERS as $index => $header) {
            $values[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
        }

        $values['barcode'] = ProductBarcode::normalize($values['barcode']);

        foreach (['volume_ml', 'width_mm', 'height_mm', 'depth_mm', 'weight_g'] as $field) {
            if ($values[$field] === '') {
                $values[$field] = null;
            }
        }

        $values['package_type'] = $this->nullableTruncated($values['package_type'], 255);
        $values['category'] = $this->nullableTruncated($values['category'], 255);
        $values['name'] = mb_substr($values['name'], 0, 255);

        return $values;
    }

    private function nullableTruncated(string $value, int $max): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributesFromValidated(array $validated): array
    {
        $category = isset($validated['category']) ? trim((string) $validated['category']) : '';

        return [
            'barcode' => $validated['barcode'],
            'name' => trim((string) $validated['name']),
            'category' => $category !== '' ? $category : 'Без категории',
            'volume_ml' => $validated['volume_ml'] ?? null,
            'package_type' => isset($validated['package_type'])
                ? (trim((string) $validated['package_type']) ?: null)
                : null,
            'width_mm' => $validated['width_mm'] ?? null,
            'height_mm' => $validated['height_mm'] ?? null,
            'depth_mm' => $validated['depth_mm'] ?? null,
            'weight_g' => $validated['weight_g'] ?? null,
        ];
    }

    private function detectDelimiter(string $firstLine): string
    {
        $semicolon = substr_count($firstLine, ';');
        $comma = substr_count($firstLine, ',');

        if ($semicolon === 0 && $comma === 0) {
            return ';';
        }

        return $semicolon >= $comma ? ';' : ',';
    }

    /**
     * @param  list<string|null>  $row
     * @return list<string>
     */
    private function normalizeRow(array $row): array
    {
        return array_map(static function ($value): string {
            $value = (string) ($value ?? '');
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

            return trim($value);
        }, $row);
    }

    /**
     * @param  list<string>  $row
     */
    private function looksLikeHeader(array $row): bool
    {
        $first = strtolower($row[0] ?? '');

        return $first === 'barcode' || $first === self::HEADERS[0];
    }

    /**
     * @param  list<string|null>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
