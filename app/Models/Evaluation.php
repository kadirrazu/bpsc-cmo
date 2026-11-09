<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Evaluation extends Model
{
    protected $fillable = ['survey_id', 'survey_snapshot', 'expert_id', 'user_id', 'viva_date', 'reference', 'expert_snapshot', 'evaluator_snapshot', 'answers', 'submitted_at'];

    protected function casts(): array
    {
        return ['survey_snapshot' => 'array', 'viva_date' => 'date', 'submitted_at' => 'datetime', 'expert_snapshot' => 'array', 'evaluator_snapshot' => 'array', 'answers' => 'array'];
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class)->withTrashed();
    }

    public function expert()
    {
        return $this->belongsTo(Expert::class)->withTrashed();
    }

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
