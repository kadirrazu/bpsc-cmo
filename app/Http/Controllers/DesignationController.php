<?php

namespace App\Http\Controllers;

use App\Models\Designation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DesignationController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('manage-system');
        $d = $r->validate(['search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $designations = Designation::withCount('users')->when($d['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))->orderBy('sort_order')->orderBy('name')->paginate(25)->withQueryString();

        return $r->is('api/*') ? response()->json($designations) : view('designations.index', compact('designations'));
    }

    public function create()
    {
        Gate::authorize('manage-system');

        return view('designations.form', ['designation' => new Designation(['is_active' => true])]);
    }

    public function store(Request $r)
    {
        Gate::authorize('manage-system');
        $data = $this->data($r);
        $data['slug'] = Str::limit(Str::slug($data['name']), 60, '').'-'.Str::lower(Str::random(10));
        $designation = DB::transaction(fn () => Designation::create($data));

        return $r->is('api/*') ? response()->json(['data' => $designation], 201) : redirect()->route('designations.index')->with('success', __('Designation created.'));
    }

    public function show(Request $r, Designation $designation)
    {
        Gate::authorize('manage-system');

        return response()->json(['data' => $designation]);
    }

    public function edit(Designation $designation)
    {
        Gate::authorize('manage-system');

        return view('designations.form', compact('designation'));
    }

    public function update(Request $r, Designation $designation)
    {
        Gate::authorize('manage-system');
        $data = $this->data($r, $designation);
        DB::transaction(fn () => $designation->update($data));

        return $r->is('api/*') ? response()->json(['data' => $designation->fresh()]) : redirect()->route('designations.index')->with('success', __('Designation updated.'));
    }

    public function destroy(Request $r, Designation $designation)
    {
        Gate::authorize('manage-system');
        Gate::authorize('manage-system');
        $r->validate(['confirmation' => 'required|in:DELETE']);
        DB::transaction(function () use ($designation) {
            $current = Designation::whereKey($designation->id)->lockForUpdate()->firstOrFail();
            if ($current->users()->withTrashed()->exists()) {
                throw ValidationException::withMessages(['designation' => __('This designation is assigned to user records. Deactivate it instead.')]);
            }
            $current->delete();
        });

        return $r->is('api/*') ? response()->noContent() : redirect()->route('designations.index')->with('success', __('Designation deleted.'));
    }

    private function data(Request $r, ?Designation $d = null): array
    {
        return $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('designations', 'name')->ignore($d?->id)], 'sort_order' => 'required|integer|min:0|max:255', 'is_active' => 'required|boolean']);
    }
}
