<?php

namespace App\Observers;

use App\Models\User;
use App\Models\VivaCandidate;
use App\Services\Library\Audit;
use Illuminate\Database\Eloquent\Model;

class LibraryObserver
{
    private function fields(Model $m): array
    {
        return match (class_basename($m)) {
            'User' => ['member_number', 'floor', 'room_number', 'lift_button_number', 'name_of_po', 'name', 'email', 'mobile_number', 'designation_id', 'unit', 'role', 'is_active', 'preferred_locale', 'deleted_at'],
            'Unit' => ['code', 'name', 'sort_order', 'is_active'],
            'Expert' => ['name', 'type', 'designation', 'organization', 'specialization', 'email', 'mobile_number', 'biography', 'is_active', 'deleted_at'],
            'VivaBoard' => ['vdate', 'post_code', 'post_name', 'board', 'unit', 'ministry'],
            'VivaCandidate' => ['vdate', 'post_code', 'viva_board_id'],
            'Survey' => ['title', 'description', 'status', 'allowed_roles', 'deleted_at'],
            'Question' => ['hints', 'rating_min', 'rating_max', 'prompt', 'type', 'options', 'is_required', 'is_active', 'deleted_at'],
            default => ['name', 'slug', 'sort_order', 'is_active']
        };
    }

    private function snapshot(Model $m, bool $old = false): array
    {
        return array_intersect_key($old ? $m->getRawOriginal() : $m->getAttributes(), array_flip($this->fields($m)));
    }

    public function created(Model $m): void
    {
        app(Audit::class)->record(strtolower(class_basename($m)).'.created', ['after' => $this->snapshot($m)], $m);
    }

    public function updated(Model $m): void
    {
        $keys = array_intersect(array_keys($m->getChanges()), $this->fields($m));
        if ($m instanceof VivaCandidate && $m->wasChanged('name')) {
            $keys[] = 'name_changed';
        }
        if ($m instanceof User && $m->wasChanged('password')) {
            $keys[] = 'password_changed';
        }
        if ($keys) {
            app(Audit::class)->record(strtolower(class_basename($m)).'.updated', ['changed' => array_values($keys), 'before' => $this->snapshot($m, true), 'after' => $this->snapshot($m)], $m);
        }
    }

    public function deleted(Model $m): void
    {
        app(Audit::class)->record(strtolower(class_basename($m)).'.deleted', ['before' => $this->snapshot($m)], $m);
    }
}
