<?php

namespace App\Services\Viva;

use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ZipArchive;

class CandidateWorkbook
{
    public const HEADERS = ['post_code', 'post_name', 'unit', 'ministry', 'vdate', 'board', 'reg', 'name'];

    public function sample(bool $blank = false): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Candidates');
        $rows = [self::HEADERS];
        if (! $blank) {
            $date = app(Workspace::class)->today();
            $vdate = CarbonImmutable::parse($date)->format('dmY');
            $rows[] = ['250019', 'Fisheries Survey Officer', 'Unit 01', 'Ministry of Fisheries and Livestock', $vdate, '01', '00123456', 'Sample Candidate'];
            $rows[] = ['250019', 'Fisheries Survey Officer', 'Unit 01', 'Ministry of Fisheries and Livestock', $vdate, '01', '00123457', ''];
            $rows[] = ['250019', 'Fisheries Survey Officer', 'Unit 01', 'Ministry of Fisheries and Livestock', $vdate, '02', '00123458', ''];
        }
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, DataType::TYPE_STRING);
            }
        }
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setWidth($column === 'B' ? 36 : 22);
        }
        $sheet->getStyle('A2:H'.max(2, count($rows)))->getNumberFormat()->setFormatCode('@');

        return $book;
    }

    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('Upload a valid .xlsx workbook.');
        }
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $size += $zip->statIndex($i)['size'];
        } $zip->close();
        if ($size > 40 * 1024 * 1024) {
            $this->fail('Workbook expanded size exceeds 40 MB.');
        }
        $reader = new Xlsx;
        $reader->setReadDataOnly(false);
        $info = $reader->listWorksheetInfo($path);
        if (count($info) !== 1 || $info[0]['totalRows'] > 5001 || $info[0]['totalColumns'] > 8) {
            $this->fail('Use one worksheet with up to 5,000 candidate rows and the eight sample headers.');
        }
        $book = $reader->load($path);
        try {
            $sheet = $book->getActiveSheet();
            foreach (self::HEADERS as $c => $header) {
                $actual = trim((string) $sheet->getCell([$c + 1, 1])->getValue());
                if ($actual !== $header && ! ($header === 'name' && $actual === '')) {
                    $this->fail('Headers must be post_code, post_name, unit, ministry, vdate, board, reg, name (name is optional).');
                }
            }
            $rows = [];
            for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
                $row = [];
                foreach (self::HEADERS as $c => $header) {
                    $cell = $sheet->getCell([$c + 1, $r]);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $this->fail("Row $r: formulas are not supported; paste values instead.");
                    }
                    $value = $cell->getValue();
                    if (! is_scalar($value) && $value !== null) {
                        $this->fail("Row $r: unsupported cell value.");
                    }
                    // Honour explicitly formatted zero-padding; never use numeric conversion for registration.
                    if (in_array($header, ['reg', 'board', 'post_code'], true) && is_numeric($value) && preg_match('/^0+$/', $cell->getStyle()->getNumberFormat()->getFormatCode())) {
                        $value = $cell->getFormattedValue();
                    }
                    $row[$header] = trim((string) $value);
                    if (mb_strlen($row[$header]) > 1000) {
                        $this->fail("Row $r: field is too long.");
                    }
                }
                if (! array_filter($row, fn ($v) => $v !== '')) {
                    continue;
                }
                $row['_row'] = $r;
                $rows[] = $row;
            }
            if (! $rows) {
                $this->fail('No candidate rows found.');
            }

            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function date(string $value): string
    {
        if (in_array(strlen($value), [5, 7], true) && ctype_digit($value)) {
            $value = '0'.$value;
        }
        if (! preg_match('/^(\d{2})(\d{2})(\d{2}|\d{4})$/', $value, $m)) {
            $this->fail('vdate must be DDMMYY or DDMMYYYY, for example 091126 or 09112026.');
        }
        $year = (int) $m[3];
        if (strlen($m[3]) === 2) {
            $year += $year <= 69 ? 2000 : 1900;
        }
        if ($year < 1900 || $year > 2099 || ! checkdate((int) $m[2], (int) $m[1], $year)) {
            $this->fail('vdate is not a valid calendar date (supported years 1900–2099).');
        }

        return sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
    }

    public function plan(array $rows): array
    {
        $plan = ['items' => [], 'errors' => [], 'create' => 0, 'update' => 0, 'dates' => [], 'versions' => []];
        $seen = [];
        $posts = [];
        foreach ($rows as $row) {
            try {
                Validator::make($row, ['post_code' => 'required|string|regex:/^[0-9]{1,20}$/', 'post_name' => 'required|string|max:255', 'unit' => 'nullable|string|max:255', 'ministry' => 'nullable|string|max:255', 'vdate' => 'required|string', 'board' => 'required|string|regex:/^[0-9]{1,3}$/', 'reg' => 'required|string|regex:/^[0-9]{1,40}$/', 'name' => 'nullable|string|max:255'])->validate();
                $date = $this->date($row['vdate']);
                $board = str_pad(ltrim($row['board'], '0'), 2, '0', STR_PAD_LEFT);
                if ((int) $board < 1) {
                    $this->fail('Board/group must be between 01 and 999.');
                }
                $key = $date.'|'.$row['post_code'].'|'.$row['reg'];
                if (isset($seen[$key])) {
                    $this->fail('Duplicate registration for the same viva date and post code in this workbook.');
                } $seen[$key] = true;
                $post = $date.'|'.$row['post_code'];
                $row['unit'] = $row['unit'] ?? '';
                $row['ministry'] = $row['ministry'] ?? '';
                $metadata = array_intersect_key($row, array_flip(['post_name', 'unit', 'ministry']));
                if (isset($posts[$post]) && $posts[$post] !== $metadata) {
                    $this->fail('A post code must use one post name, unit and ministry on the same date.');
                } $posts[$post] = $metadata;
                if (VivaBoard::where('vdate', $date)->where('post_code', $row['post_code'])->where('post_name', '!=', $row['post_name'])->exists()) {
                    $this->fail('Post name conflicts with existing imported boards for this date/post code.');
                }
                foreach (['unit', 'ministry'] as $field) {
                    if (VivaBoard::where('vdate', $date)->where('post_code', $row['post_code'])->whereNotNull($field)->where($field, '!=', $row[$field])->exists()) {
                        $this->fail(ucfirst($field).' conflicts with existing boards for this date/post code.');
                    }
                }
                $target = VivaBoard::where(['vdate' => $date, 'post_code' => $row['post_code'], 'board' => $board])->first();
                $existing = VivaCandidate::where(['vdate' => $date, 'post_code' => $row['post_code'], 'reg' => $row['reg']])->first();
                if (($target && $target->assignment()->exists()) || ($existing && VivaAssignment::where('viva_board_id', $existing->viva_board_id)->exists())) {
                    $this->fail('This board has already been assigned. Candidate data cannot change after distribution.');
                }
                $item = $row;
                $item['date'] = $date;
                $item['board'] = $board;
                $item['id'] = $existing?->id;
                $item['action'] = $existing ? 'update' : 'create';
                $plan['items'][] = $item;
                $plan[$item['action']]++;
                $plan['dates'][$date] = true;
            } catch (ValidationException $e) {
                $plan['errors'][] = ['row' => $row['_row'], 'message' => implode(' ', $e->validator->errors()->all())];
            }
        }
        $plan['dates'] = array_keys($plan['dates']);
        sort($plan['dates']);
        foreach ($plan['dates'] as $date) {
            $plan['versions'][$date] = app(Workspace::class)->version($date);
        }

        return $plan;
    }

    public function apply(array $plan): void
    {
        foreach ($plan['items'] as $item) {
            $board = VivaBoard::firstOrCreate(['vdate' => $item['date'], 'post_code' => $item['post_code'], 'board' => $item['board']], ['post_name' => $item['post_name'], 'unit' => $item['unit'] !== '' ? $item['unit'] : null, 'ministry' => $item['ministry'] !== '' ? $item['ministry'] : null]);
            $board->update(['unit' => $item['unit'] !== '' ? $item['unit'] : null, 'ministry' => $item['ministry'] !== '' ? $item['ministry'] : null]);
            VivaCandidate::updateOrCreate(['vdate' => $item['date'], 'post_code' => $item['post_code'], 'reg' => $item['reg']], ['viva_board_id' => $board->id, 'name' => $item['name'] ?: null]);
        }
        // Moving candidates before assignment may leave an empty group; remove it.
        VivaBoard::whereIn('vdate', $plan['dates'])->whereDoesntHave('candidates')->whereDoesntHave('assignment')->delete();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
