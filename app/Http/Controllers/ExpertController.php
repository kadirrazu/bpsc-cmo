<?php

namespace App\Http\Controllers;

use App\Models\Expert;
use App\Models\Survey;
use App\Services\Evaluation\ExpertReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ExpertController extends Controller
{
    private function listing(Request $r, $q)
    {
        $d = $r->validate(['q' => 'nullable|string|max:150', 'type' => 'nullable|in:expert,departmental']);

        return $q->when($d['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$s.'%')->orWhere('organization', 'like', '%'.$s.'%')->orWhere('specialization', 'like', '%'.$s.'%')))->when($d['type'] ?? null, fn ($q, $s) => $q->where('type', $s))->orderBy('name')->paginate(12)->withQueryString();
    }

    public function index(Request $r)
    {
        return view('experts.index', ['experts' => $this->listing($r, Expert::query())]);
    }

    public function create()
    {
        return view('experts.form', ['expert' => new Expert(['is_active' => true, 'type' => 'expert'])]);
    }

    public function store(Request $r)
    {
        $d = $this->data($r);
        $expert = DB::transaction(fn () => Expert::create($d));

        return redirect()->route('experts.show', $expert)->with('success', 'Expert added.');
    }

    public function show(Request $r, Expert $expert)
    {
        $report = null;
        $submissions = null;
        $surveys = collect();
        $filters = [];
        if ($r->user()->can('view-expert-reports')) {
            $service = app(ExpertReport::class);
            $filters = $service->filters($r);
            $filters['expert_id'] = $expert->id;
            $report = $service->summary($filters);
            $submissions = $service->query($filters)->latest('id')->paginate($filters['per_page'] ?? 20, ['*'], 'submissions_page')->appends(array_diff_key($filters, array_flip(['submissions_page', 'export'])))->fragment('expert-submissions');
            $surveys = Survey::withTrashed()->orderBy('title')->get();
        }

        return view('experts.show', compact('expert', 'report', 'submissions', 'surveys', 'filters'));
    }

    public function edit(Expert $expert)
    {
        return view('experts.form', compact('expert'));
    }

    public function update(Request $r, Expert $expert)
    {
        $d = $this->data($r);
        DB::transaction(fn () => Expert::whereKey($expert->id)->lockForUpdate()->firstOrFail()->update($d));

        return redirect()->route('experts.show', $expert)->with('success', 'Expert updated.');
    }

    public function destroy(Request $r, Expert $expert)
    {
        Gate::authorize('delete-experts');
        $r->validate(['confirmation' => 'required|in:DELETE']);
        DB::transaction(fn () => Expert::whereKey($expert->id)->lockForUpdate()->firstOrFail()->delete());

        return redirect()->route('experts.index')->with('success', 'Expert removed from the pool. Previous evaluations are retained.');
    }

    private function data(Request $r): array
    {
        return $r->validate(['name' => 'required|string|max:255', 'type' => ['required', Rule::in(['expert', 'departmental'])], 'designation' => 'nullable|string|max:255', 'organization' => 'nullable|string|max:255', 'specialization' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255', 'mobile_number' => 'nullable|string|max:30', 'biography' => 'nullable|string|max:5000', 'is_active' => 'required|boolean']);
    }
}
