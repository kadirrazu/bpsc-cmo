<?php

namespace App\Services\Viva;

use App\Models\VivaAssignment;
use App\Services\Library\Audit;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class MyBoardDownload
{
    public function response(VivaAssignment $assignment, string $date, string $format)
    {
        $board = $assignment->board;
        $candidates = $board->candidates()->orderBy('reg')->get();
        $member = app(Workspace::class)->member(auth()->user());
        $data = compact('assignment', 'board', 'candidates', 'date', 'member');
        app(Audit::class)->record('viva.my-board.downloaded', ['assignment_id' => $assignment->id, 'format' => $format]);
        if ($format === 'print') {
            return response()->view('viva.my-board-print', $data + ['pdf' => false]);
        }
        $filename = 'my-viva-board-'.$date.'-'.$board->post_code.'-'.$board->board;
        if ($format === 'pdf') {
            $board->setRelation('candidates', $candidates);
            $content = app(CandidateReport::class)->pdf(collect([$board]), $member);

            return response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"']);
        }
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('My Viva Board');
        $rows = [['Viva Date', 'Post Code', 'Post Name', 'Unit', 'Ministry', 'Board', 'Registration', 'Candidate Name', 'Assigned Member']];
        foreach ($candidates as $candidate) {
            $rows[] = [$date, $board->post_code, $board->post_name, $board->unit, $board->ministry, $board->board, $candidate->reg, $candidate->name, $member['name']];
        }
        foreach ($rows as $i => $row) {
            foreach ($row as $j => $value) {
                $sheet->setCellValueExplicit([$j + 1, $i + 1], (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setWidth(in_array($column, ['C', 'E', 'H', 'I']) ? 35 : 22);
        }
        $sheet->setAutoFilter('A1:I'.$sheet->getHighestRow());
        File::ensureDirectoryExists(storage_path('app/private'), 0700);
        $path = tempnam(storage_path('app/private'), 'my-viva-');
        try {
            (new Xlsx($book))->save($path);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            $book->disconnectWorksheets();
        }

        return response()->download($path, $filename.'.xlsx')->deleteFileAfterSend();
    }
}
