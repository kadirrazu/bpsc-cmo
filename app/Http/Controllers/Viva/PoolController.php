<?php

namespace App\Http\Controllers\Viva;

use App\Http\Controllers\Controller;
use App\Models\VivaAssignment;
use App\Models\VivaPool;
use App\Services\Library\Audit;
use App\Services\Viva\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PoolController extends Controller
{
    public function index()
    {
        return view('viva.pools.index', ['pools' => VivaPool::withCount('users')->latest('id')->paginate(15)]);
    }

    public function create(Workspace $workspace)
    {
        return view('viva.pools.form', ['pool' => new VivaPool(['is_active' => true]), 'members' => $workspace->orderedMembers($workspace->eligible()->get()), 'selected' => []]);
    }

    public function edit(VivaPool $pool, Workspace $workspace)
    {
        return view('viva.pools.form', ['pool' => $pool, 'members' => $workspace->orderedMembers($workspace->eligible()->get()), 'selected' => $pool->users()->pluck('users.id')->all()]);
    }

    public function store(Request $r, Workspace $workspace)
    {
        return $this->save($r, $workspace, new VivaPool);
    }

    public function update(Request $r, Workspace $workspace, VivaPool $pool)
    {
        return $this->save($r, $workspace, $pool);
    }

    private function save(Request $r, Workspace $workspace, VivaPool $pool)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:3000', 'is_active' => 'required|boolean', 'selection' => 'required|in:all_members,all_chairmen,all_eligible,selected', 'member_ids' => 'required_if:selection,selected|array|max:1000', 'member_ids.*' => 'required|integer|distinct|min:1']);
        DB::transaction(function () use ($d, $pool, $workspace, $r) {
            if ($pool->exists) {
                $pool = VivaPool::whereKey($pool->id)->lockForUpdate()->firstOrFail();
            }
            $query = $workspace->eligible();
            if ($d['selection'] === 'all_members') {
                $query->where('role', 'member');
            }
            if ($d['selection'] === 'all_chairmen') {
                $query->where('role', 'chairman');
            }
            if ($d['selection'] === 'selected') {
                $query->whereIn('id', $d['member_ids']);
            }
            $ids = $query->lockForUpdate()->pluck('id')->all();
            if (! $ids || ($d['selection'] === 'selected' && count($ids) !== count($d['member_ids']))) {
                throw ValidationException::withMessages(['member_ids' => 'Select active Member/Chairman users only. A pool needs at least one eligible member.']);
            }
            $before = $pool->exists ? $pool->users()->pluck('users.id')->all() : [];
            if (array_diff($before, $ids)) {
                $r->validate(['confirmation' => 'required|string|in:DELETE']);
            }
            $pool->fill(array_intersect_key($d, array_flip(['name', 'description', 'is_active'])));
            if (! $pool->exists) {
                $pool->created_by = $r->user()->id;
            }
            $pool->save();
            $pool->users()->sync($ids);
            app(Audit::class)->record('viva.pool.saved', ['pool_id' => $pool->id, 'name' => $pool->name, 'before' => $before, 'after' => $ids]);
        });

        return redirect()->route('viva.pools.index')->with('success', 'Member pool saved.');
    }

    public function removal(VivaPool $pool)
    {
        $pool->loadCount('users');
        $assignmentCount = VivaAssignment::where('viva_pool_id', $pool->id)->count();

        return view('viva.pools.removal', compact('pool', 'assignmentCount'));
    }

    public function destroy(Request $r, VivaPool $pool)
    {
        $r->validate(['confirmation' => 'required|string|in:DELETE']);
        DB::transaction(function () use ($pool) {
            $pool = VivaPool::whereKey($pool->id)->lockForUpdate()->firstOrFail();
            $pool->delete();
            app(Audit::class)->record('viva.pool.removed', ['pool_id' => $pool->id, 'name' => $pool->name]);
        });

        return redirect()->route('viva.pools.index')->with('success', 'Pool removed. Existing board assignments are retained.');
    }
}
