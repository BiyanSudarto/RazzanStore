<?php

namespace App\Services;

use App\Models\Game;
use Illuminate\Validation\ValidationException;

class GamePlayerDataValidator
{
    public function validate(Game $game, mixed $input): array
    {
        if (! is_array($input) || array_is_list($input) || count($input) > 20) {
            throw ValidationException::withMessages([
                'player_data' => 'Data Player ID/server harus berupa objek dengan maksimal 20 field.',
            ]);
        }

        $fields = $game->fields->keyBy('field_key');
        $unknown = array_diff(array_keys($input), $fields->keys()->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'player_data' => 'Terdapat field Player ID/server yang tidak dikenal.',
            ]);
        }

        $validated = [];

        foreach ($fields as $key => $field) {
            $present = array_key_exists($key, $input);
            $value = $present ? $input[$key] : null;

            if (! $present || $value === null || $value === '') {
                if ($field->is_required) {
                    throw ValidationException::withMessages([
                        "player_data.$key" => "Field {$field->label} wajib diisi.",
                    ]);
                }

                continue;
            }

            if (! is_string($value) && ! is_int($value)) {
                throw ValidationException::withMessages([
                    "player_data.$key" => "Field {$field->label} harus berupa teks atau angka tunggal.",
                ]);
            }

            $value = trim((string) $value);
            if ($value === '') {
                if ($field->is_required) {
                    throw ValidationException::withMessages([
                        "player_data.$key" => "Field {$field->label} wajib diisi.",
                    ]);
                }

                continue;
            }

            $rules = $field->validation_rules ?? [];

            if ($field->input_type === 'number') {
                if (! preg_match('/^\d+$/', $value)) {
                    throw ValidationException::withMessages([
                        "player_data.$key" => "Field {$field->label} harus berupa angka bulat.",
                    ]);
                }

                $number = (int) $value;
                if (isset($rules['min']) && $number < $rules['min']) {
                    throw ValidationException::withMessages([
                        "player_data.$key" => "Nilai {$field->label} di bawah batas minimum.",
                    ]);
                }
                if (isset($rules['max']) && $number > $rules['max']) {
                    throw ValidationException::withMessages([
                        "player_data.$key" => "Nilai {$field->label} melebihi batas maksimum.",
                    ]);
                }
            } elseif ($field->input_type === 'select') {
                $options = $field->options ?? [];
                if (! in_array($value, $options, true)) {
                    throw ValidationException::withMessages([
                        "player_data.$key" => "Pilihan {$field->label} tidak valid.",
                    ]);
                }
            } elseif ($field->input_type === 'text') {
                $length = mb_strlen($value);
                if (isset($rules['min_length']) && $length < $rules['min_length']) {
                    throw ValidationException::withMessages([
                        "player_data.$key" => "Field {$field->label} terlalu pendek.",
                    ]);
                }
                if (isset($rules['max_length']) && $length > $rules['max_length']) {
                    throw ValidationException::withMessages([
                        "player_data.$key" => "Field {$field->label} terlalu panjang.",
                    ]);
                }
            } else {
                throw ValidationException::withMessages([
                    "player_data.$key" => 'Konfigurasi input game tidak didukung.',
                ]);
            }

            $validated[$key] = $value;
        }

        return $validated;
    }
}
