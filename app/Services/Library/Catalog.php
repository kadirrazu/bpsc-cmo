<?php

namespace App\Services\Library;

use App\Models\Unit;
use Illuminate\Validation\ValidationException;

class Catalog
{
    public function units(string $scope = 'users'): array
    {
        return Unit::where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('code')->all();
    }

    public function unitLabels(string $scope = 'users'): array
    {
        return Unit::where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'code')->all();
    }

    public function unitLabel(?string $code): string
    {
        if (! $code) {
            return __('Not assigned');
        }
        $request = request();
        if (! $request->attributes->has('library.unit_labels')) {
            $request->attributes->set('library.unit_labels', Unit::pluck('name', 'code')->all());
        }

        return $request->attributes->get('library.unit_labels')[$code] ?? $code;
    }

    public function lockUnit(string $code, string $scope = 'users'): void
    {
        $unit = Unit::where('code', $code)->lockForUpdate()->first();
        if (! $unit || ! $unit->is_active) {
            throw ValidationException::withMessages(['unit' => 'Select an active unit before saving.']);
        }
    }
}
