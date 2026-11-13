<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MidtransWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-webhook-test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('midtrans.server_key', self::SERVER_KEY);
    }

    private function makeAttempt(): PaymentAttempt
    {
        $game = Game::create([
            'slug' => 'free-fire',
            'name' => 'Free Fire',
            'is_active' => true,
        ]);

        $order = Order::create([
            'public_order_code' => 'RS-TESTORDER001',
            'game_id' => $game->id,
            'product_sku_snapshot' => 'FF-100',
            'product_name_snapshot' => '100 Diamonds',
            'game_name_snapshot' => 'Free Fire',
            'player_data' => ['player_id' => '12345678'],
            'unit_price' => 12000,
            'quantity' => 1,
            'subtotal' => 12000,
            'discount_amount' => 0,
            'total_amount' => 12000,
            'currency' => 'IDR',
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'fulfillment_status' => 'not_started',
            'tracking_token_hash' => hash('sha256', 'test-tracking-token'),
            'expires_at' => now()->addHour(),
        ]);

        return $order->paymentAttempts()->create([
            'attempt_no' => 1,
            'provider' => 'midtrans',
            'provider_order_id' => 'RS-TESTORDER001-P1',
            'status' => 'pending',
            'amount' => 12000,
            'currency' => 'IDR',
            'requested_at' => now(),
        ]);
    }

    private function payload(string $status, string $statusCode = '200', string $grossAmount = '12000.00', string $fraudStatus = 'accept'): array
    {
        $data = [
            'order_id' => 'RS-TESTORDER001-P1',
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'transaction_id' => 'midtrans-txn-001',
            'transaction_status' => $status,
            'fraud_status' => $fraudStatus,
            'currency' => 'IDR',
            'payment_type' => 'bank_transfer',
        ];

        $data['signature_key'] = hash(
            'sha512',
            $data['order_id'].$data['status_code'].$data['gross_amount'].self::SERVER_KEY,
        );

        return $data;
    }

    public function test_valid_settlement_marks_order_paid_and_creates_one_outbox_event(): void
    {
        $attempt = $this->makeAttempt();

        $this->postJson('/api/v1/webhooks/midtrans', $this->payload('settlement'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame('paid', $attempt->order->fresh()->payment_status);
        $this->assertSame(1, OutboxEvent::where('event_type', 'order.paid')->count());
    }

    public function test_invalid_signature_is_rejected_without_mutating_payment(): void
    {
        $attempt = $this->makeAttempt();
        $payload = $this->payload('settlement');
        $payload['signature_key'] = str_repeat('0', 128);

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertUnauthorized();

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame('pending', $attempt->order->fresh()->payment_status);
        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_signed_amount_mismatch_is_rejected_without_payment_transition(): void
    {
        $attempt = $this->makeAttempt();

        $this->postJson('/api/v1/webhooks/midtrans', $this->payload('settlement', '200', '12001.00'))
            ->assertUnprocessable();

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame('pending', $attempt->order->fresh()->payment_status);
        $this->assertSame('rejected_amount_or_currency', \App\Models\PaymentWebhookEvent::firstOrFail()->processing_status);
    }

    public function test_duplicate_notification_is_acknowledged_without_duplicate_outbox(): void
    {
        $attempt = $this->makeAttempt();
        $payload = $this->payload('settlement');

        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, OutboxEvent::where('event_type', 'order.paid')->count());
        $this->assertSame(1, \App\Models\PaymentWebhookEvent::count());
    }

    public function test_stale_pending_notification_cannot_regress_paid_order(): void
    {
        $attempt = $this->makeAttempt();

        $this->postJson('/api/v1/webhooks/midtrans', $this->payload('settlement'))->assertOk();
        $pending = $this->payload('pending', '201');
        $pending['transaction_id'] = 'midtrans-txn-001';
        $pending['signature_key'] = hash(
            'sha512',
            $pending['order_id'].$pending['status_code'].$pending['gross_amount'].self::SERVER_KEY,
        );

        $this->postJson('/api/v1/webhooks/midtrans', $pending)->assertOk();

        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame('paid', $attempt->order->fresh()->payment_status);
        $this->assertSame(1, OutboxEvent::where('event_type', 'order.paid')->count());
    }

    public function test_capture_with_challenge_fraud_status_is_not_paid(): void
    {
        $attempt = $this->makeAttempt();

        $this->postJson('/api/v1/webhooks/midtrans', $this->payload('capture', '200', '12000.00', 'challenge'))
            ->assertOk();

        $this->assertSame('reconciliation_required', $attempt->fresh()->status);
        $this->assertSame('unknown', $attempt->order->fresh()->payment_status);
        $this->assertSame(0, OutboxEvent::count());
    }
}
