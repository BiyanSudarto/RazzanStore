<?php

namespace App\Services;

class MidtransAmount
{
    public function toIntegerIdr(mixed $grossAmount): ?int
    {
        if (! is_string($grossAmount)
            || ! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/', $grossAmount, $matches)) {
            return null;
        }

        $fraction = $matches[2] ?? '00';
        if (str_pad($fraction, 2, '0') !== '00') {
            return null;
        }

        $integer = filter_var($matches[1], FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }
}
