<?php

namespace App\Services\Evaluation;

use App\Models\Expert;
use App\Models\Survey;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class ReportDownload
{
    public function __construct(private ExpertReport $reports) {}

    public function download(array $filters, string $format)
    {
        $query = $this->reports->query($filters);
        $limit = $format === 'pdf' ? 500 : 5000;
        if ((clone $query)->count() > $limit) {
            throw ValidationException::withMessages(['download' => strtoupper($format)." supports up to $limit submissions per download. Narrow the date, survey or expert filters."]);
        }
        $evaluations = $query->orderBy('expert_id')->orderBy('viva_date')->orderBy('id')->get();
        if ($evaluations->sum(fn ($v) => count($v->answers)) > ($format === 'pdf' ? 5000 : 25000)) {
            throw ValidationException::withMessages(['download' => 'This report has too many answer rows for one download. Narrow the filters and download in batches.']);
        }
        $ids = $filters['expert_ids'] ?? (! empty($filters['expert_id']) ? [$filters['expert_id']] : $evaluations->pluck('expert_id')->unique()->all());
        $experts = Expert::withTrashed()->whereIn('id', $ids)->orderBy('name')->orderBy('id')->get();
        $data = [
            'filters' => $this->labels($filters), 'generated' => now()->format('d M Y, h:i A T'),
            'statistics' => $this->reports->statistics($filters), 'summary' => $this->reports->summary($filters),
            'evaluations' => $evaluations, 'experts' => $experts,
            'activity' => $this->reports->activity($filters)->get(),
        ];
        File::ensureDirectoryExists(storage_path('app/private'), 0700);
        $path = tempnam(storage_path('app/private'), 'expert-report-');
        $name = 'expert-evaluation-report-'.now()->format('Ymd-His').'.'.$format;
        try {
            if ($format === 'xlsx') {
                $book = $this->workbook($data);
                try {
                    (new Xlsx($book))->save($path);
                } finally {
                    $book->disconnectWorksheets();
                }
            } else {
                $this->pdf($data, $path);
            }

            return response()->download($path, $name, ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }
    }

    public function labels(array $filters): array
    {
        $labels = [];
        if (! empty($filters['expert_ids'])) {
            $names = Expert::withTrashed()->whereIn('id', $filters['expert_ids'])->orderBy('name')->pluck('name');
            $labels['Experts'] = $names->count().' selected: '.$names->take(6)->map(fn ($name) => Str::limit($name, 80))->implode(', ').($names->count() > 6 ? ' (remaining experts are listed in the report)' : '');
        } else {
            $labels['Expert'] = ! empty($filters['expert_id']) ? (Expert::withTrashed()->find($filters['expert_id'])?->name ?? 'Unknown expert') : 'All matching experts';
        }
        $labels['Pool type'] = ($filters['type'] ?? '') === 'departmental' ? 'Departmental Expert' : (($filters['type'] ?? '') === 'expert' ? 'Expert' : 'All types');
        $labels['Survey'] = ! empty($filters['survey_id']) ? (Survey::withTrashed()->find($filters['survey_id'])?->title ?? 'Unknown survey') : 'All surveys';
        $labels['Viva date from'] = $filters['from'] ?? 'Any date';
        $labels['Viva date to'] = $filters['to'] ?? 'Any date';
        $labels['Evaluator role'] = $filters['evaluator_role'] ?? 'All evaluator roles';

        return $labels;
    }

    public function workbook(array $data): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $overview = [['BPSC CMO — Expert Evaluation System', 'Expert Evaluation Report'], ['Generated', $data['generated']]];
        foreach ($data['filters'] as $label => $value) {
            $overview[] = [$label, $value];
        }
        $overview[] = ['Statistics scope', 'Expert/participant/submission counts follow report filters. Survey status counts describe the current workspace.'];
        foreach ($data['statistics'] as $label => $value) {
            $overview[] = [str_replace('_', ' ', ucfirst($label)), $value];
        }
        $overview[] = ['Rating averages', 'Skipped answers excluded. Different wording/options/scales are separate rows. Multiple-select distributions count selections.'];
        $this->sheet($book, 'Overview', ['Item', 'Value'], $overview);
        $profiles = [];
        foreach ($data['experts'] as $expert) {
            $submissions = $data['evaluations']->where('expert_id', $expert->id);
            $profiles[] = [$expert->id, $expert->name, $expert->type === 'departmental' ? 'Departmental Expert' : 'Expert', $expert->designation, $expert->organization, $expert->specialization, $expert->trashed() ? 'Removed' : ($expert->is_active ? 'Active' : 'Inactive'), $submissions->count(), $submissions->pluck('user_id')->unique()->count(), $submissions->pluck('survey_id')->unique()->count()];
        }
        $this->sheet($book, 'Experts', ['ID', 'Expert', 'Pool Type', 'Designation', 'Organization', 'Specialization', 'Profile Status', 'Submissions', 'Participants', 'Surveys'], $profiles);
        $summary = [];
        foreach ($data['summary']['rows'] as $row) {
            $summary[] = [$row['expert_id'], $row['expert_name'], $row['prompt'], $row['type'], $row['min'], $row['max'], $row['answered'], $row['skipped'], $row['average'], json_encode($row['distribution'], JSON_UNESCAPED_UNICODE)];
        }
        $this->sheet($book, 'Question Summary', ['Expert ID', 'Expert', 'Question', 'Type', 'Minimum', 'Maximum', 'Answered', 'Skipped', 'Rating Average', 'Distribution'], $summary);
        $submissions = [];
        $answers = [];
        foreach ($data['evaluations'] as $v) {
            $submissions[] = [$v->id, $v->expert_id, $v->expert_snapshot['name'], $v->expert_snapshot['type'] ?? '', $v->survey_snapshot['title'] ?? 'Initial Expert Evaluation', $v->evaluator_snapshot['name'], $v->evaluator_snapshot['role'], $v->viva_date->format('Y-m-d'), $v->submitted_at->format('Y-m-d H:i:s'), $v->reference];
            foreach ($v->answers as $answer) {
                $answers[] = [$v->id, $v->expert_id, $v->expert_snapshot['name'], $v->survey_snapshot['title'] ?? 'Initial Expert Evaluation', $v->evaluator_snapshot['name'], $v->viva_date->format('Y-m-d'), $answer['question_id'], $answer['prompt'], $answer['hints'] ?? '', $answer['type'], $answer['rating_min'] ?? '', $answer['rating_max'] ?? '', $this->answer($answer)];
            }
        }
        $this->sheet($book, 'Submissions', ['Response ID', 'Expert ID', 'Expert at Submission', 'Pool Type', 'Survey', 'Evaluator', 'Role', 'Viva Date', 'Submitted At', 'Reference'], $submissions);
        $this->sheet($book, 'Answers', ['Response ID', 'Expert ID', 'Expert at Submission', 'Survey', 'Evaluator', 'Viva Date', 'Question ID', 'Question', 'Hints', 'Type', 'Minimum', 'Maximum', 'Answer'], $answers);
        $activity = $data['activity']->map(fn ($s) => [$s->id, $s->title, $s->trashed() ? 'Removed' : ucfirst($s->status), (int) $s->submissions, (int) $s->evaluated_experts, (int) $s->participants, $s->latest_viva_date])->all();
        $this->sheet($book, 'Survey Activity', ['Survey ID', 'Survey', 'Current Status', 'Submissions', 'Evaluated Experts', 'Participants', 'Latest Viva Date'], $activity);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function sheet(Spreadsheet $book, string $name, array $headers, array $rows): void
    {
        $sheet = $book->createSheet()->setTitle($name);
        foreach (array_merge([$headers], $rows) as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                if (is_int($value) || is_float($value)) {
                    $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) ($value ?? ''), DataType::TYPE_STRING);
                }
            }
        }
        $sheet->freezePane('A2');
        $last = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType('solid')->getStartColor()->setARGB('FF176D62');
        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setWidth(26);
        }
        $sheet->setAutoFilter('A1:'.$last.$sheet->getHighestRow());
        $sheet->getStyle('A1:'.$last.$sheet->getHighestRow())->getAlignment()->setWrapText(true)->setVertical('top');
    }

    public function answer(array $answer): string
    {
        $value = $answer['value'];
        if ($value === null || $value === '' || $value === []) {
            return 'Skipped';
        }
        if ($answer['type'] === 'checkbox') {
            return (string) $value === '1' ? 'Checked' : 'Unchecked';
        }

        return is_array($value) ? implode(' | ', $value) : (string) $value;
    }

    private function pdf(array $data, string $path): void
    {
        $config = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();
        File::ensureDirectoryExists(storage_path('app/private/mpdf'), 0700);
        $pdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12,
            'margin_top' => 20, 'margin_bottom' => 18, 'tempDir' => storage_path('app/private/mpdf'),
            'fontDir' => array_merge($config['fontDir'], [public_path('fonts')]),
            'fontdata' => $fonts['fontdata'] + ['nikosh' => ['R' => 'Nikosh.ttf', 'useOTL' => 0xFF]],
            'default_font' => 'dejavusans', 'default_font_size' => 10,
            'autoScriptToLang' => true, 'autoLangToFont' => true, 'languageToFont' => new ReportLanguageToFont,
        ]);
        $pdf->SetTitle('Expert Evaluation Report');
        $pdf->SetAuthor('Bangladesh Public Service Commission');
        $pdf->DefHTMLHeaderByName('reportheader', '<div style="font-size:10pt;color:#176d62;border-bottom:1px solid #d4e1dc;padding-bottom:5px">Bangladesh Public Service Commission (BPSC) | Chairman Office</div>');
        $pdf->DefHTMLFooterByName('reportfooter', '<table width="100%" style="font-family:dejavusans;font-size:8pt;color:#677b71;border-top:1px solid #d4e1dc"><tr><td style="border:0;text-align:left">IT Section, BPSC</td><td style="border:0;text-align:right">Page {PAGENO} of {nbpg}</td></tr></table>');
        $pdf->WriteHTML(view('reports.pdf', $data)->render());
        $pdf->Output($path, Destination::FILE);
    }
}
