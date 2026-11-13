<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function games(): JsonResponse
    {
        $games = Game::query()
            ->where('is_active', true)
            ->with([
                'fields' => fn ($query) => $query->select([
                    'id', 'game_id', 'field_key', 'label', 'input_type',
                    'is_required', 'options', 'sort_order',
                ])->orderBy('sort_order'),
                'products' => fn ($query) => $query->where('is_active', true)
                    ->select([
                        'id', 'game_id', 'sku', 'name', 'description',
                        'nominal_label', 'selling_price', 'currency', 'sort_order',
                    ])->orderBy('sort_order'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'description', 'image_path', 'sort_order']);

        return response()->json(['data' => $games]);
    }

    public function game(string $slug): JsonResponse
    {
        $game = Game::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'fields' => fn ($query) => $query->orderBy('sort_order'),
                'products' => fn ($query) => $query->where('is_active', true)
                    ->orderBy('sort_order'),
            ])
            ->firstOrFail();

        $game->setRelation('products', $game->products->map(fn ($product) => $product->makeHidden([
            'base_cost', 'provider_sku', 'metadata',
        ])));

        return response()->json(['data' => $game]);
    }
}
