<?php

namespace App\Http\Controllers;

use App\Services\OrderPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class CheckoutController extends Controller
{
    public function store(Request $request, OrderPaymentService $payments): JsonResponse
    {
        fwrite(STDERR, "CTRL0 entered\n");
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[\x21-\x7E]{16,128}$/', $key)) {
            throw new BadRequestHttpException('Header Idempotency-Key wajib diisi (16–128 karakter ASCII yang dapat dicetak).');
        }

        fwrite(STDERR, "CTRL1 key-ok\n");
        $input = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'player_data' => ['required', 'array', 'max:20'],
            'customer_contact' => ['nullable', 'string', 'regex:/^\\+?[0-9]{8,15}$/'],
        ]);

        fwrite(STDERR, "CTRL2 validated\n");
        $result = $payments->checkout($input, $key);
        fwrite(STDERR, "CTRL3 service-returned\n");
        $attempt = $result['attempt'];
        $order = $result['order'];

        $body = [
            'data' => [
                'order_code' => $order->public_order_code,
                'tracking_token' => $result['tracking_token'],
                'tracking_url' => url('/track/'.$result['tracking_token']),
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'payment_status' => $order->payment_status,
                'payment' => [
                    'provider' => 'midtrans',
                    'status' => $attempt?->status,
                    'snap_token' => $attempt?->snap_token,
                    'redirect_url' => $attempt?->redirect_url,
                ],
            ],
        ];

        if ($attempt?->status === 'unknown' || $attempt?->status === 'creating') {
            $body['message'] = 'Status pembuatan transaksi belum dapat dipastikan. Sistem akan melakukan rekonsiliasi sebelum membuat transaksi baru.';

            return response()->json($body, 202);
        }

        if ($attempt?->status === 'failed') {
            return response()->json([
                'message' => 'Payment gateway tidak dapat membuat sesi pembayaran. Pesanan belum dibayar; silakan periksa status sebelum mencoba kembali.',
                'data' => $body['data'],
            ], 502);
        }

        return response()->json($body, $result['replayed'] ? 200 : 201);
    }
}
