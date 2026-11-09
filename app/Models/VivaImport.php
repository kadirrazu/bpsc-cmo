<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VivaImport extends Model
{
    protected $table = 'viva_imports';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime'];
    }
}
