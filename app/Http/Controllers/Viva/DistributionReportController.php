<?php

namespace App\Http\Controllers\Viva;

use App\Http\Controllers\Controller;
use App\Models\VivaBoard;
use App\Services\Library\Audit;
use App\Services\Viva\CandidateReport;
use App\Services\Viva\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DistributionReportController extends Controller
{
    public function __invoke(Request $request, CandidateReport $pdf, Workspace $workspace)
    {
        $filters = $request->validate(['vdate' => 'required|date_format:Y-m-d', 'post_code' => 'nullable|string|max:20', 'board_id' => 'nullable|integer|min:1', 'format' => 'required|in:pdf,xlsx']);
        $boards = VivaBoard::with('assignment.member')->withCount('candidates')->where('vdate', $filters['vdate'])->whereHas('assignment')
            ->when($filters['post_code'] ?? null, fn ($q, $value) => $q->where('post_code', $value))
            ->when($filters['board_id'] ?? null, fn ($q, $value) => $q->whereKey($value))
            ->orderBy('post_code')->orderByRaw('length(board)')->orderBy('board')->get();
        abort_if($boards->isEmpty(), 404, 'No distributed boards match these filters.');
        if ($boards->sum('candidates_count') > 20000) {
            throw ValidationException::withMessages(['report' => 'Select one post or board to download up to 20,000 candidates at once.']);
        }
        $boards->load('candidates');
        $members = [];
        foreach ($boards as $board) {
            $board->setRelation('candidates', $board->candidates->sort(function ($a, $b) {
                $left = ltrim($a->reg, '0');
                $right = ltrim($b->reg, '0');

                return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right) ?: strcmp($a->reg, $b->reg) ?: ($a->id <=> $b->id);
            })->values());
            $members[$board->id] = $board->assignment->member ? $workspace->member($board->assignment->member) : $board->assignment->member_snapshot;
        }
        app(Audit::class)->record('viva.chairman.report.exported', ['filters' => $filters, 'boards' => $boards->modelKeys(), 'candidates' => $boards->sum('candidates_count')]);
        $name = 'viva-distribution-'.$filters['vdate'].(isset($filters['board_id']) ? '-board-'.$filters['board_id'] : '');
        if ($filters['format'] === 'pdf') {
            return response($pdf->pdf($boards, null, $members), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"']);
        }
        $book = new Spreadsheet;
        $summary = $book->getActiveSheet()->setTitle('Assignments');
        $candidates = $book->createSheet()->setTitle('Candidates');
        $summaryRows = [['Viva Date', 'Post Code', 'Post Name', 'Unit', 'Ministry', 'Board', 'Assigned Member', 'Member Number', 'Floor', 'Room Number', 'Lift Button Number', 'Name of PO', 'Candidates', 'Assigned At (BST)']];
        $candidateRows = [['Viva Date', 'Post Code', 'Post Name', 'Unit', 'Ministry', 'Board', 'SL', 'Registration', 'Candidate Name', 'Remarks / Notes', 'Assigned Member', 'Room Number']];
        foreach ($boards as $board) {
            $member = $members[$board->id];
            $summaryRows[] = [$filters['vdate'], $board->post_code, $board->post_name, $board->unit, $board->ministry, $board->board, $member['name'] ?? '', $member['member_number'] ?? '', $member['floor'] ?? '', $member['room_number'] ?? '', $member['lift_button_number'] ?? '', $member['name_of_po'] ?? '', $board->candidates_count, $board->assignment->created_at->timezone('Asia/Dhaka')->format('Y-m-d H:i:s')];
            foreach ($board->candidates as $index => $candidate) {
                $candidateRows[] = [$filters['vdate'], $board->post_code, $board->post_name, $board->unit, $board->ministry, $board->board, $index + 1, $candidate->reg, $candidate->name, '', $member['name'] ?? '', $member['room_number'] ?? ''];
            }
        }
        foreach ([[$summary, $summaryRows], [$candidates, $candidateRows]] as [$sheet,$rows]) {
            foreach ($rows as $r => $row) {
                foreach ($row as $c => $value) {
                    $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) ($value ?? ''), DataType::TYPE_STRING);
                }
            }
            $sheet->freezePane('A2');
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
            $sheet->setAutoFilter('A1:'.$sheet->getHighestColumn().$sheet->getHighestRow());
            foreach (range(1, count($rows[0])) as $column) {
                $sheet->getColumnDimensionByColumn($column)->setWidth(23);
            }
        }
        File::ensureDirectoryExists(storage_path('app/private'), 0700);
        $path = tempnam(storage_path('app/private'), 'viva-chairman-');
        try {
            (new Xlsx($book))->save($path);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            $book->disconnectWorksheets();
        }

        return response()->download($path, $name.'.xlsx')->deleteFileAfterSend();
    }
}
