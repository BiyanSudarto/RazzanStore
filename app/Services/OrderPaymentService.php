<?php

namespace App\Services;

use App\Models\IdempotencyRecord;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\PaymentAttempt;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OrderPaymentService
{
    public function __construct(
        private readonly GamePlayerDataValidator $playerDataValidator,
        private readonly TrackingTokenService $trackingTokens,
        private readonly MidtransClient $midtrans,
    ) {}

    public function checkout(array $input, string $idempotencyKey): array
    {
        $playerData = $input['player_data'] ?? [];
        ksort($playerData);
        $canonical = [
            'product_id' => (int) $input['product_id'],
            'player_data' => $playerData,
            'customer_contact' => $input['customer_contact'] ?? null,
        ];

        $scope = 'checkout';
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            $result = DB::transaction(function () use ($canonical, $scope, $keyHash, $requestHash): array {
                IdempotencyRecord::query()
                    ->where('scope', $scope)
                    ->where('key_hash', $keyHash)
                    ->where('expires_at', '<=', now())
                    ->delete();

                $existing = IdempotencyRecord::query()
                    ->where('scope', $scope)
                    ->where('key_hash', $keyHash)
                    ->first();

                if ($existing) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        throw new ConflictHttpException('Idempotency-Key telah digunakan untuk request yang berbeda.');
                    }

                    return [
                        'order' => $existing->order,
                        'attempt' => $existing->paymentAttempt,
                        'replayed' => true,
                    ];
                }

                $product = Product::query()
                    ->with(['game.fields'])
                    ->sharedLock()
                    ->find($canonical['product_id']);

                if (! $product || ! $product->is_active || ! $product->game?->is_active) {
                    throw ValidationException::withMessages([
                        'product_id' => 'Produk tidak tersedia.',
                    ]);
                }

                $validatedPlayerData = $this->playerDataValidator->validate(
                    $product->game,
                    $canonical['player_data'],
                );

                $price = (int) $product->selling_price;
                if ($price < 1) {
                    throw ValidationException::withMessages([
                        'product_id' => 'Harga produk tidak valid.',
                    ]);
                }

                $publicCode = 'RS-'.strtoupper((string) Str::ulid());
                $order = Order::create([
                    'public_order_code' => $publicCode,
                    'product_id' => $product->id,
                    'game_id' => $product->game_id,
                    'product_sku_snapshot' => $product->sku,
                    'product_name_snapshot' => $product->name,
                    'game_name_snapshot' => $product->game->name,
                    'nominal_label_snapshot' => $product->nominal_label,
                    'player_data' => $validatedPlayerData,
                    'customer_contact' => $canonical['customer_contact'],
                    'unit_price' => $price,
                    'quantity' => 1,
                    'subtotal' => $price,
                    'discount_amount' => 0,
                    'total_amount' => $price,
                    'currency' => 'IDR',
                    'status' => 'pending_payment',
                    'payment_status' => 'pending',
                    'fulfillment_status' => 'not_started',
                    'tracking_token_hash' => $this->trackingTokens->hash(
                        $this->trackingTokens->issueFor($publicCode),
                    ),
                    'expires_at' => now()->addMinutes((int) config('orders.business_expiry_minutes', 60)),
                ]);

                $attempt = $order->paymentAttempts()->create([
                    'attempt_no' => 1,
                    'provider' => 'midtrans',
                    'provider_order_id' => $publicCode.'-P1',
                    'status' => 'creating',
                    'amount' => $price,
                    'currency' => 'IDR',
                    'requested_at' => now(),
                ]);

                IdempotencyRecord::create([
                    'scope' => $scope,
                    'key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'order_id' => $order->id,
                    'payment_attempt_id' => $attempt->id,
                    'created_at' => now(),
                    'expires_at' => now()->addDays((int) config('orders.idempotency_retention_days', 30)),
                ]);

                return [
                    'order' => $order,
                    'attempt' => $attempt,
                    'replayed' => false,
                ];
            }, 3);
        } catch (QueryException $exception) {
            $existing = IdempotencyRecord::query()
                ->where('scope', $scope)
                ->where('key_hash', $keyHash)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new ConflictHttpException('Idempotency-Key telah digunakan untuk request yang berbeda.');
            }

            $result = [
                'order' => $existing->order,
                'attempt' => $existing->paymentAttempt,
                'replayed' => true,
            ];
        }

        $attempt = $result['attempt'];
        if ($attempt && $attempt->status === 'creating') {
            $this->createSnapIfClaimed($attempt);
        }

        $result['order']->refresh();
        $result['attempt']?->refresh();
        $result['tracking_token'] = $this->trackingTokens->issueFor($result['order']);

        return $result;
    }

    public function retry(string $trackingToken, string $idempotencyKey): array
    {
        $tokenHash = $this->trackingTokens->hash($trackingToken);
        $scope = 'retry:'.$tokenHash;
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', json_encode(['retry' => true, 'tracking_hash' => $tokenHash], JSON_THROW_ON_ERROR));

        try {
            $result = DB::transaction(function () use ($tokenHash, $scope, $keyHash, $requestHash): array {
                IdempotencyRecord::query()
                    ->where('scope', $scope)
                    ->where('key_hash', $keyHash)
                    ->where('expires_at', '<=', now())
                    ->delete();

                $existing = IdempotencyRecord::query()
                    ->where('scope', $scope)
                    ->where('key_hash', $keyHash)
                    ->first();

                if ($existing) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        throw new ConflictHttpException('Idempotency-Key telah digunakan untuk request yang berbeda.');
                    }

                    return [
                        'order' => $existing->order,
                        'attempt' => $existing->paymentAttempt,
                        'replayed' => true,
                    ];
                }

                $order = Order::query()->where('tracking_token_hash', $tokenHash)->lockForUpdate()->firstOrFail();

                if ($order->payment_status === 'paid') {
                    throw new ConflictHttpException('Pesanan telah dibayar.');
                }

                if ($order->expires_at->isPast()) {
                    throw new ConflictHttpException('Masa checkout pesanan telah berakhir.');
                }

                $attempts = $order->paymentAttempts()->lockForUpdate()->get();
                if ($attempts->count() >= (int) config('orders.max_payment_attempts', 3)) {
                    throw new ConflictHttpException('Batas percobaan pembayaran telah tercapai.');
                }

                $blocking = $attempts->contains(fn (PaymentAttempt $attempt) => in_array(
                    $attempt->status,
                    ['creating', 'pending', 'unknown', 'reconciliation_required'],
                    true,
                ));

                if ($blocking) {
                    throw new ConflictHttpException('Percobaan pembayaran sebelumnya masih aktif atau belum dapat dipastikan. Status harus direkonsiliasi terlebih dahulu.');
                }

                $previous = $attempts->last();
                if (! $previous || ! in_array($previous->status, ['failed', 'expired'], true)) {
                    throw new ConflictHttpException('Percobaan ulang hanya tersedia setelah percobaan sebelumnya dipastikan gagal atau kedaluwarsa.');
                }

                $attemptNo = $attempts->count() + 1;
                $attempt = $order->paymentAttempts()->create([
                    'attempt_no' => $attemptNo,
                    'provider' => 'midtrans',
                    'provider_order_id' => $order->public_order_code.'-P'.$attemptNo,
                    'status' => 'creating',
                    'amount' => $order->total_amount,
                    'currency' => $order->currency,
                    'requested_at' => now(),
                ]);

                IdempotencyRecord::create([
                    'scope' => $scope,
                    'key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'order_id' => $order->id,
                    'payment_attempt_id' => $attempt->id,
                    'created_at' => now(),
                    'expires_at' => now()->addDays((int) config('orders.idempotency_retention_days', 30)),
                ]);

                return [
                    'order' => $order,
                    'attempt' => $attempt,
                    'replayed' => false,
                ];
            }, 3);
        } catch (QueryException $exception) {
            $existing = IdempotencyRecord::query()
                ->where('scope', $scope)
                ->where('key_hash', $keyHash)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new ConflictHttpException('Idempotency-Key telah digunakan untuk request yang berbeda.');
            }

            $result = [
                'order' => $existing->order,
                'attempt' => $existing->paymentAttempt,
                'replayed' => true,
            ];
        }

        $attempt = $result['attempt'];
        if ($attempt && $attempt->status === 'creating') {
            $this->createSnapIfClaimed($attempt);
        }

        $result['order']->refresh();
        $result['attempt']?->refresh();
        $result['tracking_token'] = $trackingToken;

        return $result;
    }

    private function createSnapIfClaimed(PaymentAttempt $attempt): void
    {
        $claimed = DB::transaction(function () use ($attempt): bool {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->status !== 'creating' || $locked->provider_request_started_at !== null) {
                return false;
            }

            $locked->forceFill(['provider_request_started_at' => now()])->save();

            return true;
        }, 3);

        if (! $claimed) {
            return;
        }

        $attempt->refresh();
        $order = $attempt->order;

        $payload = [
            'transaction_details' => [
                'order_id' => $attempt->provider_order_id,
                'gross_amount' => $attempt->amount,
            ],
            'item_details' => [[
                'id' => mb_substr($order->product_sku_snapshot, 0, 50),
                'price' => $attempt->amount,
                'quantity' => 1,
                'name' => mb_substr($order->product_name_snapshot, 0, 50),
            ]],
            'credit_card' => [
                'secure' => true,
            ],
        ];

        if ($order->customer_contact) {
            $payload['customer_details'] = [
                'phone' => $order->customer_contact,
            ];
        }

        if ((string) config('midtrans.server_key') === '') {
            $this->markAttemptFailed($attempt, 'server_key_not_configured');

            return;
        }

        try {
            $response = $this->midtrans->createSnapTransaction($payload);
        } catch (ConnectionException) {
            $this->markAttemptUnknown($attempt, 'connection_error');

            return;
        } catch (\Throwable) {
            $this->markAttemptUnknown($attempt, 'provider_client_error');

            return;
        }

        if ($response->successful()) {
            $token = $response->json('token');
            $redirectUrl = $response->json('redirect_url');

            if (! is_string($token) || $token === '' || ! $this->isSafeMidtransRedirect($redirectUrl)) {
                $this->markAttemptUnknown($attempt, 'invalid_provider_response');

                return;
            }

            DB::transaction(function () use ($attempt, $token, $redirectUrl, $response): void {
                $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

                if ($locked->status !== 'creating') {
                    return;
                }

                $locked->update([
                    'status' => 'pending',
                    'snap_token' => $token,
                    'redirect_url' => $redirectUrl,
                    'provider_metadata' => ['http_status' => $response->status()],
                ]);

                $this->syncOrderAggregate($locked->order_id);
            }, 3);

            return;
        }

        $status = $response->status();
        if ($status >= 500 || in_array($status, [408, 409, 429], true)) {
            $this->markAttemptUnknown($attempt, 'ambiguous_provider_response');

            return;
        }

        DB::transaction(function () use ($attempt, $status): void {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status !== 'creating') {
                return;
            }

            $locked->update([
                'status' => 'failed',
                'provider_metadata' => ['http_status' => $status],
            ]);
            $this->syncOrderAggregate($locked->order_id);
        }, 3);
    }

    private function markAttemptUnknown(PaymentAttempt $attempt, string $reason): void
    {
        DB::transaction(function () use ($attempt, $reason): void {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status !== 'creating') {
                return;
            }

            $locked->update([
                'status' => 'unknown',
                'provider_metadata' => ['reason' => $reason],
            ]);
            $this->syncOrderAggregate($locked->order_id);
        }, 3);
    }

    private function markAttemptFailed(PaymentAttempt $attempt, string $reason): void
    {
        DB::transaction(function () use ($attempt, $reason): void {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status !== 'creating') {
                return;
            }

            $locked->update([
                'status' => 'failed',
                'provider_metadata' => ['reason' => $reason],
                'confirmed_at' => now(),
            ]);
            $this->syncOrderAggregate($locked->order_id);
        }, 3);
    }

    private function syncOrderAggregate(int $orderId): void
    {
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);
        $attempts = PaymentAttempt::query()
            ->where('order_id', $orderId)
            ->lockForUpdate()
            ->get();

        if ($attempts->contains(fn (PaymentAttempt $row) => $row->status === 'paid')) {
            $order->update([
                'status' => 'paid',
                'payment_status' => 'paid',
                'paid_at' => $order->paid_at ?? now(),
            ]);

            OutboxEvent::firstOrCreate(
                ['order_id' => $order->id, 'event_type' => 'order.paid'],
                [
                    'event_uuid' => (string) Str::uuid(),
                    'payload' => [
                        'order_id' => $order->id,
                        'public_order_code' => $order->public_order_code,
                    ],
                    'status' => 'pending',
                    'available_at' => now(),
                ],
            );

            return;
        }

        if ($order->payment_status === 'paid') {
            return;
        }

        $activeStatuses = ['creating', 'pending', 'unknown', 'reconciliation_required'];
        if ($attempts->contains(fn (PaymentAttempt $row) => in_array($row->status, $activeStatuses, true))) {
            $unknown = $attempts->contains(fn (PaymentAttempt $row) => in_array(
                $row->status,
                ['unknown', 'reconciliation_required'],
                true,
            ));

            $order->update([
                'status' => 'pending_payment',
                'payment_status' => $unknown ? 'unknown' : 'pending',
            ]);

            return;
        }

        if ($attempts->isNotEmpty() && $attempts->every(fn (PaymentAttempt $row) => in_array(
            $row->status,
            ['failed', 'expired'],
            true,
        ))) {
            $allExpired = $attempts->every(fn (PaymentAttempt $row) => $row->status === 'expired');

            $order->update([
                'status' => 'failed',
                'payment_status' => $allExpired ? 'expired' : 'failed',
            ]);
        }
    }

    private function isSafeMidtransRedirect(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['app.midtrans.com', 'app.sandbox.midtrans.com'], true)
            && parse_url($url, PHP_URL_SCHEME) === 'https';
    }
}
