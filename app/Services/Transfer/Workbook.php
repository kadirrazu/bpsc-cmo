<?php

namespace App\Services\Transfer;

use App\Models\Designation;
use App\Models\Expert;
use App\Models\Question;
use App\Models\Survey;
use App\Models\Unit;
use App\Models\User;
use App\Services\Viva\Workspace;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ZipArchive;

class Workbook
{
    public const MODELS = ['Designations' => Designation::class, 'Units' => Unit::class, 'Users' => User::class, 'Experts' => Expert::class, 'Questions' => Question::class, 'Surveys' => Survey::class];

    public const HEADERS = [
        'Designations' => ['key', 'name', 'sort_order', 'is_active'],
        'Units' => ['key', 'code', 'name', 'sort_order', 'is_active'],
        'Users' => ['key', 'name', 'email', 'mobile_number', 'designation_key', 'unit_key', 'role', 'is_active', 'password', 'password_hash', 'member_number', 'floor', 'room_number', 'lift_button_number', 'name_of_po'],
        'Experts' => ['key', 'name', 'type', 'designation', 'organization', 'specialization', 'email', 'mobile_number', 'biography', 'is_active'],
        'Questions' => ['key', 'prompt', 'hints', 'type', 'options', 'rating_min', 'rating_max', 'is_required', 'is_active'],
        'Surveys' => ['key', 'title', 'description', 'status', 'allowed_roles', 'question_keys'],
    ];

    public const SCOPES = [
        'all' => ['Designations', 'Units', 'Users', 'Experts', 'Questions', 'Surveys'],
        'users' => ['Designations', 'Units', 'Users'], 'designations' => ['Designations'], 'units' => ['Units'],
        'evaluation' => ['Experts', 'Questions', 'Surveys'], 'experts' => ['Experts'], 'questions' => ['Questions'], 'surveys' => ['Questions', 'Surveys'],
    ];

    public function sheets(string $scope, User $actor): array
    {
        abort_unless(isset(self::SCOPES[$scope]), 422);
        $sheets = self::SCOPES[$scope];
        $this->authorize($sheets, $actor);

        return $sheets;
    }

    public function authorize(array $sheets, User $actor): void
    {
        abort_unless($actor->is_active && $actor->can('manage-system'), 403);
        if (array_intersect($sheets, ['Users', 'Units', 'Designations'])) {
            abort_unless($actor->can('manage-system'), 403);
        }
    }

    public function export(array $sheets, bool $template, bool $credentials, bool $sample = false): Spreadsheet
    {
        $book = new Spreadsheet;
        $info = $book->getActiveSheet()->setTitle('Format');
        $info->fromArray([['format', 'version'], ['bpsc-cmo', '1'], ['Instructions', 'See docs/data-transfer.md. Do not rename headers. Use UUID keys to link records.'], ['Privacy', $credentials ? 'Contains password hashes. Keep this workbook private.' : 'No passwords exported. New imported users need Administrator password setup.'], ['Scope', 'Configuration and master data only; evaluation responses and audit history are not included.']]);
        $info->fromArray([
            ['Keys', 'Blank key = new row. Keep exported UUID keys to update records and preserve links.'],
            ['Boolean fields', 'Use 1 for yes/active/required and 0 for no/inactive/optional.'],
            ['Question types', 'select, text, textarea, radio, multiple, checkbox, date, time, rating'],
            ['Options', 'Use a JSON list, for example ["Yes","No"]. Select/radio/multiple require 2–50 distinct options.'],
            ['Survey roles', 'Use ["chairman","member"], ["chairman"] or ["member"].'],
            ['Survey question order', 'question_keys is a JSON list of Question keys in display order.'],
            ['Users', 'Reference active designation_key and unit_key; role is admin/chairman/member/staff.'],
            ['Passwords', 'Blank password/hash preserves existing passwords. New accounts need Administrator password setup unless supplied.'],
            ['Samples', 'Sample downloads contain examples only. Replace them with your own data before importing.'],
        ], null, 'A7');
        $info->getColumnDimension('A')->setWidth(26);
        $info->getColumnDimension('B')->setWidth(100);
        $info->getStyle('B1:B15')->getAlignment()->setWrapText(true);
        foreach ($sheets as $name) {
            $sheet = $book->createSheet()->setTitle($name);
            $sheet->fromArray(self::HEADERS[$name]);
            if ($sample) {
                foreach ($this->samples()[$name] as $index => $data) {
                    foreach (self::HEADERS[$name] as $col => $header) {
                        $sheet->setCellValueExplicit([$col + 1, $index + 2], (string) ($data[$header] ?? ''), DataType::TYPE_STRING);
                    }
                }
            } elseif (! $template) {
                foreach (self::MODELS[$name]::orderBy('id')->cursor() as $index => $model) {
                    $data = $model->getAttributes();
                    $data['key'] = $model->transfer_uuid;
                    if ($name === 'Users') {
                        $data['designation_key'] = $model->designation?->transfer_uuid;
                        $data['unit_key'] = Unit::where('code', $model->unit)->value('transfer_uuid');
                        $data['password'] = '';
                        $data['password_hash'] = $credentials ? $model->getRawOriginal('password') : '';
                    }
                    if ($name === 'Questions') {
                        $data['options'] = json_encode($model->options ?? [], JSON_UNESCAPED_UNICODE);
                    }
                    if ($name === 'Surveys') {
                        $data['allowed_roles'] = json_encode($model->allowed_roles, JSON_UNESCAPED_UNICODE);
                        $data['question_keys'] = json_encode($model->questions()->orderBy('question_survey.position')->pluck('questions.transfer_uuid')->all());
                    }
                    foreach (self::HEADERS[$name] as $col => $header) {
                        $sheet->setCellValueExplicit([$col + 1, $index + 2], (string) ($data[$header] ?? ''), DataType::TYPE_STRING);
                    }
                }
            }
            $sheet->freezePane('B2');
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF176D62');
            foreach (range(1, count(self::HEADERS[$name])) as $col) {
                $sheet->getColumnDimensionByColumn($col)->setWidth($col === 1 ? 39 : 26);
            }
            $sheet->setAutoFilter('A1:'.$sheet->getHighestColumn().max(1, $sheet->getHighestRow()));
        }
        $book->setActiveSheetIndex(1);

        return $book;
    }

    private function samples(): array
    {
        $d = '11111111-1111-4111-8111-111111111111';
        $u = '22222222-2222-4222-8222-222222222222';
        $q = '33333333-3333-4333-8333-333333333333';
        $q2 = '44444444-4444-4444-8444-444444444444';

        return [
            'Designations' => [['key' => $d, 'name' => 'Sample Designation', 'sort_order' => '100', 'is_active' => '1']],
            'Units' => [['key' => $u, 'code' => 'SAMPLE-UNIT', 'name' => 'Sample Unit', 'sort_order' => '100', 'is_active' => '1']],
            'Users' => [['key' => '55555555-5555-4555-8555-555555555555', 'name' => 'Sample Member', 'email' => 'sample.member@example.test', 'designation_key' => $d, 'unit_key' => $u, 'role' => 'member', 'is_active' => '1']],
            'Experts' => [
                ['key' => '66666666-6666-4666-8666-666666666666', 'name' => 'Sample Expert', 'type' => 'expert', 'designation' => 'Professor', 'organization' => 'Sample University', 'specialization' => 'Public Administration', 'email' => 'sample.expert@example.test', 'is_active' => '1'],
                ['key' => '77777777-7777-4777-8777-777777777777', 'name' => 'Sample Departmental Expert', 'type' => 'departmental', 'designation' => 'Director', 'organization' => 'Sample Department', 'is_active' => '1'],
            ],
            'Questions' => [
                ['key' => $q, 'prompt' => 'The expert demonstrated relevant subject knowledge.', 'hints' => '1 = Definitely no; 5 = Definitely yes', 'type' => 'rating', 'options' => '[]', 'rating_min' => '1', 'rating_max' => '5', 'is_required' => '1', 'is_active' => '1'],
                ['key' => $q2, 'prompt' => 'Overall recommendation', 'type' => 'select', 'options' => '["Recommended","Needs review"]', 'is_required' => '0', 'is_active' => '1'],
            ],
            'Surveys' => [['key' => '88888888-8888-4888-8888-888888888888', 'title' => 'Sample Expert Evaluation', 'description' => 'Replace sample data before importing.', 'status' => 'draft', 'allowed_roles' => '["chairman","member"]', 'question_keys' => json_encode([$q, $q2])]],
        ];
    }

    public function read(string $path, User $actor): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('Upload a valid .xlsx workbook.');
        }
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $bytes += $zip->statIndex($i)['size'];
        }
        $zip->close();
        if ($bytes > 40 * 1024 * 1024) {
            $this->fail('Workbook exceeds the 40 MB expanded size limit.');
        }
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $names = $reader->listWorksheetNames($path);
        if (array_diff($names, array_merge(['Format'], array_keys(self::HEADERS)))) {
            $this->fail('Workbook contains an unknown worksheet. Download a template first.');
        }
        $sheets = array_values(array_intersect(array_keys(self::HEADERS), $names));
        if (! $sheets) {
            $this->fail('No data worksheets found.');
        }
        $this->authorize($sheets, $actor);
        foreach ($reader->listWorksheetInfo($path) as $info) {
            if ($info['totalRows'] > 5001 || $info['totalColumns'] > 20) {
                $this->fail('Each worksheet supports at most 5,000 rows and 20 columns.');
            }
        }
        $book = $reader->load($path);
        try {
            $format = $book->getSheetByName('Format');
            if (! $format || $format->getCell('A2')->getValue() !== 'bpsc-cmo' || (string) $format->getCell('B2')->getValue() !== '1') {
                $this->fail('Unsupported workbook format. Download a current template.');
            }
            $result = [];
            $total = 0;
            foreach ($sheets as $name) {
                $sheet = $book->getSheetByName($name);
                $headers = self::HEADERS[$name];
                $missingMemberFields = [];
                foreach ($headers as $c => $h) {
                    if ($name === 'Users' && in_array($h, array_keys(Workspace::DETAILS), true) && ! $sheet->getCell([$c + 1, 1])->getValue()) {
                        $missingMemberFields[] = $h;

                        continue;
                    }
                    if ($sheet->getCell([$c + 1, 1])->getValue() !== $h) {
                        $this->fail("$name: headers must match the downloaded template.");
                    }
                }
                $result[$name] = [];
                for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
                    $row = [];
                    foreach ($headers as $c => $h) {
                        $cell = $sheet->getCell([$c + 1, $r]);
                        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                            $this->fail("$name row $r: formulas are not supported; paste values instead.");
                        }
                        $value = $cell->getValue();
                        if (! is_scalar($value) && $value !== null) {
                            $this->fail("$name row $r: unsupported cell value.");
                        }
                        $row[$h] = trim((string) $value);
                        if (strlen($row[$h]) > 200000) {
                            $this->fail("$name row $r: cell too large.");
                        }
                    }
                    if (! array_filter($row, fn ($v) => $v !== '')) {
                        continue;
                    }
                    $row['_row'] = $r;
                    if ($name === 'Users') {
                        $row['_missing_member_fields'] = $missingMemberFields;
                    }
                    $row['key'] = $row['key'] ?: (string) Str::uuid();
                    $result[$name][] = $row;
                    if (++$total > 5000) {
                        $this->fail('Import at most 5,000 data rows per workbook.');
                    }
                }
            }
            if (! $total) {
                $this->fail('The workbook contains no data. Add rows below the headers.');
            }

            return $result;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
