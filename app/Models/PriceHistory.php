<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'changed_by_admin_id',
        'old_base_cost',
        'new_base_cost',
        'old_selling_price',
        'new_selling_price',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_base_cost' => 'integer',
            'new_base_cost' => 'integer',
            'old_selling_price' => 'integer',
            'new_selling_price' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'changed_by_admin_id');
    }
}
