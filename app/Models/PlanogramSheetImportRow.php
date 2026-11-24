<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanogramSheetImportRow extends Model
{
    use HasUuids;

    public const STATUS_OK = 'ok';

    public const STATUS_MISSING_PRODUCT = 'missing_product';

    public const STATUS_MISSING_LEVEL = 'missing_level';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_COMMITTED = 'committed';

    protected $fillable = [
        'import_id',
        'sort_order',
        'level_number',
        'barcode',
        'product_name_ocr',
        'facings',
        'product_id',
        'level_id',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'level_number' => 'integer',
            'facings' => 'integer',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PlanogramSheetImport::class, 'import_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
