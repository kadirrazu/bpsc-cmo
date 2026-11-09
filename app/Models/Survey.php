<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Survey extends Model
{
    use SoftDeletes;

    protected $fillable = ['title', 'description', 'status', 'allowed_roles', 'created_by'];

    protected function casts(): array
    {
        return ['allowed_roles' => 'array'];
    }

    public function questions()
    {
        return $this->belongsToMany(Question::class)->withPivot('position');
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }

    public function canAttend(User $user): bool
    {
        return $user->can('evaluate-experts') && $this->status === 'active' && in_array($user->role->value, $this->allowed_roles ?? [], true);
    }

    public function scopeAvailableTo($query, User $user)
    {
        return $query->where('status', 'active')->whereJsonContains('allowed_roles', $user->role->value);
    }
}
