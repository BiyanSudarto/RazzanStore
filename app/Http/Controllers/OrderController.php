<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderPaymentService;
use App\Services\TrackingTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class OrderController extends Controller
{
    public function show(string $trackingToken, TrackingTokenService $tokens): JsonResponse
    {
        $order = Order::query()
            ->with(['paymentAttempts:id,order_id,attempt_no,status,amount,currency,created_at,updated_at'])
            ->where('tracking_token_hash', $tokens->hash($trackingToken))
            ->firstOrFail();

        $latestAttempt = $order->paymentAttempts->last();

        return response()->json([
            'data' => [
                'order_code' => $order->public_order_code,
                'game' => $order->game_name_snapshot,
                'product' => $order->product_name_snapshot,
                'nominal' => $order->nominal_label_snapshot,
                'player_data' => $order->player_data,
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'created_at' => $order->created_at,
                'expires_at' => $order->expires_at,
                'paid_at' => $order->paid_at,
                'payment_attempt' => $latestAttempt ? [
                    'attempt_no' => $latestAttempt->attempt_no,
                    'status' => $latestAttempt->status,
                    'amount' => $latestAttempt->amount,
                    'currency' => $latestAttempt->currency,
                ] : null,
            ],
        ]);
    }

    public function retry(
        Request $request,
        string $trackingToken,
        OrderPaymentService $payments,
    ): JsonResponse {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[\x21-\x7E]{16,128}$/', $key)) {
            throw new BadRequestHttpException('Header Idempotency-Key wajib diisi (16–128 karakter ASCII yang dapat dicetak).');
        }

        $result = $payments->retry($trackingToken, $key);
        $order = $result['order'];
        $attempt = $result['attempt'];

        $body = [
            'data' => [
                'order_code' => $order->public_order_code,
                'tracking_token' => $result['tracking_token'],
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

        if (in_array($attempt?->status, ['unknown', 'creating'], true)) {
            return response()->json($body, 202);
        }

        if ($attempt?->status === 'failed') {
            return response()->json(['message' => 'Payment gateway menolak pembuatan transaksi.'], 502);
        }

        return response()->json($body, $result['replayed'] ? 200 : 201);
    }
}
