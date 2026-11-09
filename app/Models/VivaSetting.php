<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VivaSetting extends Model
{
    protected $table = 'viva_settings';

    protected $guarded = [];

    protected $hidden = ['display_password'];

    protected function casts(): array
    {
        return ['display_password' => 'hashed', 'auth_version' => 'integer'];
    }
}
