<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VivaPool extends Model
{
    protected $table = 'viva_pools';

    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'viva_pool_user', 'viva_pool_id', 'user_id');
    }
}
