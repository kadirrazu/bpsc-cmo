<?php

namespace App\Http\Controllers\Viva;

use App\Http\Controllers\Controller;
use App\Models\VivaBoard;
use App\Services\Library\Audit;
use App\Services\Viva\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends Controller
{
    public function __invoke(Request $r, Workspace $workspace)
    {
        $d = $r->validate(['vdate' => 'required|date_format:Y-m-d', 'post_code' => 'nullable|string|max:20']);
        $boards = VivaBoard::with('assignment.member')->withCount('candidates')->where('vdate', $d['vdate'])->when($d['post_code'] ?? null, fn ($q, $code) => $q->where('post_code', $code))->orderBy('post_code')->orderBy('board')->get();
        if ($boards->sum('candidates_count') > 20000) {
            throw ValidationException::withMessages(['report' => 'Download up to 20,000 candidates at once; select a single post code.']);
        }
        $book = new Spreadsheet;
        $summary = $book->getActiveSheet()->setTitle('Boards');
        $candidates = $book->createSheet()->setTitle('Candidates');
        $rows = [['Viva Date', 'Post Code', 'Post Name', 'Board', 'Assigned Member', 'Member Number', 'Floor', 'Room Number', 'Lift Button Number', 'Name of PO', 'Candidates', 'Assigned At (BST)']];
        $candidateRows = [['Viva Date', 'Post Code', 'Post Name', 'Board', 'Registration', 'Candidate Name', 'Assigned Member']];
        foreach ($boards as $b) {
            $a = $b->assignment;
            $member = $a ? ($a->member ? $workspace->member($a->member) : $a->member_snapshot) : [];
            $rows[] = [$d['vdate'], $b->post_code, $b->post_name, $b->board, $member['name'] ?? 'Unassigned', $member['member_number'] ?? '', $member['floor'] ?? '', $member['room_number'] ?? '', $member['lift_button_number'] ?? '', $member['name_of_po'] ?? '', $b->candidates_count, $a?->created_at?->timezone('Asia/Dhaka')->format('Y-m-d H:i:s') ?? ''];
            foreach ($b->candidates()->orderBy('reg')->cursor() as $candidate) {
                $candidateRows[] = [$d['vdate'], $b->post_code, $b->post_name, $b->board, $candidate->reg, $candidate->name, $member['name'] ?? 'Unassigned'];
            }
        }
        foreach ([[$summary, $rows], [$candidates, $candidateRows]] as [$sheet,$data]) {
            foreach ($data as $i => $row) {
                foreach ($row as $j => $value) {
                    $sheet->setCellValueExplicit([$j + 1, $i + 1], (string) ($value ?? ''), DataType::TYPE_STRING);
                }
            }
            $sheet->freezePane('A2');
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
            foreach (range(1, count($data[0])) as $c) {
                $sheet->getColumnDimensionByColumn($c)->setWidth(23);
            }
            $sheet->setAutoFilter('A1:'.$sheet->getHighestColumn().$sheet->getHighestRow());
        }
        File::ensureDirectoryExists(storage_path('app/private'), 0700);
        $path = tempnam(storage_path('app/private'), 'viva-report-');
        try {
            (new Xlsx($book))->save($path);
            app(Audit::class)->record('viva.distribution.report.exported', $d + ['boards' => $boards->count()]);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            $book->disconnectWorksheets();
        }

        return response()->download($path, 'viva-distribution-'.$d['vdate'].'.xlsx')->deleteFileAfterSend();
    }
}
