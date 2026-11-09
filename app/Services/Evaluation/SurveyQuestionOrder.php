<?php

namespace App\Services\Evaluation;

use App\Models\Question;
use App\Models\Survey;
use App\Services\Library\Audit;
use Illuminate\Validation\ValidationException;

class SurveyQuestionOrder
{
    public function sync(Survey $survey, array $ids, array $positions = []): void
    {
        $ids = array_map('intval', $ids);
        $seen = [];
        $decorated = [];
        foreach ($ids as $index => $id) {
            $position = $positions[$id] ?? null;
            if ($position !== null && $position !== '') {
                if (isset($seen[(int) $position])) {
                    throw ValidationException::withMessages(['question_positions' => 'Each selected question needs a different order number.']);
                }$seen[(int) $position] = true;
            }$decorated[] = ['id' => $id, 'position' => $position === null || $position === '' ? 10001 + $index : (int) $position];
        }
        usort($decorated, fn ($a, $b) => $a['position'] <=> $b['position']);
        $ordered = array_column($decorated, 'id');
        $existing = Question::whereIn('id', $ordered)->lockForUpdate()->pluck('id')->all();
        if (count($existing) !== count($ordered)) {
            throw ValidationException::withMessages(['question_ids' => 'A selected question is no longer available. Reload the survey form.']);
        }
        $before = $survey->questions()->orderBy('question_survey.position')->orderBy('questions.id')->pluck('questions.id')->all();
        $links = [];
        foreach ($ordered as $index => $id) {
            $links[$id] = ['position' => $index + 1];
        }$survey->questions()->sync($links);
        app(Audit::class)->record('survey.questions.updated', ['before' => $before, 'after' => $ordered], $survey);
    }
}
