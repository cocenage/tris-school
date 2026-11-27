<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DeviceLinkRequest extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'secret_hash', 'comparison_code', 'status', 'expires_at'];
    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
