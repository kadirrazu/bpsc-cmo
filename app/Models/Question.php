<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use SoftDeletes;

    protected $fillable = ['survey_id', 'hints', 'rating_min', 'rating_max', 'prompt', 'type', 'options', 'is_required', 'is_active'];

    protected function casts(): array
    {
        return ['rating_min' => 'integer', 'rating_max' => 'integer', 'options' => 'array', 'is_required' => 'boolean', 'is_active' => 'boolean'];
    }

    public const TYPES = ['select' => 'Select', 'text' => 'Text', 'textarea' => 'Text Area', 'radio' => 'Radio Button', 'multiple' => 'Multiple Select', 'checkbox' => 'Checkbox', 'date' => 'Date Field', 'time' => 'Time Field', 'rating' => 'Rating'];

    public function surveys()
    {
        return $this->belongsToMany(Survey::class)->withPivot('position');
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public static function fingerprint($questions): string
    {
        return hash('sha256', json_encode($questions->map(fn ($q) => $q->only(['id', 'hints', 'rating_min', 'rating_max', 'prompt', 'type', 'options', 'is_required']))->values()->all()));
    }
}
