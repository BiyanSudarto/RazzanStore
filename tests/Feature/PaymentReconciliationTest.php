<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliationLog;
use App\Services\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-reconcile-test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('midtrans.server_key', self::SERVER_KEY);
        config()->set('midtrans.api_base_url', 'https://api.sandbox.midtrans.com');
    }

    private function createOrder(): Order
    {
        $game = Game::create([
            'slug' => 'pubg-mobile',
            'name' => 'PUBG Mobile',
            'is_active' => true,
        ]);

        return Order::create([
            'public_order_code' => 'RS-RECONCILE001',
            'game_id' => $game->id,
            'product_sku_snapshot' => 'PUBGM-60',
            'product_name_snapshot' => '60 UC',
            'game_name_snapshot' => 'PUBG Mobile',
            'player_data' => ['player_id' => '12345678'],
            'unit_price' => 15000,
            'quantity' => 1,
            'subtotal' => 15000,
            'discount_amount' => 0,
            'total_amount' => 15000,
            'currency' => 'IDR',
            'status' => 'pending_payment',
            'payment_status' => 'unknown',
            'fulfillment_status' => 'not_started',
            'tracking_token_hash' => hash('sha256', 'reconcile-token'),
            'expires_at' => now()->addHour(),
        ]);
    }

    private function createAttempt(Order $order, string $status, int $attemptNo = 1): PaymentAttempt
    {
        return $order->paymentAttempts()->create([
            'attempt_no' => $attemptNo,
            'provider' => 'midtrans',
            'provider_order_id' => $order->public_order_code.'-P'.$attemptNo,
            'status' => $status,
            'amount' => $order->total_amount,
            'currency' => 'IDR',
            'requested_at' => now()->subMinutes(5),
            'provider_request_started_at' => now()->subMinutes(4),
        ]);
    }

    public function test_status_api_settlement_marks_order_paid_and_records_reconciliation(): void
    {
        $order = $this->createOrder();
        $attempt = $this->createAttempt($order, 'unknown');

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
                'order_id' => $attempt->provider_order_id,
                'transaction_id' => 'midtrans-status-txn-1',
                'status_code' => '200',
                'gross_amount' => '15000.00',
                'currency' => 'IDR',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
            ], 200),
        ]);

        $summary = app(PaymentReconciliationService::class)->run();

        $this->assertSame(1, $summary['checked']);
        $this->assertSame(1, $summary['processed']);
        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, OutboxEvent::where('order_id', $order->id)->where('event_type', 'order.paid')->count());
        $this->assertDatabaseHas('payment_reconciliation_logs', [
            'payment_attempt_id' => $attempt->id,
            'outcome' => 'processed',
            'http_status' => 200,
        ]);
    }

    public function test_404_status_response_stays_unknown_and_is_not_marked_failed(): void
    {
        $order = $this->createOrder();
        $attempt = $this->createAttempt($order, 'unknown');

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/status' => Http::response(['message' => 'Payment not found'], 404),
        ]);

        $summary = app(PaymentReconciliationService::class)->run();

        $this->assertSame(1, $summary['checked']);
        $this->assertSame(1, $summary['still_ambiguous']);
        $this->assertSame('unknown', $attempt->fresh()->status);
        $this->assertSame('unknown', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payment_reconciliation_logs', [
            'payment_attempt_id' => $attempt->id,
            'outcome' => 'not_found_ambiguous',
            'http_status' => 404,
        ]);
        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_ambiguous_second_attempt_does_not_regress_order_paid_by_first_attempt(): void
    {
        $order = $this->createOrder();
        $order->update([
            'status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now()->subMinute(),
        ]);
        $paid = $this->createAttempt($order, 'paid', 1);
        $pending = $this->createAttempt($order, 'pending', 2);
        $order->outboxEvents()->create([
            'event_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => 'order.paid',
            'payload' => ['order_id' => $order->id],
            'status' => 'pending',
            'available_at' => now(),
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/status' => Http::response(['message' => 'Payment not found'], 404),
        ]);

        app(PaymentReconciliationService::class)->run();

        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame('unknown', $pending->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, OutboxEvent::where('order_id', $order->id)->where('event_type', 'order.paid')->count());
    }
}
