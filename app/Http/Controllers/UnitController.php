<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UnitController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('manage-system');
        $data = $r->validate(['search' => 'nullable|string|max:100']);
        $units = Unit::withCount('users')->when($data['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))->orderBy('sort_order')->orderBy('name')->paginate(30)->withQueryString();

        return view('units.index', compact('units'));
    }

    public function create()
    {
        Gate::authorize('manage-system');

        return view('units.form', ['unit' => new Unit(['is_active' => true, 'sort_order' => 0])]);
    }

    public function store(Request $r)
    {
        Gate::authorize('manage-system');
        $data = $this->data($r);
        $data['code'] = 'unit-'.Str::uuid();
        DB::transaction(fn () => Unit::create($data));

        return redirect()->route('units.index')->with('success', 'Unit created.');
    }

    public function edit(Unit $unit)
    {
        Gate::authorize('manage-system');

        return view('units.form', compact('unit'));
    }

    public function update(Request $r, Unit $unit)
    {
        Gate::authorize('manage-system');
        $data = $this->data($r, $unit);
        DB::transaction(function () use ($unit, $data) {
            $current = Unit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
            if (! $data['is_active'] && User::withTrashed()->where('unit', $current->code)->exists()) {
                throw ValidationException::withMessages(['is_active' => 'This unit is assigned to users. Reassign those users before deactivating it.']);
            }
            $current->update($data);
        });

        return redirect()->route('units.index')->with('success', 'Unit updated.');
    }

    public function destroy(Request $r, Unit $unit)
    {
        Gate::authorize('manage-system');
        $r->validate(['confirmation' => 'required|in:DELETE']);
        DB::transaction(function () use ($unit) {
            $current = Unit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
            if (User::withTrashed()->where('unit', $current->code)->exists()) {
                throw ValidationException::withMessages(['unit' => 'This unit is assigned to user records and cannot be deleted.']);
            }
            $current->delete();
        });

        return redirect()->route('units.index')->with('success', 'Unit deleted.');
    }

    private function data(Request $r, ?Unit $unit = null): array
    {
        return $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('units', 'name')->ignore($unit?->id)], 'sort_order' => 'required|integer|min:0|max:9999', 'is_active' => 'required|boolean']);
    }
}
