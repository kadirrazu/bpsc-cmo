<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expert extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'type', 'designation', 'organization', 'specialization', 'email', 'mobile_number', 'biography', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }
}
