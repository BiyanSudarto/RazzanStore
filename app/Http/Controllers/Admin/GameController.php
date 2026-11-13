<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GameController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Game::query()
                ->withCount(['fields', 'products'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate(25),
        ]);
    }

    public function store(Request $request, AdminAuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:games,slug'],
            'name' => ['required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image_path' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);

        $game = DB::transaction(function () use ($data, $audit): Game {
            $game = Game::create($data);

            $audit->record('game.created', $game, null, $game->only([
                'slug', 'name', 'is_active', 'sort_order',
            ]));

            return $game;
        });

        return response()->json(['data' => $game], 201);
    }

    public function show(Game $game): JsonResponse
    {
        $game->load(['fields', 'products']);

        if (! auth('admin')->user()?->hasRole('super_admin')) {
            $game->setRelation('products', $game->products->each(
                fn ($product) => $product->makeHidden(['base_cost', 'provider_sku', 'metadata']),
            ));
        }

        return response()->json(['data' => $game]);
    }

    public function update(Request $request, Game $game, AdminAuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('games', 'slug')->ignore($game->id)],
            'name' => ['sometimes', 'required', 'string', 'max:140'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);

        $updated = DB::transaction(function () use ($game, $data, $audit): Game {
            $locked = Game::query()->lockForUpdate()->findOrFail($game->id);
            $before = $locked->only(array_keys($data));
            $locked->fill($data)->save();
            $locked->refresh();

            $audit->record('game.updated', $locked, $before, $locked->only(array_keys($data)));

            return $locked;
        }, 3);

        return response()->json(['data' => $updated]);
    }

    public function destroy(Game $game, AdminAuditLogger $audit): JsonResponse
    {
        $deleted = DB::transaction(function () use ($game, $audit): bool {
            $locked = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($locked->products()->exists()) {
                return false;
            }

            $before = $locked->only(['slug', 'name', 'is_active']);
            $audit->record('game.deleted', $locked, $before, null);
            $locked->delete();

            return true;
        }, 3);

        if (! $deleted) {
            return response()->json([
                'message' => 'Game yang sudah memiliki produk tidak dapat dihapus. Nonaktifkan game sebagai gantinya.',
            ], 409);
        }

        return response()->json(['message' => 'Game berhasil dihapus.']);
    }
}
