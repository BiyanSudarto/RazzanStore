<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameField;
use App\Models\IdempotencyRecord;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('orders.tracking_token_key', str_repeat('T', 48));
        config()->set('midtrans.server_key', 'SB-Mid-server-test-key');
    }

    private function createProduct(int $price = 15000): Product
    {
        $game = Game::create([
            'slug' => 'mobile-legends',
            'name' => 'Mobile Legends',
            'is_active' => true,
        ]);

        GameField::create([
            'game_id' => $game->id,
            'field_key' => 'player_id',
            'label' => 'Player ID',
            'input_type' => 'text',
            'is_required' => true,
            'validation_rules' => ['min_length' => 3, 'max_length' => 20],
        ]);

        GameField::create([
            'game_id' => $game->id,
            'field_key' => 'server',
            'label' => 'Server',
            'input_type' => 'number',
            'is_required' => true,
            'validation_rules' => ['min' => 1, 'max' => 9999],
        ]);

        return Product::create([
            'game_id' => $game->id,
            'sku' => 'MLBB-86',
            'provider_sku' => 'DF-MLBB-86',
            'name' => '86 Diamonds',
            'nominal_label' => '86 Diamonds',
            'base_cost' => 12000,
            'selling_price' => $price,
            'is_active' => true,
        ]);
    }

    private function fakeSnapSuccess(): void
    {
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-test-123',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/test',
            ], 201),
        ]);
    }

    public function test_checkout_uses_server_price_and_creates_encrypted_snap_attempt(): void
    {
        $product = $this->createProduct();
        $this->fakeSnapSuccess();

        $response = $this->withHeader('Idempotency-Key', str_repeat('a', 32))
            ->postJson('/api/v1/checkout', [
                'product_id' => $product->id,
                'player_data' => [
                    'player_id' => '12345678',
                    'server' => '1234',
                ],
                'amount' => 1,
                'selling_price' => 1,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', 15000)
            ->assertJsonPath('data.currency', 'IDR')
            ->assertJsonPath('data.payment.provider', 'midtrans')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.snap_token', 'snap-token-test-123');

        $order = Order::firstOrFail();
        $attempt = PaymentAttempt::firstOrFail();

        $this->assertSame(15000, $order->total_amount);
        $this->assertSame(15000, $attempt->amount);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('snap-token-test-123', $attempt->snap_token);
        $this->assertNotSame('snap-token-test-123', $attempt->getRawOriginal('snap_token'));
        $this->assertSame(1, PaymentAttempt::count());
        $this->assertSame(1, IdempotencyRecord::count());
        Http::assertSentCount(1);
    }

    public function test_checkout_idempotent_replay_returns_same_order_and_does_not_create_new_attempt(): void
    {
        $product = $this->createProduct();
        $this->fakeSnapSuccess();
        $key = str_repeat('b', 32);
        $input = [
            'product_id' => $product->id,
            'player_data' => ['player_id' => '12345678', 'server' => '1234'],
        ];

        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', $input);
        $first->assertCreated();

        $second = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', $input);
        $second->assertOk();

        $this->assertSame($first->json('data.order_code'), $second->json('data.order_code'));
        $this->assertSame($first->json('data.tracking_token'), $second->json('data.tracking_token'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, PaymentAttempt::count());
        Http::assertSentCount(1);
    }

    public function test_reusing_idempotency_key_with_different_request_returns_conflict(): void
    {
        $product = $this->createProduct();
        $this->fakeSnapSuccess();
        $key = str_repeat('c', 32);

        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', [
            'product_id' => $product->id,
            'player_data' => ['player_id' => '12345678', 'server' => '1234'],
        ])->assertCreated();

        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', [
            'product_id' => $product->id,
            'player_data' => ['player_id' => '87654321', 'server' => '1234'],
        ])->assertConflict();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_checkout_rejects_invalid_dynamic_player_data(): void
    {
        $product = $this->createProduct();
        Http::fake();

        $this->withHeader('Idempotency-Key', str_repeat('d', 32))
            ->postJson('/api/v1/checkout', [
                'product_id' => $product->id,
                'player_data' => [
                    'player_id' => '12345',
                    'server' => 'not-a-number',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('player_data.server');

        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public function test_checkout_unknown_snap_create_is_not_repeated_on_idempotent_replay(): void
    {
        $product = $this->createProduct();
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::failedConnection(),
        ]);
        $key = str_repeat('e', 32);
        $input = [
            'product_id' => $product->id,
            'player_data' => ['player_id' => '12345678', 'server' => '1234'],
        ];

        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', $input);
        $first->assertStatus(202)
            ->assertJsonPath('data.payment.status', 'unknown');

        $second = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', $input);
        $second->assertStatus(202);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, PaymentAttempt::count());
        $this->assertSame('unknown', PaymentAttempt::firstOrFail()->status);
        Http::assertSentCount(1);
    }

    public function test_tracking_endpoint_returns_customer_safe_order_details(): void
    {
        $product = $this->createProduct();
        $this->fakeSnapSuccess();

        $response = $this->withHeader('Idempotency-Key', str_repeat('f', 32))
            ->postJson('/api/v1/checkout', [
                'product_id' => $product->id,
                'player_data' => ['player_id' => '12345678', 'server' => '1234'],
            ])->assertCreated();

        $token = $response->json('data.tracking_token');

        $this->getJson('/api/v1/orders/'.$token)
            ->assertOk()
            ->assertJsonPath('data.product', '86 Diamonds')
            ->assertJsonPath('data.amount', 15000)
            ->assertJsonMissingPath('data.tracking_token_hash')
            ->assertJsonMissingPath('data.payment_attempt.snap_token');
    }

    public function test_retry_creates_a_new_payment_attempt_only_after_definitive_failure(): void
    {
        $product = $this->createProduct();
        $sequence = Http::sequence()
            ->push(['error_messages' => ['Request rejected']], 400)
            ->push([
                'token' => 'snap-token-retry-456',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/retry',
            ], 201);

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => $sequence,
        ]);

        $initial = $this->withHeader('Idempotency-Key', str_repeat('g', 32))
            ->postJson('/api/v1/checkout', [
                'product_id' => $product->id,
                'player_data' => ['player_id' => '12345678', 'server' => '1234'],
            ]);

        $initial->assertStatus(502)
            ->assertJsonPath('data.payment.status', 'failed');

        $trackingToken = $initial->json('data.tracking_token');
        $this->assertSame(1, PaymentAttempt::count());
        $this->assertSame('failed', PaymentAttempt::firstOrFail()->status);

        $retry = $this->withHeader('Idempotency-Key', str_repeat('h', 32))
            ->postJson('/api/v1/orders/'.$trackingToken.'/payment-attempts');

        $retry->assertCreated()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.snap_token', 'snap-token-retry-456');

        $attempts = PaymentAttempt::orderBy('attempt_no')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame(1, $attempts[0]->attempt_no);
        $this->assertSame(2, $attempts[1]->attempt_no);
        $this->assertNotSame($attempts[0]->provider_order_id, $attempts[1]->provider_order_id);
        $this->assertSame('pending', Order::firstOrFail()->payment_status);
        Http::assertSentCount(2);
    }

    public function test_expired_idempotency_key_can_start_a_new_checkout_after_retention_window(): void
    {
        $product = $this->createProduct();
        $sequence = Http::sequence()
            ->push([
                'token' => 'snap-token-first-123',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/first',
            ], 201)
            ->push([
                'token' => 'snap-token-second-456',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/second',
            ], 201);

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => $sequence,
        ]);

        $key = str_repeat('j', 32);
        $input = [
            'product_id' => $product->id,
            'player_data' => ['player_id' => '12345678', 'server' => '1234'],
        ];

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/checkout', $input);
        $first->assertCreated();

        $this->travel(31)->days();

        $second = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/checkout', $input);
        $second->assertCreated();

        $this->assertNotSame($first->json('data.order_code'), $second->json('data.order_code'));
        $this->assertSame(2, Order::count());
        $this->assertSame(2, PaymentAttempt::count());
        $this->assertSame(1, IdempotencyRecord::count());
        Http::assertSentCount(2);
    }

}
