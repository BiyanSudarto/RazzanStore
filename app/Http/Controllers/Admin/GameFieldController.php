<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameField;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameFieldController extends Controller
{
    public function store(Request $request, Game $game, AdminAuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request, $game);

        $field = DB::transaction(function () use ($game, $data, $audit): GameField {
            $field = $game->fields()->create($data);
            $audit->record('game_field.created', $field, null, $field->toArray());

            return $field;
        });

        return response()->json(['data' => $field], 201);
    }

    public function update(Request $request, Game $game, GameField $field, AdminAuditLogger $audit): JsonResponse
    {
        abort_unless($field->game_id === $game->id, 404);

        $data = $this->validated($request, $game, $field);
        $updated = DB::transaction(function () use ($game, $field, $data, $audit): GameField {
            $locked = GameField::query()->lockForUpdate()->findOrFail($field->id);
            abort_unless($locked->game_id === $game->id, 404);

            $before = $locked->only(array_keys($data));
            $locked->fill($data)->save();
            $locked->refresh();
            $audit->record('game_field.updated', $locked, $before, $locked->only(array_keys($data)));

            return $locked;
        }, 3);

        return response()->json(['data' => $updated]);
    }

    public function destroy(Game $game, GameField $field, AdminAuditLogger $audit): JsonResponse
    {
        abort_unless($field->game_id === $game->id, 404);

        DB::transaction(function () use ($game, $field, $audit): void {
            $locked = GameField::query()->lockForUpdate()->findOrFail($field->id);
            abort_unless($locked->game_id === $game->id, 404);

            $before = $locked->toArray();
            $audit->record('game_field.deleted', $locked, $before, null);
            $locked->delete();
        }, 3);

        return response()->json(['message' => 'Field berhasil dihapus.']);
    }

    private function validated(Request $request, Game $game, ?GameField $field = null): array
    {
        $data = $request->validate([
            'field_key' => [
                $field ? 'sometimes' : 'required',
                'required',
                'string',
                'max:60',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('game_fields', 'field_key')
                    ->where('game_id', $game->id)
                    ->ignore($field?->id),
            ],
            'label' => [$field ? 'sometimes' : 'required', 'required', 'string', 'max:100'],
            'input_type' => [$field ? 'sometimes' : 'required', 'required', Rule::in(['text', 'number', 'select'])],
            'is_required' => ['sometimes', 'boolean'],
            'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['string', 'max:100'],
            'validation_rules' => ['nullable', 'array:min_length,max_length,min,max'],
            'validation_rules.min_length' => ['sometimes', 'integer', 'min:1', 'max:255'],
            'validation_rules.max_length' => ['sometimes', 'integer', 'min:1', 'max:255'],
            'validation_rules.min' => ['sometimes', 'integer', 'min:0'],
            'validation_rules.max' => ['sometimes', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);

        $rules = $data['validation_rules'] ?? [];
        if ((isset($rules['min_length'], $rules['max_length']) && $rules['min_length'] > $rules['max_length'])
            || (isset($rules['min'], $rules['max']) && $rules['min'] > $rules['max'])) {
            throw ValidationException::withMessages([
                'validation_rules' => 'Batas minimum tidak boleh melebihi batas maksimum.',
            ]);
        }

        $type = $data['input_type'] ?? $field?->input_type;
        $options = $data['options'] ?? $field?->options;

        if ($type !== 'select' && ! empty($options)) {
            throw ValidationException::withMessages(['options' => 'Pilihan hanya boleh diisi untuk field select.']);
        }

        if ($type === 'select' && empty($options)) {
            throw ValidationException::withMessages(['options' => 'Field select wajib memiliki minimal satu pilihan.']);
        }

        return $data;
    }
}
