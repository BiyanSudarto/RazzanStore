<?php

return [
    'tracking_token_key' => env('TRACKING_TOKEN_KEY'),
    'tracking_token_ttl_days' => (int) env('TRACKING_TOKEN_TTL_DAYS', 90),
    'idempotency_retention_days' => (int) env('IDEMPOTENCY_RETENTION_DAYS', 30),
    'business_expiry_minutes' => (int) env('ORDER_EXPIRY_MINUTES', 60),
    'max_payment_attempts' => 3,
];
