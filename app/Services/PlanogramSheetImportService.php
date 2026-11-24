<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cooler;
use App\Models\Placement;
use App\Models\PlanogramSheetImport;
use App\Models\PlanogramSheetImportRow;
use App\Models\Product;
use App\Models\Shelf;
use App\Models\Stand;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PlanogramSheetImportService
{
    public function __construct(
        private readonly PlanogramSheetOcrParser $parser,
        private readonly PlacementService $placements,
    ) {}

    /**
     * @param  array{store_id: string, equipment_type: string, equipment_id: string, ocr_text?: string|null}  $data
     */
    public function createFromUpload(User $user, array $data, ?UploadedFile $image = null): PlanogramSheetImport
    {
        $type = $data['equipment_type'];
        $equipmentId = $data['equipment_id'];
        $storeId = $data['store_id'];

        $store = Store::query()->findOrFail($storeId);
        $equipment = $this->resolveEquipment($type, $equipmentId);

        if ((string) $equipment->store_id !== (string) $storeId) {
            throw new InvalidArgumentException('Оборудование не принадлежит выбранному магазину.');
        }

        $imagePath = null;
        if ($image) {
            $imagePath = $image->store('planogram-sheets/'.$user->id, 'local');
        }

        $ocrText = trim((string) ($data['ocr_text'] ?? ''));

        if ($ocrText === '' && $imagePath) {
            $absolute = Storage::disk('local')->path($imagePath);
            $ocrText = (string) ($this->parser->recognizeImage($absolute) ?? '');
        }

        $import = PlanogramSheetImport::query()->create([
            'tenant_id' => $user->tenant_id ?? $store->tenant_id,
            'user_id' => $user->id,
            'store_id' => $storeId,
            'equipment_type' => $type,
            'equipment_id' => $equipmentId,
            'image_path' => $imagePath,
            'raw_ocr_text' => $ocrText !== '' ? $ocrText : null,
            'status' => PlanogramSheetImport::STATUS_DRAFT,
            'message' => $ocrText === ''
                ? 'OCR пуст: вставьте текст с листа вручную или установите tesseract (rus+eng).'
                : null,
        ]);

        if ($ocrText !== '') {
            $this->rebuildRowsFromOcr($import, $ocrText);
        }

        return $import->fresh(['rows.product']) ?? $import;
    }

    public function rebuildRowsFromOcr(PlanogramSheetImport $import, string $ocrText): PlanogramSheetImport
    {
        $parsed = $this->parser->parse($ocrText);
        $levels = $this->levelsMap($import->equipment_type, $import->equipment_id);

        DB::transaction(function () use ($import, $parsed, $levels, $ocrText): void {
            $import->rows()->delete();

            $matched = 0;
            foreach ($parsed as $index => $item) {
                $barcode = $item['barcode'];
                $product = Product::query()->where('barcode', $barcode)->first();
                $levelNumber = $item['level_number'];
                $levelId = $levelNumber !== null ? ($levels[$levelNumber] ?? null) : null;

                $status = PlanogramSheetImportRow::STATUS_OK;
                $note = null;

                if (! $product) {
                    $status = PlanogramSheetImportRow::STATUS_MISSING_PRODUCT;
                    $note = 'Штрихкод не найден в каталоге';
                } elseif ($levelNumber !== null && $levelId === null) {
                    $status = PlanogramSheetImportRow::STATUS_MISSING_LEVEL;
                    $note = "Нет уровня №{$levelNumber} у оборудования";
                } elseif ($levelNumber === null) {
                    // Allow manual level pick; still mark missing level until set.
                    $status = PlanogramSheetImportRow::STATUS_MISSING_LEVEL;
                    $note = 'Укажите номер полки вручную';
                } else {
                    $matched++;
                }

                PlanogramSheetImportRow::query()->create([
                    'import_id' => $import->id,
                    'sort_order' => $index + 1,
                    'level_number' => $levelNumber,
                    'barcode' => $barcode,
                    'product_name_ocr' => $item['product_name_ocr'],
                    'facings' => max(1, (int) $item['facings']),
                    'product_id' => $product?->id,
                    'level_id' => $levelId,
                    'status' => $status,
                    'note' => $note,
                ]);
            }

            $import->update([
                'raw_ocr_text' => $ocrText,
                'rows_total' => count($parsed),
                'rows_matched' => $matched,
                'status' => count($parsed) > 0
                    ? PlanogramSheetImport::STATUS_READY
                    : PlanogramSheetImport::STATUS_DRAFT,
                'message' => count($parsed) === 0
                    ? 'Не удалось распознать штрихкоды. Проверьте OCR-текст.'
                    : null,
            ]);
        });

        return $import->fresh(['rows.product']) ?? $import;
    }

    /**
     * @param  array{barcode?: string|null, product_name_ocr?: string|null, facings?: int|null, level_number?: int|null, status?: string|null}  $data
     */
    public function updateRow(PlanogramSheetImportRow $row, array $data): PlanogramSheetImportRow
    {
        $import = $row->import;
        $levels = $this->levelsMap($import->equipment_type, $import->equipment_id);

        if (array_key_exists('barcode', $data)) {
            $barcode = preg_replace('/\D+/', '', (string) ($data['barcode'] ?? '')) ?: null;
            $row->barcode = $barcode;
            $product = $barcode ? Product::query()->where('barcode', $barcode)->first() : null;
            $row->product_id = $product?->id;
        }

        if (array_key_exists('product_name_ocr', $data)) {
            $row->product_name_ocr = $data['product_name_ocr'] !== null
                ? mb_substr(trim((string) $data['product_name_ocr']), 0, 255)
                : null;
        }

        if (array_key_exists('facings', $data) && $data['facings'] !== null) {
            $row->facings = max(1, (int) $data['facings']);
        }

        if (array_key_exists('level_number', $data)) {
            $levelNumber = $data['level_number'] !== null && $data['level_number'] !== ''
                ? (int) $data['level_number']
                : null;
            $row->level_number = $levelNumber;
            $row->level_id = $levelNumber !== null ? ($levels[$levelNumber] ?? null) : null;
        }

        if (($data['status'] ?? null) === PlanogramSheetImportRow::STATUS_SKIPPED) {
            $row->status = PlanogramSheetImportRow::STATUS_SKIPPED;
            $row->note = 'Пропущено вручную';
        } else {
            $row->status = $this->resolveRowStatus($row);
            $row->note = match ($row->status) {
                PlanogramSheetImportRow::STATUS_MISSING_PRODUCT => 'Штрихкод не найден в каталоге',
                PlanogramSheetImportRow::STATUS_MISSING_LEVEL => $row->level_number
                    ? "Нет уровня №{$row->level_number} у оборудования"
                    : 'Укажите номер полки',
                PlanogramSheetImportRow::STATUS_OK => null,
                default => $row->note,
            };
        }

        $row->save();
        $this->refreshCounters($import->fresh() ?? $import);

        return $row->fresh(['product']) ?? $row;
    }

    public function commit(PlanogramSheetImport $import, bool $replaceExisting = false): PlanogramSheetImport
    {
        if ($import->status === PlanogramSheetImport::STATUS_COMMITTED) {
            throw new RuntimeException('Этот импорт уже применён.');
        }

        $equipment = $this->resolveEquipment($import->equipment_type, $import->equipment_id);
        $okRows = $import->rows()
            ->where('status', PlanogramSheetImportRow::STATUS_OK)
            ->orderBy('sort_order')
            ->get();

        if ($okRows->isEmpty()) {
            throw new RuntimeException('Нет строк со статусом OK для записи.');
        }

        $committed = 0;
        $failed = 0;

        DB::transaction(function () use ($import, $equipment, $okRows, $replaceExisting, &$committed, &$failed): void {
            if ($replaceExisting) {
                $this->clearEquipmentPlacements($import->equipment_type, $equipment);
            }

            foreach ($okRows as $row) {
                if (! $row->product_id || ! $row->level_id) {
                    continue;
                }

                try {
                    $this->placements->createForEquipment($import->equipment_type, $equipment, [
                        'product_id' => $row->product_id,
                        'level_id' => $row->level_id,
                        'facings' => max(1, (int) $row->facings),
                    ]);
                    $row->update([
                        'status' => PlanogramSheetImportRow::STATUS_COMMITTED,
                        'note' => null,
                    ]);
                    $committed++;
                } catch (Throwable $exception) {
                    $failed++;
                    $row->update([
                        'note' => mb_substr($exception->getMessage(), 0, 255),
                    ]);
                }
            }

            if ($committed === 0) {
                $import->update([
                    'rows_committed' => 0,
                    'status' => PlanogramSheetImport::STATUS_FAILED,
                    'message' => 'Не удалось записать ни одной строки. Исправьте ошибки и повторите.',
                ]);

                return;
            }

            $import->update([
                'rows_committed' => $committed,
                'status' => $failed > 0
                    ? PlanogramSheetImport::STATUS_READY
                    : PlanogramSheetImport::STATUS_COMMITTED,
                'message' => $failed > 0
                    ? "Частично записано: {$committed}, ошибок: {$failed}. Исправьте строки и повторите commit."
                    : "Записано placements: {$committed}",
            ]);
        });

        $fresh = $import->fresh(['rows.product']) ?? $import;

        if ($fresh->status === PlanogramSheetImport::STATUS_FAILED) {
            throw new RuntimeException($fresh->message ?: 'Не удалось записать ни одной строки планограммы.');
        }

        return $fresh;
    }

    /**
     * @return array<int, string> level_number => level uuid
     */
    public function levelsMap(string $type, string $equipmentId): array
    {
        $equipment = $this->resolveEquipment($type, $equipmentId);

        return $equipment->levels()
            ->get(['id', 'level_number'])
            ->mapWithKeys(fn ($level) => [(int) $level->level_number => (string) $level->id])
            ->all();
    }

    public function resolveEquipment(string $type, string $equipmentId): Shelf|Cooler|Stand
    {
        return match ($type) {
            'shelf' => Shelf::query()->with('levels')->findOrFail($equipmentId),
            'cooler' => Cooler::query()->with('levels')->findOrFail($equipmentId),
            'stand' => Stand::query()->with('levels')->findOrFail($equipmentId),
            default => throw new InvalidArgumentException('Неизвестный тип оборудования.'),
        };
    }

    private function resolveRowStatus(PlanogramSheetImportRow $row): string
    {
        if ($row->status === PlanogramSheetImportRow::STATUS_SKIPPED
            || $row->status === PlanogramSheetImportRow::STATUS_COMMITTED) {
            return $row->status;
        }

        if (! $row->product_id || ! $row->barcode) {
            return PlanogramSheetImportRow::STATUS_MISSING_PRODUCT;
        }

        if (! $row->level_id || ! $row->level_number) {
            return PlanogramSheetImportRow::STATUS_MISSING_LEVEL;
        }

        return PlanogramSheetImportRow::STATUS_OK;
    }

    private function refreshCounters(PlanogramSheetImport $import): void
    {
        $import->update([
            'rows_total' => $import->rows()->count(),
            'rows_matched' => $import->rows()->where('status', PlanogramSheetImportRow::STATUS_OK)->count(),
            'status' => PlanogramSheetImport::STATUS_READY,
        ]);
    }

    private function clearEquipmentPlacements(string $type, Shelf|Cooler|Stand $equipment): void
    {
        $levelIds = $equipment->levels()->pluck('id');

        if ($levelIds->isEmpty()) {
            return;
        }

        $column = match ($type) {
            'shelf' => 'shelf_level_id',
            'cooler' => 'cooler_shelf_level_id',
            'stand' => 'stand_shelf_level_id',
            default => throw new InvalidArgumentException('Неизвестный тип оборудования.'),
        };

        Placement::query()->whereIn($column, $levelIds)->delete();
    }
}
