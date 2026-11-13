<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliationLog;
use App\Models\PaymentWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentStateService
{
    public function __construct(private readonly MidtransAmount $amounts) {}

    public function handleNotification(array $payload, string $rawBody): string
    {
        $fingerprint = $this->eventFingerprint($payload);
        $payloadHash = hash('sha256', $rawBody);

        try {
            return DB::transaction(function () use ($payload, $fingerprint, $payloadHash): string {
                $existingEvent = PaymentWebhookEvent::query()
                    ->where('event_fingerprint', $fingerprint)
                    ->first();

                if ($existingEvent) {
                    return 'duplicate';
                }

                $orderId = (string) $payload['order_id'];
                $attempt = PaymentAttempt::query()
                    ->where('provider_order_id', $orderId)
                    ->lockForUpdate()
                    ->first();

                $event = PaymentWebhookEvent::create([
                    'provider' => 'midtrans',
                    'event_fingerprint' => $fingerprint,
                    'payment_attempt_id' => $attempt?->id,
                    'provider_order_id' => $orderId,
                    'provider_transaction_id' => $this->nullableString($payload['transaction_id'] ?? null),
                    'event_type' => 'notification',
                    'provider_status' => $this->nullableString($payload['transaction_status'] ?? null),
                    'payload_hash' => $payloadHash,
                    'signature_valid' => true,
                    'processing_status' => 'received',
                    'safe_metadata' => $this->safeMetadata($payload),
                    'received_at' => now(),
                ]);

                if (! $attempt) {
                    $event->update([
                        'processing_status' => 'unmatched',
                        'processed_at' => now(),
                    ]);

                    return 'unmatched';
                }

                $amount = $this->amounts->toIntegerIdr($payload['gross_amount'] ?? null);
                if ($amount !== $attempt->amount || (($payload['currency'] ?? 'IDR') !== 'IDR')) {
                    $event->update([
                        'processing_status' => 'rejected_amount_or_currency',
                        'processed_at' => now(),
                    ]);

                    return 'rejected';
                }

                $mappedStatus = $this->mapProviderStatus($payload);
                $result = $this->applyAttemptStatus($attempt, $payload, $mappedStatus);

                $event->update([
                    'processing_status' => $result,
                    'processed_at' => now(),
                ]);

                return $result === 'conflict' ? 'conflict' : 'processed';
            }, 3);
        } catch (QueryException $exception) {
            $duplicate = PaymentWebhookEvent::query()
                ->where('event_fingerprint', $fingerprint)
                ->exists();

            if ($duplicate) {
                return 'duplicate';
            }

            throw $exception;
        }
    }

    public function applyReconciledStatus(PaymentAttempt $attempt, array $payload): string
    {
        if (($payload['order_id'] ?? null) !== $attempt->provider_order_id) {
            return 'rejected_reference';
        }

        $amount = $this->amounts->toIntegerIdr($payload['gross_amount'] ?? null);
        if ($amount !== $attempt->amount || (($payload['currency'] ?? 'IDR') !== 'IDR')) {
            return 'rejected_amount_or_currency';
        }

        return DB::transaction(function () use ($attempt, $payload): string {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $status = $this->mapProviderStatus($payload);

            return $this->applyAttemptStatus($locked, $payload, $status);
        }, 3);
    }

    private function applyAttemptStatus(PaymentAttempt $attempt, array $payload, string $incomingStatus): string
    {
        $attempt->refresh();
        $order = Order::query()->lockForUpdate()->findOrFail($attempt->order_id);

        $transactionId = $this->nullableString($payload['transaction_id'] ?? null);
        if ($transactionId !== null) {
            $otherAttempt = PaymentAttempt::query()
                ->where('provider_transaction_id', $transactionId)
                ->where('id', '!=', $attempt->id)
                ->exists();

            if ($otherAttempt || ($attempt->provider_transaction_id !== null
                && $attempt->provider_transaction_id !== $transactionId)) {
                if ($attempt->status !== 'paid') {
                    $attempt->update(['status' => 'reconciliation_required']);
                }

                $this->preservePaidAggregate($order);

                return 'conflict';
            }
        }

        $current = $attempt->status;
        $next = $current;

        if ($incomingStatus === 'paid') {
            $next = 'paid';
        } elseif ($current === 'paid') {
            $next = 'paid';
        } elseif (in_array($current, ['failed', 'expired'], true)
            && in_array($incomingStatus, ['pending', 'unknown', 'reconciliation_required'], true)) {
            $next = $current;
        } elseif (in_array($incomingStatus, ['pending', 'failed', 'expired', 'reconciliation_required'], true)) {
            $next = $incomingStatus;
        }

        $attemptUpdates = ['status' => $next];
        if ($transactionId !== null && $attempt->provider_transaction_id === null) {
            $attemptUpdates['provider_transaction_id'] = $transactionId;
        }
        if (in_array($next, ['paid', 'failed', 'expired'], true) && $attempt->confirmed_at === null) {
            $attemptUpdates['confirmed_at'] = now();
        }
        $attemptUpdates['provider_metadata'] = $this->safeMetadata($payload);
        $attempt->update($attemptUpdates);

        $attempts = PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->get();

        $paidAttempts = $attempts->where('status', 'paid');
        if ($paidAttempts->isNotEmpty()) {
            $order->update([
                'status' => 'paid',
                'payment_status' => 'paid',
                'paid_at' => $order->paid_at ?? now(),
            ]);

            OutboxEvent::firstOrCreate(
                [
                    'order_id' => $order->id,
                    'event_type' => 'order.paid',
                ],
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

            if ($paidAttempts->count() > 1) {
                PaymentReconciliationLog::firstOrCreate(
                    [
                        'payment_attempt_id' => $attempt->id,
                        'source' => 'multiple_paid_attempts',
                        'outcome' => 'multiple_paid_conflict',
                    ],
                    [
                        'provider_status' => 'multiple_paid',
                        'safe_metadata' => [
                            'paid_attempt_ids' => $paidAttempts->pluck('id')->values()->all(),
                        ],
                        'checked_at' => now(),
                    ],
                );

                return 'conflict';
            }

            return 'processed';
        }

        if ($order->payment_status === 'paid') {
            return 'processed';
        }

        $activeStatuses = ['creating', 'pending', 'unknown', 'reconciliation_required'];
        $hasActive = $attempts->contains(fn (PaymentAttempt $row) => in_array($row->status, $activeStatuses, true));

        if ($hasActive) {
            $paymentStatus = $attempts->contains(fn (PaymentAttempt $row) => in_array(
                $row->status,
                ['unknown', 'reconciliation_required'],
                true,
            )) ? 'unknown' : 'pending';

            $order->update([
                'status' => 'pending_payment',
                'payment_status' => $paymentStatus,
            ]);

            return 'processed';
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

        return 'processed';
    }


    private function preservePaidAggregate(Order $order): void
    {
        $hasPaidAttempt = PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->where('status', 'paid')
            ->exists();

        if (! $hasPaidAttempt && $order->payment_status !== 'paid') {
            $order->update(['payment_status' => 'unknown']);

            return;
        }

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
    }

    private function mapProviderStatus(array $payload): string
    {
        $status = strtolower((string) ($payload['transaction_status'] ?? ''));
        $statusCode = (string) ($payload['status_code'] ?? '');
        $fraudStatus = strtolower((string) ($payload['fraud_status'] ?? ''));

        if ($status === 'settlement') {
            return $statusCode === '200' ? 'paid' : 'reconciliation_required';
        }

        if ($status === 'capture') {
            return $statusCode === '200' && $fraudStatus === 'accept'
                ? 'paid'
                : 'reconciliation_required';
        }

        return match ($status) {
            'pending', 'authorize' => 'pending',
            'deny', 'cancel' => 'failed',
            'expire' => 'expired',
            'refund', 'partial_refund', 'chargeback' => 'reconciliation_required',
            default => 'reconciliation_required',
        };
    }

    private function eventFingerprint(array $payload): string
    {
        $parts = [
            (string) ($payload['order_id'] ?? ''),
            (string) ($payload['transaction_id'] ?? ''),
            (string) ($payload['transaction_status'] ?? ''),
            (string) ($payload['status_code'] ?? ''),
            (string) ($payload['gross_amount'] ?? ''),
            (string) ($payload['fraud_status'] ?? ''),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function safeMetadata(array $payload): array
    {
        $allowed = [
            'status_code',
            'transaction_status',
            'fraud_status',
            'payment_type',
            'currency',
            'transaction_time',
            'settlement_time',
        ];

        return array_intersect_key($payload, array_flip($allowed));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
