<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataImport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime'];
    }
}
