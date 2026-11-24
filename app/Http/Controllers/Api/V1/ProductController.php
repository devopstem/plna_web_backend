<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AuthorizesResourcePermissions;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Permissions;
use App\Support\ProductBarcode;
use App\Support\ProductCatalogAuthority;
use App\Support\ProductCatalogSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class ProductController extends Controller implements HasMiddleware
{
    use AuthorizesResourcePermissions;

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return self::resourcePermissionMiddleware(
            Permissions::VIEW_PRODUCTS,
            Permissions::CREATE_PRODUCTS,
            Permissions::EDIT_PRODUCTS,
            Permissions::DELETE_PRODUCTS,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->tap(fn ($query) => ProductCatalogSearch::apply($query, $request->string('search')->toString()))
            ->orderBy('name')
            ->paginate(20);

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => [
                ...ProductBarcode::rules(),
                Rule::unique('products', 'barcode')->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'volume_ml' => ['nullable', 'integer', 'min:0'],
            'package_type' => ['nullable', 'string', 'max:255'],
            'width_mm' => ['nullable', 'integer', 'min:0'],
            'height_mm' => ['nullable', 'integer', 'min:0'],
            'depth_mm' => ['nullable', 'integer', 'min:0'],
            'weight_g' => ['nullable', 'integer', 'min:0'],
            'checked' => ['sometimes', 'boolean'],
            'is_private' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $isPrivate = (bool) ($validated['is_private'] ?? false);
        unset($validated['is_private']);

        $product = Product::query()->create([
            ...$validated,
            'checked' => (bool) ($validated['checked'] ?? false),
            'owner_tenant_id' => ProductCatalogAuthority::resolveOwnerTenantIdForCreate($user, $isPrivate),
        ]);

        return response()->json($product, Response::HTTP_CREATED);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($product);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        ProductCatalogAuthority::assertCanMutate($product, $request->user());

        $validated = $request->validate([
            'barcode' => [
                ...ProductBarcode::rules(required: false),
                Rule::unique('products', 'barcode')
                    ->whereNull('deleted_at')
                    ->ignore($product->id),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'volume_ml' => ['nullable', 'integer', 'min:0'],
            'package_type' => ['nullable', 'string', 'max:255'],
            'width_mm' => ['nullable', 'integer', 'min:0'],
            'height_mm' => ['nullable', 'integer', 'min:0'],
            'depth_mm' => ['nullable', 'integer', 'min:0'],
            'weight_g' => ['nullable', 'integer', 'min:0'],
            'checked' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('checked', $validated)) {
            $validated['checked'] = (bool) $validated['checked'];
        }

        $product->update($validated);

        return response()->json($product);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        ProductCatalogAuthority::assertCanMutate($product, $request->user(), 'удалить');

        $product->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
