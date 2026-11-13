<?php

namespace App\Services;

use App\Models\Order;
use RuntimeException;

class TrackingTokenService
{
    public function issueFor(Order $order): string
    {
        $key = (string) config('orders.tracking_token_key');

        if (strlen($key) < 32) {
            throw new RuntimeException('TRACKING_TOKEN_KEY must contain at least 32 characters.');
        }

        $digest = hash_hmac('sha256', $order->public_order_code, $key, true);

        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
