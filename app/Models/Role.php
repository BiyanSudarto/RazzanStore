<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['code', 'name'];

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }
}
