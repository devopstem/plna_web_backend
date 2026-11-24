<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Fast product lookup for 5k+ catalogs: barcode prefix uses btree;
 * name uses ILIKE (backed by pg_trgm GIN when available).
 */
final class ProductCatalogSearch
{
    public static function apply(Builder $query, ?string $raw): void
    {
        $term = trim((string) $raw);

        if ($term === '') {
            return;
        }

        // Digits → barcode prefix (products_barcode_index).
        if (preg_match('/^\d+$/', $term) === 1) {
            $query->where('barcode', 'like', $term.'%');

            return;
        }

        $prefix = $term.'%';
        $contains = '%'.$term.'%';

        $query->where(function (Builder $builder) use ($prefix, $contains): void {
            $builder
                ->where('name', 'ilike', $prefix)
                ->orWhere('name', 'ilike', $contains)
                ->orWhere('barcode', 'like', $prefix);
        });
    }
}
