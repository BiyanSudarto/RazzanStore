<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReconciliationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_attempt_id',
        'source',
        'http_status',
        'provider_status',
        'outcome',
        'response_hash',
        'safe_metadata',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'safe_metadata' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }
}
