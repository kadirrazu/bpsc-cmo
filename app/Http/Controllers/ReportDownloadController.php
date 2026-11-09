<?php

namespace App\Http\Controllers;

use App\Services\Evaluation\ExpertReport;
use App\Services\Evaluation\ReportDownload;
use App\Services\Library\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportDownloadController extends Controller
{
    public function __invoke(Request $request, ExpertReport $reports, ReportDownload $downloads)
    {
        $data = $request->validate([
            'format' => 'required|in:xlsx,pdf', 'scope' => 'required|in:filtered,selected',
            'expert_ids' => 'required_if:scope,selected|array|max:500',
            'expert_ids.*' => 'required|integer|min:1|distinct|exists:experts,id',
        ]);
        $filters = $reports->filters($request);
        unset($filters['export'], $filters['expert_page'], $filters['submissions_page'], $filters['activity_page'], $filters['per_page'], $filters['expert_ids']);
        if ($data['scope'] === 'selected') {
            unset($filters['expert_id']);
            $filters['expert_ids'] = $data['expert_ids'];
        }
        $response = DB::transaction(fn () => $downloads->download($filters, $data['format']));
        app(Audit::class)->record('evaluation.report.export', ['filters' => $filters, 'format' => $data['format'], 'scope' => $data['scope']]);

        return $response;
    }
}
