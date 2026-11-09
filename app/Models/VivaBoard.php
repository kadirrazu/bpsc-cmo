<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class VivaBoard extends Model
{
    protected $table = 'viva_boards';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['vdate' => 'date'];
    }

    public function candidates()
    {
        return $this->hasMany(VivaCandidate::class);
    }

    public function assignment()
    {
        return $this->hasOne(VivaAssignment::class);
    }

    public function setVdateAttribute($value): void
    {
        $this->attributes['vdate'] = Carbon::parse($value)->toDateString();
    }
}
