<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanogramSheetImport extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'store_id',
        'equipment_type',
        'equipment_id',
        'image_path',
        'raw_ocr_text',
        'status',
        'rows_total',
        'rows_matched',
        'rows_committed',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'rows_total' => 'integer',
            'rows_matched' => 'integer',
            'rows_committed' => 'integer',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PlanogramSheetImportRow::class, 'import_id')->orderBy('sort_order');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
