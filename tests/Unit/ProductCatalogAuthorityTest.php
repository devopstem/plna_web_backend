<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Models\User;
use App\Support\ProductCatalogAuthority;
use PHPUnit\Framework\TestCase;

class ProductCatalogAuthorityTest extends TestCase
{
    public function test_tenant_cannot_mutate_global_product(): void
    {
        $product = new Product(['owner_tenant_id' => null]);
        $user = $this->stubUser(tenantId: 'tenant-a', superAdmin: false);

        $this->assertNotNull(ProductCatalogAuthority::denyReason($product, $user));
        $this->assertFalse(ProductCatalogAuthority::canMutate($product, $user));
    }

    public function test_super_admin_can_mutate_global_product(): void
    {
        $product = new Product(['owner_tenant_id' => null]);
        $user = $this->stubUser(tenantId: null, superAdmin: true);

        $this->assertNull(ProductCatalogAuthority::denyReason($product, $user));
        $this->assertTrue(ProductCatalogAuthority::canMutate($product, $user));
    }

    public function test_tenant_can_mutate_own_private_product(): void
    {
        $product = new Product(['owner_tenant_id' => 'tenant-a']);
        $user = $this->stubUser(tenantId: 'tenant-a', superAdmin: false);

        $this->assertNull(ProductCatalogAuthority::denyReason($product, $user));
    }

    public function test_tenant_cannot_mutate_other_private_product(): void
    {
        $product = new Product(['owner_tenant_id' => 'tenant-b']);
        $user = $this->stubUser(tenantId: 'tenant-a', superAdmin: false);

        $this->assertNotNull(ProductCatalogAuthority::denyReason($product, $user, 'удалить'));
    }

    private function stubUser(?string $tenantId, bool $superAdmin): User
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSuperAdmin'])
            ->getMock();

        $user->method('isSuperAdmin')->willReturn($superAdmin);
        $user->tenant_id = $tenantId;

        return $user;
    }
}
