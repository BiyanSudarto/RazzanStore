<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    protected $fillable = [
        'order_id',
        'attempt_no',
        'provider',
        'provider_order_id',
        'provider_transaction_id',
        'status',
        'amount',
        'currency',
        'snap_token',
        'redirect_url',
        'provider_metadata',
        'requested_at',
        'confirmed_at',
        'expires_at',
        'last_reconciled_at',
        'reconciliation_count',
    ];

    protected $hidden = [
        'snap_token',
        'provider_metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'snap_token' => 'encrypted',
            'provider_metadata' => 'array',
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'reconciliation_count' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
