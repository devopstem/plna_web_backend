<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresStoreAccess;
use App\Models\Cooler;
use App\Models\Department;
use App\Models\PlanogramSheetImport;
use App\Models\PlanogramSheetImportRow;
use App\Models\Shelf;
use App\Models\Stand;
use App\Models\Store;
use App\Services\PlanogramSheetImportService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PlanogramSheetImportController extends Controller implements HasMiddleware
{
    use EnsuresStoreAccess;

    public function __construct(
        private readonly PlanogramSheetImportService $imports,
    ) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:'.Permissions::VIEW_PLANOGRAMS, only: ['create', 'show', 'image']),
            new Middleware(
                'permission:'.Permissions::CREATE_PLANOGRAMS.'|'.Permissions::EDIT_PLANOGRAMS.'|'.Permissions::MANAGE_PLANOGRAMS,
                only: ['store', 'reparse', 'updateRow', 'commit'],
            ),
        ];
    }

    public function create(Request $request): Response
    {
        $stores = $this->storesForSelect();
        $storeId = $request->string('store_id')->toString();

        if ($storeId === '' && $stores !== []) {
            $storeId = $stores[0]['id'];
        }

        if ($storeId !== '') {
            $this->assertCanAccessStore($this->findAccessibleStore($storeId));
        }

        return Inertia::render('Planograms/SheetImport', [
            'stores' => $stores,
            'tree' => $this->buildDepartmentTree($storeId),
            'filters' => [
                'store_id' => $storeId,
                'shelf_id' => $request->string('shelf_id')->toString(),
                'cooler_id' => $request->string('cooler_id')->toString(),
                'stand_id' => $request->string('stand_id')->toString(),
            ],
            'tesseractAvailable' => $this->tesseractAvailable(),
            'import' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'uuid', 'exists:stores,id'],
            'equipment_type' => ['required', Rule::in(['shelf', 'cooler', 'stand'])],
            'equipment_id' => ['required', 'uuid'],
            'ocr_text' => ['nullable', 'string', 'max:500000'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
        ], [
            'image.max' => 'Фото листа — максимум 20 МБ.',
        ]);

        abort_unless(
            $request->hasFile('image') || filled($validated['ocr_text'] ?? null),
            422,
            'Загрузите фото листа и/или вставьте OCR-текст.',
        );

        $user = Auth::user();
        abort_unless($user !== null, 403);

        $store = $this->findAccessibleStore($validated['store_id']);
        $this->assertCanModifyStore($store);

        try {
            $import = $this->imports->createFromUpload(
                $user,
                [
                    'store_id' => $validated['store_id'],
                    'equipment_type' => $validated['equipment_type'],
                    'equipment_id' => $validated['equipment_id'],
                    'ocr_text' => $validated['ocr_text'] ?? null,
                ],
                $request->file('image'),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Planogram sheet import failed', [
                'message' => $exception->getMessage(),
            ]);

            return back()->with('error', 'Не удалось обработать лист. Проверьте оборудование и OCR-текст.')->withInput();
        }

        return redirect()
            ->route('planograms.sheet-import.show', $import->id)
            ->with('success', 'Лист загружен. Проверьте строки перед записью.');
    }

    public function show(string $import): Response
    {
        $model = $this->findAuthorizedImport($import, modify: false);

        $equipment = $this->imports->resolveEquipment($model->equipment_type, $model->equipment_id);
        $levels = $equipment->levels()
            ->orderBy('level_number')
            ->get(['id', 'level_number'])
            ->map(fn ($level) => [
                'id' => $level->id,
                'level_number' => (int) $level->level_number,
            ])
            ->values()
            ->all();

        $imageUrl = null;
        if ($model->image_path && Storage::disk('local')->exists($model->image_path)) {
            $absolute = Storage::disk('local')->path($model->image_path);
            $mime = mime_content_type($absolute) ?: 'image/jpeg';
            if (! str_contains($mime, 'pdf')) {
                $imageUrl = route('planograms.sheet-import.image', $model->id);
            }
        }

        return Inertia::render('Planograms/SheetImport', [
            'stores' => $this->storesForSelect(),
            'tree' => $this->buildDepartmentTree($model->store_id),
            'filters' => [
                'store_id' => $model->store_id,
                'shelf_id' => $model->equipment_type === 'shelf' ? $model->equipment_id : '',
                'cooler_id' => $model->equipment_type === 'cooler' ? $model->equipment_id : '',
                'stand_id' => $model->equipment_type === 'stand' ? $model->equipment_id : '',
            ],
            'tesseractAvailable' => $this->tesseractAvailable(),
            'import' => [
                'id' => $model->id,
                'status' => $model->status,
                'message' => $model->message,
                'rows_total' => $model->rows_total,
                'rows_matched' => $model->rows_matched,
                'rows_committed' => $model->rows_committed,
                'raw_ocr_text' => $model->raw_ocr_text,
                'equipment_type' => $model->equipment_type,
                'equipment_id' => $model->equipment_id,
                'equipment_label' => $this->equipmentLabel($model->equipment_type, $equipment),
                'store_name' => is_array($model->store?->name)
                    ? ($model->store->name['ru'] ?? $model->store->name['en'] ?? '')
                    : (string) ($model->store?->name ?? ''),
                'image_url' => $imageUrl,
                'levels' => $levels,
                'rows' => $model->rows->map(fn (PlanogramSheetImportRow $row) => [
                    'id' => $row->id,
                    'sort_order' => $row->sort_order,
                    'level_number' => $row->level_number,
                    'barcode' => $row->barcode,
                    'product_name_ocr' => $row->product_name_ocr,
                    'facings' => $row->facings,
                    'product_id' => $row->product_id,
                    'product_name' => $row->product?->name,
                    'status' => $row->status,
                    'note' => $row->note,
                ])->values()->all(),
            ],
        ]);
    }

    public function image(string $import): StreamedResponse
    {
        $model = $this->findAuthorizedImport($import, modify: false);

        abort_unless(
            $model->image_path && Storage::disk('local')->exists($model->image_path),
            404,
        );

        $absolute = Storage::disk('local')->path($model->image_path);
        $mime = mime_content_type($absolute) ?: 'image/jpeg';

        abort_if(str_contains($mime, 'pdf'), 404);

        return response()->stream(static function () use ($absolute): void {
            $stream = fopen($absolute, 'rb');
            if ($stream === false) {
                return;
            }

            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function reparse(Request $request, string $import): RedirectResponse
    {
        $model = $this->findAuthorizedImport($import, modify: true);

        $validated = $request->validate([
            'ocr_text' => ['required', 'string', 'max:500000'],
        ]);

        try {
            $this->imports->rebuildRowsFromOcr($model, $validated['ocr_text']);
        } catch (Throwable $exception) {
            Log::warning('Planogram sheet reparse failed', [
                'import_id' => $model->id,
                'message' => $exception->getMessage(),
            ]);

            return back()->with('error', 'Не удалось пересобрать строки из OCR-текста.');
        }

        return back()->with('success', 'Строки пересобраны из OCR-текста.');
    }

    public function updateRow(Request $request, string $row): RedirectResponse
    {
        $rowModel = PlanogramSheetImportRow::query()->with('import')->findOrFail($row);
        abort_unless($rowModel->import !== null, 404);

        $this->assertImportStoreAccess($rowModel->import, modify: true);

        $validated = $request->validate([
            'barcode' => ['nullable', 'string', 'max:14'],
            'product_name_ocr' => ['nullable', 'string', 'max:255'],
            'facings' => ['nullable', 'integer', 'min:1', 'max:99'],
            'level_number' => ['nullable', 'integer', 'min:1', 'max:99'],
            'status' => ['nullable', Rule::in([
                PlanogramSheetImportRow::STATUS_SKIPPED,
                PlanogramSheetImportRow::STATUS_OK,
            ])],
        ]);

        $this->imports->updateRow($rowModel, $validated);

        return back()->with('success', 'Строка обновлена.');
    }

    public function commit(Request $request, string $import): RedirectResponse
    {
        $model = $this->findAuthorizedImport($import, modify: true);

        $validated = $request->validate([
            'replace_existing' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $this->imports->commit($model, (bool) ($validated['replace_existing'] ?? false));
        } catch (Throwable $exception) {
            Log::warning('Planogram sheet commit failed', [
                'import_id' => $model->id,
                'message' => $exception->getMessage(),
            ]);

            return back()->with('error', $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Не удалось записать планограмму из листа.');
        }

        if ($result->status !== PlanogramSheetImport::STATUS_COMMITTED) {
            return redirect()
                ->route('planograms.sheet-import.show', $result->id)
                ->with('error', $result->message ?? 'Импорт записан частично. Исправьте строки и повторите.');
        }

        $params = ['store_id' => $result->store_id];
        $params[$result->equipment_type.'_id'] = $result->equipment_id;

        return redirect()
            ->route('planograms.index', $params)
            ->with('success', $result->message ?? 'Планограмма обновлена из листа.');
    }

    private function findAuthorizedImport(string $importId, bool $modify): PlanogramSheetImport
    {
        $model = PlanogramSheetImport::query()
            ->with(['rows.product:id,barcode,name,width_mm', 'store:id,name,city,tenant_id'])
            ->findOrFail($importId);

        $this->assertImportStoreAccess($model, $modify);

        return $model;
    }

    private function assertImportStoreAccess(PlanogramSheetImport $import, bool $modify): void
    {
        $store = $import->store ?? Store::query()->findOrFail($import->store_id);

        if ($modify) {
            $this->assertCanModifyStore($store);
        } else {
            $this->assertCanAccessStore($store);
        }
    }

    /**
     * @return list<array{id: string, name: string, city: string|null}>
     */
    private function storesForSelect(): array
    {
        return Store::query()
            ->orderBy('city')
            ->get(['id', 'name', 'city'])
            ->map(function (Store $store) {
                $name = is_array($store->name)
                    ? ($store->name['ru'] ?? $store->name['en'] ?? '')
                    : (string) $store->name;

                return [
                    'id' => $store->id,
                    'name' => $name,
                    'city' => $store->city,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildDepartmentTree(string $storeId): array
    {
        if ($storeId === '') {
            return [];
        }

        $departments = Department::query()
            ->where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'color', 'store_id']);

        $shelves = Shelf::query()
            ->where('store_id', $storeId)
            ->withCount('levels')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'department_id', 'store_id']);

        $coolers = Cooler::query()
            ->where('store_id', $storeId)
            ->withCount('levels')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'department_id', 'store_id']);

        $stands = Stand::query()
            ->where('store_id', $storeId)
            ->withCount('levels')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'department_id', 'store_id']);

        return $departments->map(function (Department $department) use ($shelves, $coolers, $stands) {
            return [
                'id' => $department->id,
                'name' => $department->name,
                'code' => $department->code,
                'color' => $department->color,
                'shelves' => $shelves->where('department_id', $department->id)->values()->map(fn (Shelf $s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'name' => is_array($s->name) ? ($s->name['ru'] ?? '') : (string) $s->name,
                    'levels_count' => (int) $s->levels_count,
                ])->all(),
                'coolers' => $coolers->where('department_id', $department->id)->values()->map(fn (Cooler $c) => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => is_array($c->name) ? ($c->name['ru'] ?? '') : (string) $c->name,
                    'levels_count' => (int) $c->levels_count,
                ])->all(),
                'stands' => $stands->where('department_id', $department->id)->values()->map(fn (Stand $s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'name' => is_array($s->name) ? ($s->name['ru'] ?? '') : (string) $s->name,
                    'levels_count' => (int) $s->levels_count,
                ])->all(),
            ];
        })->values()->all();
    }

    private function equipmentLabel(string $type, Shelf|Cooler|Stand $equipment): string
    {
        $prefix = match ($type) {
            'shelf' => 'Стеллаж',
            'cooler' => 'Холодильник',
            'stand' => 'Стойка',
            default => 'Оборудование',
        };

        return $prefix.' · '.$equipment->code;
    }

    private function tesseractAvailable(): bool
    {
        return (bool) Cache::remember('planogram.tesseract_available', 600, static function (): bool {
            foreach (['/usr/bin/tesseract', '/usr/local/bin/tesseract', '/bin/tesseract'] as $path) {
                if (is_executable($path)) {
                    return true;
                }
            }

            return false;
        });
    }
}
