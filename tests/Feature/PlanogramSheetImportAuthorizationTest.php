<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlanogramSheetImport;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * Requires a working testing database (see phpunit.xml).
 * Uses Artisan::call instead of RefreshDatabase to avoid Mockery (not in require-dev).
 */
class PlanogramSheetImportAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('PostgreSQL testing database is not available.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_tenant_cannot_view_other_tenant_sheet_import(): void
    {
        [$user] = $this->tenantAdminWithSubscription();

        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $otherStore = Store::factory()->create(['tenant_id' => $otherTenant->id]);

        $import = PlanogramSheetImport::query()->create([
            'tenant_id' => $otherTenant->id,
            'user_id' => $user->id,
            'store_id' => $otherStore->id,
            'equipment_type' => 'shelf',
            'equipment_id' => fake()->uuid(),
            'status' => PlanogramSheetImport::STATUS_DRAFT,
            'rows_total' => 0,
            'rows_matched' => 0,
            'rows_committed' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('planograms.sheet-import.show', $import->id))
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function tenantAdminWithSubscription(): array
    {
        $tenant = Tenant::factory()->create([
            'is_active' => true,
            'subscription_until' => now()->addMonth(),
        ]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => Subscription::STATUS_ACTIVE,
            'ends_at' => now()->addMonth(),
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
        $user->assignRole(Permissions::ROLE_DEPUTY);

        return [$user, $tenant];
    }
}
