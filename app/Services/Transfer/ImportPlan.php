<?php

namespace App\Services\Transfer;

use App\Models\Question;
use App\Models\Survey;
use App\Models\User;
use App\Services\Evaluation\SurveyQuestionOrder;
use App\Services\Viva\Workspace;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ImportPlan
{
    public function build(array $rows, User $actor, bool $lock = false): array
    {
        app(Workbook::class)->authorize(array_keys($rows), $actor);
        $plan = ['items' => [], 'errors' => [], 'counts' => [], 'versions' => [], 'password_setup' => 0];
        $maps = [];
        // Surveys are locked first, matching survey/question editing and attendance.
        if ($lock) {
            Survey::orderBy('id')->lockForUpdate()->get();
            foreach (['Questions', 'Experts', 'Designations', 'Units', 'Users'] as $sheet) {
                if (isset($rows[$sheet])) {
                    Workbook::MODELS[$sheet]::orderBy('id')->lockForUpdate()->get();
                }
            }
        }
        foreach (Workbook::MODELS as $sheet => $class) {
            $maps[$sheet] = $class::get()->keyBy('transfer_uuid')->all();
        }
        foreach (Workbook::MODELS as $sheet => $class) {
            $seen = [];
            $targets = [];
            $naturalSeen = [];
            $plan['counts'][$sheet] = ['create' => 0, 'update' => 0];
            foreach ($rows[$sheet] ?? [] as $row) {
                $line = $row['_row'];
                try {
                    $this->check($row, ['key' => 'required|uuid']);
                    if (isset($seen[$row['key']])) {
                        $this->invalid('Duplicate key in worksheet.');
                    }
                    $seen[$row['key']] = true;
                    $existing = $class::where('transfer_uuid', $row['key'])->first();
                    // Natural keys let a fresh installation reuse its seeded masters.
                    $natural = match ($sheet) {
                        'Designations' => 'name', 'Units' => 'code', 'Users' => 'email', default => null
                    };
                    if ($natural) {
                        $normalized = mb_strtolower(trim($row[$natural]));
                        if (isset($naturalSeen[$normalized])) {
                            $this->invalid("Duplicate $natural in worksheet.");
                        }
                        $naturalSeen[$normalized] = true;
                        $other = $class::where($natural, $row[$natural])->first();
                        if ($existing && $other && ! $existing->is($other)) {
                            $this->invalid("$natural belongs to another record.");
                        }
                        $existing ??= $other;
                    }
                    if (in_array($sheet, ['Users', 'Experts', 'Questions', 'Surveys'], true) && $class::onlyTrashed()->where(function ($q) use ($row, $natural) {
                        $q->where('transfer_uuid', $row['key']);
                        if ($natural) {
                            $q->orWhere($natural, $row[$natural]);
                        }
                    })->exists()) {
                        $this->invalid('A matching record is deleted. Restore or resolve it before importing.');
                    }
                    $target = $existing?->id ?? 'key:'.$row['key'];
                    if (isset($targets[$target])) {
                        $this->invalid('Two rows resolve to the same existing record.');
                    }
                    $targets[$target] = true;
                    $data = $this->data($sheet, $row, $maps, $existing, $actor, $plan);
                    if ($existing) {
                        $plan['versions'][$sheet.':'.$existing->id] = hash('sha256', json_encode($existing->getAttributes()));
                    }
                    $plan['items'][$sheet][] = ['key' => $row['key'], 'row' => $line, 'id' => $existing?->id, 'data' => $data, 'action' => $existing ? 'update' : 'create'];
                    $plan['counts'][$sheet][$existing ? 'update' : 'create']++;
                    $record = $existing ? clone $existing : new $class;
                    $record->forceFill($data);
                    $maps[$sheet][$row['key']] = $record;
                } catch (ValidationException $e) {
                    $plan['errors'][] = ['sheet' => $sheet, 'row' => $line, 'message' => implode(' ', $e->validator->errors()->all())];
                }
            }
        }
        // A referenced master can change between preview and confirmation, too.
        foreach (['Designations', 'Units', 'Questions'] as $sheet) {
            foreach ($maps[$sheet] as $record) {
                if ($record->exists) {
                    $fresh = Workbook::MODELS[$sheet]::find($record->id);
                    $plan['versions'][$sheet.':'.$record->id] = hash('sha256', json_encode($fresh->getAttributes()));
                }
            }
        }
        ksort($plan['versions']);

        return $plan;
    }

    private function data(string $sheet, array $row, array $maps, $existing, User $actor, array &$plan): array
    {
        $data = array_diff_key($row, array_flip(['key', '_row', '_missing_member_fields']));
        foreach ($data as &$value) {
            if ($value === '') {
                $value = null;
            }
        } unset($value);
        $rules = match ($sheet) {
            'Designations' => ['name' => 'required|string|max:100', 'sort_order' => 'required|integer|min:0|max:99999', 'is_active' => 'required|boolean'],
            'Units' => ['code' => 'required|string|max:100', 'name' => 'required|string|max:100', 'sort_order' => 'required|integer|min:0|max:99999', 'is_active' => 'required|boolean'],
            'Users' => ['name' => 'required|string|max:255', 'email' => 'required|email|max:255', 'mobile_number' => 'nullable|string|regex:/^[0-9]{11}$/', 'role' => 'required|in:admin,chairman,member,staff', 'is_active' => 'required|boolean', 'designation_key' => 'required|uuid', 'unit_key' => 'required|uuid', 'password' => ['nullable', 'string', 'max:255', Password::min(8)->mixedCase()->numbers()->symbols()], 'password_hash' => 'nullable|string|max:255'],
            'Experts' => ['name' => 'required|string|max:255', 'type' => 'required|in:expert,departmental', 'designation' => 'nullable|string|max:255', 'organization' => 'nullable|string|max:255', 'specialization' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255', 'mobile_number' => 'nullable|string|max:30', 'biography' => 'nullable|string|max:5000', 'is_active' => 'required|boolean'],
            'Questions' => ['prompt' => 'required|string|max:1000', 'hints' => 'nullable|string|max:2000', 'type' => 'required|in:'.implode(',', array_keys(Question::TYPES)), 'options' => 'nullable|string|max:12000', 'rating_min' => 'nullable|integer|min:-1000|max:1000', 'rating_max' => 'nullable|integer|min:-1000|max:1000', 'is_required' => 'required|boolean', 'is_active' => 'required|boolean'],
            'Surveys' => ['title' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'status' => 'required|in:draft,active,closed', 'allowed_roles' => 'required|string|max:100', 'question_keys' => 'required|string|max:50000'],
        };
        if ($sheet === 'Users') {
            foreach (array_keys(Workspace::DETAILS) as $field) {
                $rules[$field] = 'nullable|string|max:150';
            }
            foreach ($row['_missing_member_fields'] ?? [] as $field) {
                unset($data[$field]);
            }
        }
        $this->check($data, $rules);
        if ($sheet === 'Designations') {
            $data['slug'] = $existing?->slug ?? Str::substr(Str::slug($data['name']), 0, 60).'-'.$row['key'];
        }
        if ($sheet === 'Units' && $existing && $existing->code !== $data['code']) {
            $this->invalid('Unit code cannot change through import because users reference it.');
        }
        if ($sheet === 'Users') {
            $designation = $maps['Designations'][$data['designation_key']] ?? null;
            $unit = $maps['Units'][$data['unit_key']] ?? null;
            if (! $designation?->is_active || ! $unit?->is_active) {
                $this->invalid('Designation and unit keys must reference active records in this workbook or installation.');
            }
            if ($existing && $existing->is($actor) && ($data['role'] !== 'admin' || ! $data['is_active'])) {
                $this->invalid('You cannot remove your own active Administrator access.');
            }
            $data['designation_key'] = $row['designation_key'];
            $data['unit_key'] = $row['unit_key'];
            if ($data['password'] && $data['password_hash']) {
                $this->invalid('Provide password or password_hash, not both.');
            }
            if ($data['password_hash']) {
                // Only the application's configured hashing algorithm can be used for login.
                if (! Hash::isHashed($data['password_hash']) || Hash::info($data['password_hash'])['algoName'] !== match (config('hashing.driver')) {
                    'argon' => 'argon2i', 'argon2id' => 'argon2id', default => 'bcrypt'
                }) {
                    $this->invalid('Unsupported password hash for this installation.');
                }
                $data['password'] = $data['password_hash'];
            }
            unset($data['password_hash']);
            if (! $data['password']) {
                unset($data['password']);
                if (! $existing) {
                    $plan['password_setup']++;
                }
            }
        }
        if ($sheet === 'Questions') {
            $options = $data['options'] ? $this->json($data['options'], 'options') : [];
            if (in_array($data['type'], ['select', 'radio', 'multiple'], true)) {
                $this->check(['options' => $options], ['options' => 'required|array|min:2|max:50', 'options.*' => 'required|string|distinct|max:200']);
                $data['options'] = $options;
            } else {
                $data['options'] = null;
            }
            if ($data['type'] === 'rating') {
                $data['rating_min'] ??= 1;
                $data['rating_max'] ??= 5;
                if ($data['rating_max'] <= $data['rating_min'] || $data['rating_max'] - $data['rating_min'] > 100) {
                    $this->invalid('Rating maximum must exceed minimum, with at most 101 values.');
                }
            } else {
                $data['rating_min'] = $data['rating_max'] = null;
            }
        }
        if ($sheet === 'Surveys') {
            $data['allowed_roles'] = $this->json($data['allowed_roles'], 'allowed_roles');
            $data['question_keys'] = $this->json($data['question_keys'], 'question_keys');
            $this->check($data, ['allowed_roles' => 'required|array|min:1|max:2', 'allowed_roles.*' => 'required|distinct|in:chairman,member', 'question_keys' => 'present|array|max:1000', 'question_keys.*' => 'required|uuid|distinct']);
            $active = false;
            foreach ($data['question_keys'] as $key) {
                $question = $maps['Questions'][$key] ?? null;
                if (! $question) {
                    $this->invalid('Question key does not exist in this workbook or installation.');
                }
                $active = $active || $question->is_active;
            }
            if ($data['status'] === 'active' && ! $active) {
                $this->invalid('Active surveys require at least one active question.');
            }
        }

        return $data;
    }

    private function json(string $value, string $field): array
    {
        $parsed = json_decode($value, true);
        if (! is_array($parsed) || ! array_is_list($parsed)) {
            $this->invalid("$field must be a JSON list, for example [\"value\",\"value2\"].");
        }

        return $parsed;
    }

    private function check(array $data, array $rules): void
    {
        Validator::make($data, $rules)->validate();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['row' => $message]);
    }

    public function apply(array $plan, User $actor): void
    {
        $maps = [];
        foreach (Workbook::MODELS as $sheet => $class) {
            $maps[$sheet] = $class::get()->keyBy('transfer_uuid')->all();
        }
        foreach (Workbook::MODELS as $sheet => $class) {
            foreach ($plan['items'][$sheet] ?? [] as $item) {
                $record = $item['id'] ? $class::findOrFail($item['id']) : new $class;
                $data = $item['data'];
                if ($sheet === 'Users') {
                    $data['designation_id'] = $maps['Designations'][$data['designation_key']]->id;
                    $data['unit'] = $maps['Units'][$data['unit_key']]->code;
                    unset($data['designation_key'], $data['unit_key']);
                    if (! $record->exists && ! isset($data['password'])) {
                        $data['password'] = Str::password(40);
                    }
                }
                $questionKeys = $data['question_keys'] ?? null;
                unset($data['question_keys']);
                if ($sheet === 'Surveys' && ! $record->exists) {
                    $data['created_by'] = $actor->id;
                }
                if (! $record->exists) {
                    $data['transfer_uuid'] = $item['key'];
                }
                $record->forceFill($data)->save();
                $maps[$sheet][$item['key']] = $record;
                if ($sheet === 'Surveys') {
                    $ids = array_map(fn ($key) => $maps['Questions'][$key]->id, $questionKeys);
                    app(SurveyQuestionOrder::class)->sync($record, $ids);
                }
            }
        }
    }
}
