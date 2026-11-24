<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\User;

/**
 * Write rules for the shared product catalog (owner_tenant_id null = global).
 */
final class ProductCatalogAuthority
{
    public static function canMutate(Product $product, ?User $user): bool
    {
        return self::denyReason($product, $user) === null;
    }

    /**
     * Human-readable deny reason, or null if mutation is allowed.
     */
    public static function denyReason(Product $product, ?User $user, string $verb = 'изменить'): ?string
    {
        if ($product->isGlobal() && ! $user?->isSuperAdmin()) {
            return "Глобальный товар каталога может {$verb} только Super Admin.";
        }

        if (
            $product->owner_tenant_id
            && $user
            && ! $user->isSuperAdmin()
            && $product->owner_tenant_id !== $user->tenant_id
        ) {
            return "Нельзя {$verb} чужой приватный товар.";
        }

        return null;
    }

    /**
     * Abort with 403 when the actor may not mutate the product.
     */
    public static function assertCanMutate(Product $product, ?User $user, string $verb = 'изменить'): void
    {
        $reason = self::denyReason($product, $user, $verb);

        if ($reason !== null) {
            abort(403, $reason);
        }
    }

    /**
     * Non–Super Admin always owns private SKUs; only Super Admin may create globals.
     */
    public static function resolveOwnerTenantIdForCreate(?User $user, bool $wantsPrivate): ?string
    {
        if ($user?->isSuperAdmin()) {
            if (! $wantsPrivate) {
                return null;
            }

            abort_unless($user->tenant_id !== null, 422, 'Для приватного товара войдите под арендатором или укажите владельца.');

            return $user->tenant_id;
        }

        abort_unless($user?->tenant_id !== null, 403, 'Нельзя создать товар без арендатора.');

        // Tenant actors always create private SKUs (cannot pollute the global catalog).
        return $user->tenant_id;
    }
}
