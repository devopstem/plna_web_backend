<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TenantRequest;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
    ) {}

    public function index(Request $request): Response
    {
        $this->assertPlatformAdmin();

        $status = $request->string('status')->toString();
        $search = $request->string('search')->toString();

        $tenants = $this->tenants
            ->paginate(
                in_array($status, ['active', 'blocked'], true) ? $status : null,
                $search !== '' ? $search : null,
            )
            ->through(fn (Tenant $tenant) => $this->tenants->toListItem($tenant));

        return Inertia::render('Tenants/Index', [
            'tenants' => $tenants,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'plans' => $this->tenants->planOptions(),
        ]);
    }

    public function create(): Response
    {
        $this->assertPlatformAdmin();

        return Inertia::render('Tenants/Create', [
            'plans' => $this->tenants->planOptions(),
        ]);
    }

    public function store(TenantRequest $request): RedirectResponse
    {
        $this->assertPlatformAdmin();

        $tenant = $this->tenants->create($request->tenantAttributes());

        return redirect()
            ->route('tenants.show', $tenant->id)
            ->with('success', 'Арендатор создан.');
    }

    public function show(string $tenant): Response
    {
        $model = Tenant::query()->findOrFail($tenant);
        $this->assertCanAccessTenant($model);

        return Inertia::render('Tenants/Show', [
            'tenant' => $this->tenants->toDetails($model),
        ]);
    }

    public function edit(string $tenant): Response
    {
        $model = Tenant::query()->findOrFail($tenant);
        $this->assertCanAccessTenant($model);

        return Inertia::render('Tenants/Edit', [
            'tenant' => $this->tenants->toSummary($model),
            'plans' => $this->tenants->planOptions(),
        ]);
    }

    public function update(TenantRequest $request, string $tenant): RedirectResponse
    {
        $model = Tenant::query()->findOrFail($tenant);
        $this->assertCanAccessTenant($model);

        // Tenant Admin may edit own tenant profile, but not plan/activation/quotas.
        if (! Auth::user()?->isSuperAdmin()) {
            $attrs = $request->tenantAttributes();
            $this->tenants->update($model, [
                'name' => $attrs['name'],
                'domain' => $attrs['domain'],
            ]);
        } else {
            $this->tenants->update($model, $request->tenantAttributes());
        }

        return redirect()
            ->route('tenants.show', $model->id)
            ->with('success', 'Арендатор обновлён.');
    }

    public function toggleActive(string $tenant): RedirectResponse
    {
        $this->assertPlatformAdmin();

        $model = Tenant::query()->findOrFail($tenant);
        $this->tenants->toggleActive($model);

        return back()->with(
            'success',
            $model->is_active ? 'Арендатор разблокирован.' : 'Арендатор заблокирован.',
        );
    }

    private function assertPlatformAdmin(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin() === true, 403, 'Только Super Admin.');
    }

    private function assertCanAccessTenant(Tenant $tenant): void
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin()) {
            return;
        }

        abort_unless(
            $user?->isTenantAdmin() === true
                && $user->tenant_id !== null
                && (string) $user->tenant_id === (string) $tenant->id,
            403,
            'Нет доступа к этому арендатору.',
        );
    }
}
