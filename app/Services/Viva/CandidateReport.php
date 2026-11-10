<?php

namespace App\Services\Viva;

use App\Services\Evaluation\ReportLanguageToFont;
use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class CandidateReport
{
    public function pdf($boards, ?array $member = null, array $membersByBoard = []): string
    {
        File::ensureDirectoryExists(storage_path('app/private/mpdf'), 0700);
        $config = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();
        $pdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P', 'margin_left' => 12, 'margin_right' => 12,
            'margin_top' => 20, 'margin_bottom' => 18, 'margin_header' => 10, 'setAutoTopMargin' => 'stretch', 'autoMarginPadding' => 4, 'tempDir' => storage_path('app/private/mpdf'),
            'fontDir' => array_merge($config['fontDir'], [public_path('fonts')]),
            'fontdata' => $fonts['fontdata'] + ['nikosh' => ['R' => 'Nikosh.ttf', 'useOTL' => 0xFF]],
            'default_font' => 'dejavusans', 'default_font_size' => 10,
            'autoScriptToLang' => true, 'autoLangToFont' => true, 'languageToFont' => new ReportLanguageToFont,
        ]);
        $pdf->SetTitle('Viva Board Candidate Lists');
        $pdf->SetAuthor('Bangladesh Public Service Commission');
        $pdf->WriteHTML(view('viva.reports.styles', ['pdf' => true])->render(), HTMLParserMode::HEADER_CSS);
        $pdf->SetHTMLFooter(view('viva.reports.footer')->render());
        foreach ($boards->values() as $index => $board) {
            $header = '<div style="font-size:8.5pt;color:#58645c;margin-bottom:6px">Bangladesh Public Service Commission (BPSC) | Chairman Office</div>'
                .view('viva.reports.board-heading', ['board' => $board, 'pdf' => true, 'member' => $membersByBoard[$board->id] ?? $member])->render();
            $pdf->SetHTMLHeader($header);
            if ($index > 0) {
                $pdf->AddPage();
            }
            $pdf->WriteHTML(view('viva.imports.report', ['boards' => collect([$board]), 'pdf' => true, 'pdfBodyOnly' => true])->render());
        }

        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
