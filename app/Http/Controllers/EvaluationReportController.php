<?php

namespace App\Http\Controllers;

use App\Models\Expert;
use App\Models\Survey;
use App\Services\Evaluation\ExpertLookup;
use App\Services\Evaluation\ExpertReport;
use App\Services\Library\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EvaluationReportController extends Controller
{
    public function index(Request $r, ExpertReport $reports, ExpertLookup $lookup)
    {
        Gate::authorize('view-expert-reports');
        $filters = $reports->filters($r);
        if (! empty($filters['export'])) {
            app(Audit::class)->record('evaluation.report.export', ['filters' => $filters]);

            return $reports->csv($filters, $filters['export']);
        }
        app(Audit::class)->record('evaluation.report.view', ['filters' => $filters]);
        $matching = Expert::withTrashed()->whereIn('id', $reports->query($filters)->select('expert_id')->distinct())->orderBy('name')->orderBy('id');
        $expertPages = (clone $matching)->paginate(1, ['*'], 'expert_page');
        if ($expertPages->isEmpty() && $expertPages->total() > 0) {
            $expertPages = (clone $matching)->paginate(1, ['*'], 'expert_page', $expertPages->lastPage());
        }
        $expertPages->appends(array_diff_key($filters, array_flip(['expert_page', 'submissions_page', 'export'])))->fragment('expert-summary');
        $currentReportExpert = $expertPages->first();
        $scope = $filters;
        if ($currentReportExpert) {
            $scope['expert_id'] = $currentReportExpert->id;
        }
        $query = $reports->query($scope);
        if (! $currentReportExpert) {
            $query->whereRaw('1=0');
        }
        $submissions = (clone $query)->latest('id')->paginate($filters['per_page'] ?? 20, ['*'], 'submissions_page');
        if ($submissions->isEmpty() && $submissions->total() > 0) {
            $submissions = (clone $query)->latest('id')->paginate($filters['per_page'] ?? 20, ['*'], 'submissions_page', $submissions->lastPage());
        }
        $submissions->appends(array_merge(array_diff_key($filters, array_flip(['submissions_page', 'export'])), ['expert_page' => $expertPages->currentPage()]))->fragment('expert-submissions');
        $summary = $currentReportExpert ? $reports->summary($scope) : ['total' => 0, 'expert_count' => 0, 'survey_count' => 0, 'rows' => []];
        $experts = $lookup->query(trim($filters['expert_q'] ?? ''), true)->limit(20)->get();
        $selectedReportExpert = ! empty($filters['expert_id']) ? $lookup->query('', true)->where('id', $filters['expert_id'])->first() : null;
        if ($selectedReportExpert && ! $experts->contains('id', $selectedReportExpert->id)) {
            $experts->prepend($selectedReportExpert);
        }

        return view('reports.experts', compact('filters', 'summary', 'submissions', 'experts', 'selectedReportExpert', 'expertPages', 'currentReportExpert') + ['surveys' => Survey::withTrashed()->orderBy('title')->get()]);
    }

    public function expertOptions(Request $r, ExpertLookup $lookup)
    {
        Gate::authorize('view-expert-reports');
        $r->validate(['q' => 'nullable|string|max:150', 'page' => 'nullable|integer|min:1|max:10000']);
        $results = $lookup->query(trim((string) $r->input('q', '')), true)->simplePaginate(20);

        return response()->json(['data' => $results->getCollection()->map(fn ($e) => $lookup->item($e))->values(), 'has_more' => $results->hasMorePages(), 'next_page' => $results->hasMorePages() ? $results->currentPage() + 1 : null]);
    }
}
