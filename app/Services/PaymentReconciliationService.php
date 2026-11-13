<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PaymentReconciliationService
{
    public function __construct(
        private readonly MidtransClient $midtrans,
        private readonly PaymentStateService $states,
    ) {}

    public function run(int $limit = 100): array
    {
        $staleCreatingBefore = now()->subMinutes(2);

        $attempts = PaymentAttempt::query()
            ->where(function ($query) use ($staleCreatingBefore): void {
                $query->whereIn('status', ['pending', 'unknown', 'reconciliation_required'])
                    ->orWhere(function ($creating) use ($staleCreatingBefore): void {
                        $creating->where('status', 'creating')
                            ->whereNotNull('provider_request_started_at')
                            ->where('provider_request_started_at', '<=', $staleCreatingBefore);
                    });
            })
            ->orderByRaw("CASE WHEN status = 'creating' THEN 0 ELSE 1 END")
            ->orderBy('requested_at')
            ->limit(min(max($limit, 1), 500))
            ->get();

        $summary = [
            'checked' => 0,
            'processed' => 0,
            'still_ambiguous' => 0,
            'rejected' => 0,
            'errors' => 0,
        ];

        foreach ($attempts as $attempt) {
            $eligible = DB::transaction(function () use ($attempt): bool {
                $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

                if ($locked->status === 'creating') {
                    $startedAt = $locked->provider_request_started_at;
                    if ($startedAt === null || $startedAt->gt(now()->subMinutes(2))) {
                        return false;
                    }

                    $locked->update(['status' => 'unknown']);
                }

                return in_array(
                    $locked->status,
                    ['pending', 'unknown', 'reconciliation_required'],
                    true,
                );
            }, 3);

            if (! $eligible) {
                continue;
            }

            $attempt->refresh();
            $summary['checked']++;

            try {
                $response = $this->midtrans->getStatus($attempt->provider_order_id);
            } catch (ConnectionException) {
                $this->markAmbiguous($attempt, null, 'network_error', null);
                $summary['errors']++;

                continue;
            } catch (Throwable) {
                $this->markAmbiguous($attempt, null, 'client_error', null);
                $summary['errors']++;

                continue;
            }

            if ($response->status() === 404) {
                $this->markAmbiguous($attempt, 404, 'not_found_ambiguous', null);
                $summary['still_ambiguous']++;

                continue;
            }

            if (! $response->successful()) {
                $this->markAmbiguous($attempt, $response->status(), 'provider_http_error', null);
                $summary['errors']++;

                continue;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                $this->markAmbiguous($attempt, $response->status(), 'invalid_provider_payload', null);
                $summary['errors']++;

                continue;
            }

            $outcome = $this->states->applyReconciledStatus($attempt, $payload);
            $attempt->refresh();
            $attempt->update([
                'last_reconciled_at' => now(),
                'reconciliation_count' => $attempt->reconciliation_count + 1,
            ]);

            PaymentReconciliationLog::create([
                'payment_attempt_id' => $attempt->id,
                'source' => 'midtrans_status_api',
                'http_status' => $response->status(),
                'provider_status' => is_string($payload['transaction_status'] ?? null)
                    ? $payload['transaction_status'] : null,
                'outcome' => $outcome,
                'response_hash' => hash('sha256', $response->body()),
                'safe_metadata' => $this->safeMetadata($payload),
                'checked_at' => now(),
            ]);

            if (str_starts_with($outcome, 'rejected')) {
                $summary['rejected']++;
            } elseif ($outcome === 'processed') {
                $summary['processed']++;
            } else {
                $summary['still_ambiguous']++;
            }
        }

        return $summary;
    }

    private function markAmbiguous(
        PaymentAttempt $attempt,
        ?int $httpStatus,
        string $outcome,
        ?array $metadata,
    ): void {
        DB::transaction(function () use ($attempt, $httpStatus, $outcome, $metadata): void {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status !== 'paid') {
                $locked->status = 'unknown';
            }
            $locked->last_reconciled_at = now();
            $locked->reconciliation_count++;
            $locked->save();

            PaymentReconciliationLog::create([
                'payment_attempt_id' => $locked->id,
                'source' => 'midtrans_status_api',
                'http_status' => $httpStatus,
                'outcome' => $outcome,
                'response_hash' => null,
                'safe_metadata' => $metadata,
                'checked_at' => now(),
            ]);

            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);
            $hasPaidAttempt = PaymentAttempt::query()
                ->where('order_id', $order->id)
                ->where('status', 'paid')
                ->exists();

            if ($hasPaidAttempt || $order->payment_status === 'paid') {
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
            } elseif ($locked->status !== 'paid') {
                $order->update(['payment_status' => 'unknown']);
            }
        }, 3);
    }

    private function safeMetadata(array $payload): array
    {
        $allowed = [
            'status_code',
            'transaction_status',
            'fraud_status',
            'payment_type',
            'currency',
        ];

        return array_intersect_key($payload, array_flip($allowed));
    }
}
