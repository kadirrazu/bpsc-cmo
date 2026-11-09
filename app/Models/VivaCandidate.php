<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class VivaCandidate extends Model
{
    protected $table = 'viva_candidates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['vdate' => 'date'];
    }

    public function board()
    {
        return $this->belongsTo(VivaBoard::class, 'viva_board_id');
    }

    public function setVdateAttribute($value): void
    {
        $this->attributes['vdate'] = Carbon::parse($value)->toDateString();
    }
}
