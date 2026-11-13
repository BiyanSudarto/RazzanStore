<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'public_order_code',
        'product_id',
        'game_id',
        'product_sku_snapshot',
        'product_name_snapshot',
        'game_name_snapshot',
        'nominal_label_snapshot',
        'player_data',
        'customer_contact',
        'unit_price',
        'quantity',
        'subtotal',
        'discount_amount',
        'total_amount',
        'currency',
        'status',
        'payment_status',
        'fulfillment_status',
        'tracking_token_hash',
        'expires_at',
        'paid_at',
        'completed_at',
    ];

    protected $hidden = [
        'tracking_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'player_data' => 'array',
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'total_amount' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('attempt_no');
    }

    public function idempotencyRecords(): HasMany
    {
        return $this->hasMany(IdempotencyRecord::class);
    }

    public function outboxEvents(): HasMany
    {
        return $this->hasMany(OutboxEvent::class);
    }
}
