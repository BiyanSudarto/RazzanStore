<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider',
        'event_fingerprint',
        'payment_attempt_id',
        'provider_order_id',
        'provider_transaction_id',
        'event_type',
        'provider_status',
        'payload_hash',
        'signature_valid',
        'processing_status',
        'safe_metadata',
        'received_at',
        'processed_at',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'safe_metadata' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }
}
