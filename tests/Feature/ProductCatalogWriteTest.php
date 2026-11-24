<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
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
class ProductCatalogWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('PostgreSQL testing database is not available.');
        }

        // Prefer Artisan::call over $this->seed() / RefreshDatabase — those need Mockery.
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', [
            '--class' => RolesAndPermissionsSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    public function test_tenant_admin_cannot_update_global_product(): void
    {
        [$user] = $this->tenantAdminWithSubscription();
        $product = Product::factory()->create([
            'owner_tenant_id' => null,
            'name' => 'Global SKU',
        ]);

        $response = $this->actingAs($user)->put(route('products.update', $product->id), [
            'barcode' => $product->barcode,
            'name' => 'Hacked name',
            'category' => $product->category,
            'volume_ml' => $product->volume_ml,
            'package_type' => $product->package_type,
            'width_mm' => $product->width_mm,
            'height_mm' => $product->height_mm,
            'depth_mm' => $product->depth_mm,
            'weight_g' => $product->weight_g,
            'checked' => false,
            'is_private' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('Global SKU', $product->fresh()->name);
    }

    public function test_tenant_admin_create_is_private_even_without_is_private_flag(): void
    {
        [$user, $tenant] = $this->tenantAdminWithSubscription();

        $barcode = '4607001234567';

        $response = $this->actingAs($user)->post(route('products.store'), [
            'barcode' => $barcode,
            'name' => 'Tenant SKU',
            'category' => 'Напитки',
            'volume_ml' => 500,
            'package_type' => 'ПЭТ',
            'width_mm' => 60,
            'height_mm' => 200,
            'depth_mm' => 60,
            'weight_g' => 500,
            'checked' => false,
            'is_private' => false,
        ]);

        $response->assertRedirect(route('products.index'));

        $created = Product::query()->where('barcode', $barcode)->first();
        $this->assertNotNull($created);
        $this->assertSame($tenant->id, $created->owner_tenant_id);
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
