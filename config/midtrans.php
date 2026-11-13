<?php

return [
    'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
    'server_key' => env('MIDTRANS_SERVER_KEY'),
    'client_key' => env('MIDTRANS_CLIENT_KEY'),
    'snap_base_url' => env(
        'MIDTRANS_SNAP_BASE_URL',
        env('MIDTRANS_IS_PRODUCTION', false)
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions',
    ),
    'api_base_url' => env(
        'MIDTRANS_API_BASE_URL',
        env('MIDTRANS_IS_PRODUCTION', false)
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com',
    ),
    'connect_timeout' => (int) env('MIDTRANS_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('MIDTRANS_TIMEOUT', 15),
];
