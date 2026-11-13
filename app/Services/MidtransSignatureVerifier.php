<?php

namespace App\Services;

class MidtransSignatureVerifier
{
    public function verify(array $payload): bool
    {
        $serverKey = (string) config('midtrans.server_key');
        $orderId = $payload['order_id'] ?? null;
        $statusCode = $payload['status_code'] ?? null;
        $grossAmount = $payload['gross_amount'] ?? null;
        $signature = $payload['signature_key'] ?? null;

        if ($serverKey === ''
            || ! is_string($orderId)
            || ! is_string($statusCode)
            || ! is_string($grossAmount)
            || ! is_string($signature)
            || ! preg_match('/^[a-fA-F0-9]{128}$/', $signature)) {
            return false;
        }

        $expected = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        return hash_equals($expected, strtolower($signature));
    }
}
