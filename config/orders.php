<?php

return [
    'tracking_token_key' => env('TRACKING_TOKEN_KEY'),
    'idempotency_retention_days' => (int) env('IDEMPOTENCY_RETENTION_DAYS', 30),
    'business_expiry_minutes' => (int) env('ORDER_EXPIRY_MINUTES', 60),
    'max_payment_attempts' => 3,
];
