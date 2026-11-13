<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with('game:id,slug,name')
            ->when($request->filled('game_id'), fn ($query) => $query->where('game_id', $request->integer('game_id')))
            ->when($request->filled('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('game_id')
            ->orderBy('sort_order')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json(['data' => $products]);
    }

    public function store(Request $request, AdminAuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'sku' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:products,sku'],
            'provider_sku' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'nominal_label' => ['nullable', 'string', 'max:100'],
            'base_cost' => ['required', 'integer', 'min:0', 'max:2000000000000'],
            'selling_price' => ['required', 'integer', 'min:0', 'max:2000000000000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $product = DB::transaction(function () use ($data, $audit): Product {
            $product = Product::create($data);

            PriceHistory::create([
                'product_id' => $product->id,
                'changed_by_admin_id' => auth('admin')->id(),
                'old_base_cost' => null,
                'new_base_cost' => $product->base_cost,
                'old_selling_price' => null,
                'new_selling_price' => $product->selling_price,
                'reason' => 'Initial product price',
                'created_at' => now(),
            ]);

            $audit->record('product.created', $product, null, $product->only([
                'game_id', 'sku', 'name', 'base_cost', 'selling_price', 'is_active',
            ]));

            return $product;
        });

        return response()->json(['data' => $product->load('game:id,slug,name')], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'data' => $product->load('game:id,slug,name', 'priceHistories.changedBy:id,name,email'),
        ]);
    }

    public function update(Request $request, Product $product, AdminAuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('products', 'sku')->ignore($product->id)],
            'provider_sku' => ['sometimes', 'nullable', 'string', 'max:120'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nominal_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $before = $product->only(array_keys($data));
        $product->fill($data)->save();
        $product->refresh();
        $audit->record('product.updated', $product, $before, $product->only(array_keys($data)));

        return response()->json(['data' => $product]);
    }

    public function changePrice(Request $request, Product $product, AdminAuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'base_cost' => ['required', 'integer', 'min:0', 'max:2000000000000'],
            'selling_price' => ['required', 'integer', 'min:0', 'max:2000000000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = DB::transaction(function () use ($product, $data, $audit): Product {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $before = $locked->only(['base_cost', 'selling_price']);

            if ($locked->base_cost === $data['base_cost'] && $locked->selling_price === $data['selling_price']) {
                abort(422, 'Harga tidak berubah.');
            }

            $locked->update([
                'base_cost' => $data['base_cost'],
                'selling_price' => $data['selling_price'],
            ]);

            PriceHistory::create([
                'product_id' => $locked->id,
                'changed_by_admin_id' => auth('admin')->id(),
                'old_base_cost' => $before['base_cost'],
                'new_base_cost' => $locked->base_cost,
                'old_selling_price' => $before['selling_price'],
                'new_selling_price' => $locked->selling_price,
                'reason' => $data['reason'] ?? null,
                'created_at' => now(),
            ]);

            $audit->record('product.price_changed', $locked, $before, $locked->only([
                'base_cost', 'selling_price',
            ]));

            return $locked->refresh();
        }, 3);

        return response()->json(['data' => $updated]);
    }

    public function deactivate(Product $product, AdminAuditLogger $audit): JsonResponse
    {
        $before = $product->only(['is_active']);
        $product->update(['is_active' => false]);
        $audit->record('product.deactivated', $product, $before, ['is_active' => false]);

        return response()->json(['data' => $product->refresh()]);
    }
}
