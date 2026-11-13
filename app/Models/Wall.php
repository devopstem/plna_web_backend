<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RestrictsToOwnDepartment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Wall extends Model
{
    use HasUuids;
    use RestrictsToOwnDepartment;

    public const TYPES = [
        'wall' => 'Стена',
        'door' => 'Дверь',
        'window' => 'Окно',
    ];

    protected $fillable = [
        'store_id',
        'start_x',
        'start_y',
        'end_x',
        'end_y',
        'wall_type',
    ];

    protected function casts(): array
    {
        return [
            'start_x' => 'decimal:2',
            'start_y' => 'decimal:2',
            'end_x' => 'decimal:2',
            'end_y' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $user = Auth::user();

            if (! $user) {
                return;
            }

            if ($user->isSuperAdmin()) {
                return;
            }

            if ($user->tenant_id === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->whereHas('store', function (Builder $query) use ($user): void {
                $query->where('tenant_id', $user->tenant_id);
            });

            static::applyOwnDepartmentScope(
                $builder,
                $user,
                function (Builder $query, string $ignoredDepartmentId) use ($user): void {
                    $storeId = $user->ownedStoreId();

                    if ($storeId === null) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query->where($query->getModel()->qualifyColumn('store_id'), $storeId);
                },
            );
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
