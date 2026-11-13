<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $game = Game::create($data);
        $audit->record('game.created', $game, null, $game->only(['slug', 'name', 'is_active', 'sort_order']));

        return response()->json(['data' => $game], 201);
    }

    public function show(Game $game): JsonResponse
    {
        return response()->json(['data' => $game->load(['fields', 'products'])]);
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

        $before = $game->only(array_keys($data));
        $game->fill($data)->save();
        $game->refresh();
        $audit->record('game.updated', $game, $before, $game->only(array_keys($data)));

        return response()->json(['data' => $game]);
    }

    public function destroy(Game $game, AdminAuditLogger $audit): JsonResponse
    {
        if ($game->products()->exists()) {
            return response()->json([
                'message' => 'Game yang sudah memiliki produk tidak dapat dihapus. Nonaktifkan game sebagai gantinya.',
            ], 409);
        }

        $before = $game->only(['slug', 'name', 'is_active']);
        $audit->record('game.deleted', $game, $before, null);
        $game->delete();

        return response()->json(['message' => 'Game berhasil dihapus.']);
    }
}
