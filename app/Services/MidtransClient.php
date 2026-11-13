<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MidtransClient
{
    public function createSnapTransaction(array $payload): Response
    {
        $serverKey = (string) config('midtrans.server_key');
        if ($serverKey === '') {
            throw new RuntimeException('Midtrans Server Key belum dikonfigurasi.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withBasicAuth($serverKey, '')
            ->connectTimeout((int) config('midtrans.connect_timeout', 5))
            ->timeout((int) config('midtrans.timeout', 15))
            ->post((string) config('midtrans.snap_base_url'), $payload);
    }

    public function getStatus(string $providerOrderId): Response
    {
        $serverKey = (string) config('midtrans.server_key');
        if ($serverKey === '') {
            throw new RuntimeException('Midtrans Server Key belum dikonfigurasi.');
        }

        $baseUrl = rtrim((string) config('midtrans.api_base_url'), '/');
        $path = rawurlencode($providerOrderId);

        return Http::acceptJson()
            ->withBasicAuth($serverKey, '')
            ->connectTimeout((int) config('midtrans.connect_timeout', 5))
            ->timeout((int) config('midtrans.timeout', 15))
            ->get($baseUrl.'/v2/'.$path.'/status');
    }
}
