<?php

namespace App\Http\Controllers\Viva;

use App\Http\Controllers\Controller;
use App\Models\VivaBoard;
use App\Services\Library\Audit;
use App\Services\Viva\CandidateReport;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CandidateReportController extends Controller
{
    public function __invoke(Request $request, CandidateReport $report)
    {
        $filters = $request->validate(['vdate' => 'required|date_format:Y-m-d', 'post_code' => 'nullable|string|max:20', 'board_id' => 'nullable|integer|min:1', 'format' => 'required|in:pdf']);
        $boards = VivaBoard::withCount('candidates')->where('vdate', $filters['vdate'])
            ->when($filters['post_code'] ?? null, fn ($query, $code) => $query->where('post_code', $code))
            ->when($filters['board_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->orderBy('post_code')->orderByRaw('length(board)')->orderBy('board')->get();
        abort_if($boards->isEmpty(), 404, 'No imported boards match these filters.');
        if ($boards->sum('candidates_count') > 20000) {
            throw ValidationException::withMessages(['report' => 'Select one post code or board to download up to 20,000 candidates at once.']);
        }
        // Registration is an identifier: compare numeric magnitude without converting
        // to an integer/float, which would drop zeroes or overflow long registrations.
        $boards->load('candidates');
        foreach ($boards as $board) {
            $board->setRelation('candidates', $board->candidates->sort(function ($a, $b) {
                $left = ltrim($a->reg, '0');
                $right = ltrim($b->reg, '0');

                return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right) ?: strcmp($a->reg, $b->reg) ?: ($a->id <=> $b->id);
            })->values());
        }
        app(Audit::class)->record('viva.candidates.report.exported', ['filters' => $filters, 'boards' => $boards->count(), 'candidates' => $boards->sum('candidates_count')]);

        return response($report->pdf($boards), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="viva-candidates-'.$filters['vdate'].'.pdf"']);
    }
}
