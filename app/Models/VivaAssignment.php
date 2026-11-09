<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class VivaAssignment extends Model
{
    protected $table = 'viva_assignments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['vdate' => 'date', 'member_snapshot' => 'array'];
    }

    public function board()
    {
        return $this->belongsTo(VivaBoard::class, 'viva_board_id');
    }

    public function member()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function setVdateAttribute($value): void
    {
        $this->attributes['vdate'] = Carbon::parse($value)->toDateString();
    }
}
