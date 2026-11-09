<?php

namespace App\Services\Evaluation;

use App\Models\Expert;

class ExpertLookup
{
    public function query(string $search = '', bool $historical = false)
    {
        return Expert::query()->when($historical, fn ($q) => $q->withTrashed(), fn ($q) => $q->where('is_active', true))->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('organization', 'like', '%'.$search.'%')->orWhere('specialization', 'like', '%'.$search.'%')->orWhere('designation', 'like', '%'.$search.'%')))->select(['id', 'name', 'type', 'designation', 'organization', 'specialization', 'is_active', 'deleted_at'])->orderBy('name')->orderBy('id');
    }

    public function item(Expert $expert): array
    {
        return $expert->only(['id', 'name', 'type', 'designation', 'organization', 'specialization', 'is_active', 'deleted_at']) + ['label' => $expert->name.($expert->designation?' · '.$expert->designation:'').' · '.($expert->type === 'departmental' ? 'Departmental Expert' : 'Expert').($expert->organization ? ' · '.$expert->organization : '').($expert->trashed() ? ' (removed)' : (! $expert->is_active ? ' (inactive)' : ''))];
    }
}
