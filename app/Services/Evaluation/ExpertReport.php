<?php

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use Illuminate\Http\Request;

class ExpertReport
{
    public function filters(Request $r): array
    {
        $data = $r->validate(['expert_id' => 'nullable|integer|min:1', 'type' => 'nullable|in:expert,departmental', 'survey_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => $r->filled('from') ? 'nullable|date_format:Y-m-d|after_or_equal:from' : 'nullable|date_format:Y-m-d', 'evaluator_role' => 'nullable|in:Chairman,Member', 'expert_page' => 'nullable|integer|min:1|max:100000', 'submissions_page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|in:10,20,50', 'expert_q' => 'nullable|string|max:150', 'export' => 'nullable|in:summary,submissions']);
        $data['per_page'] = 20;

        return $data;
    }

    public function query(array $f)
    {
        return Evaluation::with('expert')->when($f['expert_id'] ?? null, fn ($q, $v) => $q->where('expert_id', $v))->when($f['survey_id'] ?? null, fn ($q, $v) => $q->where('survey_id', $v))->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('viva_date', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('viva_date', '<=', $v))->when($f['type'] ?? null, fn ($q, $v) => $q->where('expert_snapshot->type', $v))->when($f['evaluator_role'] ?? null, fn ($q, $v) => $q->where('evaluator_snapshot->role', $v));
    }

    public function summary(array $f): array
    {
        $total = 0;
        $experts = [];
        $surveys = [];
        $rows = [];
        foreach ($this->query($f)->lazyById(200) as $v) {
            $total++;
            $experts[$v->expert_id] = true;
            $surveys[$v->survey_id] = true;
            foreach ($v->answers as $a) {
                $min = $a['type'] === 'rating' ? ($a['rating_min'] ?? 1) : null;
                $max = $a['type'] === 'rating' ? ($a['rating_max'] ?? 5) : null;
                $key = hash('sha256', json_encode([$v->expert_id, $a['question_id'], $a['prompt'], $a['type'], $min, $max, $a['options'] ?? null]));
                if (! isset($rows[$key])) {
                    $rows[$key] = ['expert_id' => $v->expert_id, 'expert_name' => $v->expert?->name ?? $v->expert_snapshot['name'], 'expert_type' => $v->expert_snapshot['type'] ?? 'expert', 'question_id' => $a['question_id'], 'prompt' => $a['prompt'], 'type' => $a['type'], 'min' => $min, 'max' => $max, 'answered' => 0, 'skipped' => 0, 'sum' => 0, 'average' => null, 'distribution' => []];
                }
                $value = $a['value'];
                if ($value === null || $value === '' || $value === []) {
                    $rows[$key]['skipped']++;

                    continue;
                }$rows[$key]['answered']++;
                if ($a['type'] === 'rating' && is_numeric($value)) {
                    $rows[$key]['sum'] += (float) $value;
                    $rows[$key]['average'] = round($rows[$key]['sum'] / $rows[$key]['answered'], 2);
                }
                if (in_array($a['type'], ['rating', 'select', 'radio', 'choice', 'multiple', 'checkbox'], true)) {
                    $values = is_array($value) ? $value : [$value];
                    foreach ($values as $option) {
                        $label = $a['type'] === 'checkbox' ? ((string) $option === '1' ? 'Checked' : 'Unchecked') : (string) $option;
                        $rows[$key]['distribution'][$label] = ($rows[$key]['distribution'][$label] ?? 0) + 1;
                    }
                }
            }
        }
        foreach ($rows as &$row) {
            ksort($row['distribution']);
        }unset($row);

        return ['total' => $total, 'expert_count' => count($experts), 'survey_count' => count($surveys), 'rows' => array_values($rows)];
    }

    public function csv(array $f, string $kind)
    {
        $filename = 'expert-evaluation-'.$kind.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($f, $kind) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $write = function (array $row) use ($out) {
                $safe = array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : $v, $row);
                fputcsv($out, $safe, ',', '"', '');
            };
            if ($kind === 'summary') {
                $write(['Expert ID', 'Expert', 'Pool Type', 'Question ID', 'Question', 'Answer Type', 'Rating Minimum', 'Rating Maximum', 'Answered', 'Skipped', 'Average', 'Distribution']);
                foreach ($this->summary($f)['rows'] as $row) {
                    $write([$row['expert_id'], $row['expert_name'], $row['expert_type'], $row['question_id'], $row['prompt'], $row['type'], $row['min'], $row['max'], $row['answered'], $row['skipped'], $row['average'], json_encode($row['distribution'], JSON_UNESCAPED_UNICODE)]);
                }
            } else {
                $write(['Response ID', 'Expert ID', 'Expert at Submission', 'Pool Type', 'Survey ID', 'Survey', 'Evaluator', 'Evaluator Role', 'Viva Date', 'Submitted At', 'Question ID', 'Question', 'Hints', 'Type', 'Minimum', 'Maximum', 'Answer']);
                foreach ($this->query($f)->lazyById(200) as $v) {
                    foreach ($v->answers as $a) {
                        $write([$v->id, $v->expert_id, $v->expert_snapshot['name'], $v->expert_snapshot['type'] ?? '', $v->survey_id, $v->survey_snapshot['title'] ?? 'Initial Expert Evaluation', $v->evaluator_snapshot['name'], $v->evaluator_snapshot['role'], $v->viva_date->format('Y-m-d'), $v->submitted_at->format('Y-m-d H:i:s'), $a['question_id'], $a['prompt'], $a['hints'] ?? '', $a['type'], $a['type'] === 'rating' ? ($a['rating_min'] ?? 1) : '', $a['type'] === 'rating' ? ($a['rating_max'] ?? 5) : '', is_array($a['value']) ? implode(' | ', $a['value']) : ($a['value'] ?? '')]);
                    }
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
