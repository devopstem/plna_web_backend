<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Store;
use Illuminate\Support\Facades\Auth;

trait EnsuresStoreAccess
{
    /**
     * Resolve store visible to the current actor (tenant + department scopes apply).
     */
    protected function findAccessibleStore(string $storeId): Store
    {
        return Store::query()->findOrFail($storeId);
    }

    /**
     * Read / export access: Tenant Admin & Programmer — own tenant; Store Manager — own store.
     */
    protected function assertCanAccessStore(Store $store): void
    {
        $user = Auth::user();

        if (! $user || $user->isSuperAdmin()) {
            return;
        }

        if ($user->isTenantAdmin() || $user->isIntegrator()) {
            abort_unless(
                $user->tenant_id !== null && (string) $store->tenant_id === (string) $user->tenant_id,
                403,
                'Нет доступа к магазину другого арендатора.',
            );

            return;
        }

        if ($user->isDepartmentHead()) {
            $this->assertHeadOwnsStore($store);

            return;
        }

        abort(403, 'Недостаточно прав для доступа к магазину.');
    }

    /**
     * Mutate store / floor plan: Tenant Admin — own tenant; Store Manager — own store only.
     * Programmer cannot edit stores.
     */
    protected function assertCanModifyStore(Store $store): void
    {
        $user = Auth::user();

        if (! $user || $user->isSuperAdmin()) {
            return;
        }

        if ($user->isTenantAdmin()) {
            abort_unless(
                $user->tenant_id !== null && (string) $store->tenant_id === (string) $user->tenant_id,
                403,
                'Нет доступа к магазину другого арендатора.',
            );

            return;
        }

        if ($user->isDepartmentHead()) {
            $this->assertHeadOwnsStore($store);

            return;
        }

        abort(403, 'Недостаточно прав для изменения магазина.');
    }

    private function assertHeadOwnsStore(Store $store): void
    {
        $user = Auth::user();
        $ownedStoreId = $user?->ownedStoreId();

        abort_unless(
            $ownedStoreId !== null && (string) $ownedStoreId === (string) $store->id,
            403,
            'Заведующий может работать только со своим магазином.',
        );
    }
}
