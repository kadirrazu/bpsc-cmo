<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuestionController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['survey_id' => ['nullable', 'integer', Rule::exists('surveys', 'id')->whereNull('deleted_at')]]);
        $surveys = Survey::orderBy('title')->get();
        $questions = Question::with('surveys')->when($d['survey_id'] ?? null, fn ($q, $id) => $q->whereHas('surveys', fn ($s) => $s->where('surveys.id', $id)))->orderByDesc('id')->paginate(25)->withQueryString();

        return view('questions.index', compact('questions', 'surveys'));
    }

    public function create(Request $r)
    {
        return view('questions.form', ['question' => new Question(['type' => 'select', 'is_required' => true, 'is_active' => true, 'rating_min' => 1, 'rating_max' => 5])]);
    }

    public function store(Request $r)
    {
        $data = $this->data($r);
        DB::transaction(fn () => Question::create($data));

        return redirect()->route('questions.index')->with('success', 'Question added to the bank. Select and arrange it from Survey Create/Edit.');
    }

    public function edit(Question $question)
    {
        return view('questions.form', compact('question'));
    }

    public function update(Request $r, Question $question)
    {
        $data = $this->data($r);
        DB::transaction(function () use ($question, $data) {
            $ids = $question->surveys()->pluck('surveys.id')->all();
            Survey::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            Question::whereKey($question->id)->lockForUpdate()->firstOrFail()->update($data);
        });

        return redirect()->route('questions.index')->with('success', 'Question updated. Each survey retains its own question order; submitted answers are unchanged.');
    }

    public function destroy(Request $r, Question $question)
    {
        $r->validate(['confirmation' => 'required|in:DELETE']);
        DB::transaction(function () use ($question) {
            Survey::whereHas('questions', fn ($q) => $q->where('questions.id', $question->id))->orderBy('id')->lockForUpdate()->get();
            Question::whereKey($question->id)->lockForUpdate()->firstOrFail()->delete();
        });

        return redirect()->route('questions.index')->with('success', 'Question removed from the bank and future surveys. Previous answers are retained.');
    }

    private function data(Request $r): array
    {
        $d = $r->validate(['prompt' => 'required|string|max:1000', 'hints' => 'nullable|string|max:2000', 'type' => ['required', Rule::in(array_keys(Question::TYPES))], 'option_lines' => 'nullable|string|max:12000', 'rating_min' => 'nullable|integer|min:-1000|max:1000', 'rating_max' => 'nullable|integer|min:-1000|max:1000', 'is_required' => 'required|boolean', 'is_active' => 'required|boolean']);
        $options = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $d['option_lines'] ?? '')), fn ($x) => $x !== ''));
        if (in_array($d['type'], ['select', 'radio', 'multiple'], true) && (count($options) < 2 || count($options) > 50 || count(array_unique($options)) !== count($options) || collect($options)->contains(fn ($v) => mb_strlen($v) > 200))) {
            throw ValidationException::withMessages(['option_lines' => 'Enter 2–50 different options, one per line, each up to 200 characters.']);
        }
        $d['options'] = in_array($d['type'], ['select', 'radio', 'multiple'], true) ? $options : null;
        $d['rating_min'] = $d['type'] === 'rating' ? ($d['rating_min'] ?? 1) : null;
        $d['rating_max'] = $d['type'] === 'rating' ? ($d['rating_max'] ?? 5) : null;
        if ($d['type'] === 'rating' && ($d['rating_max'] <= $d['rating_min'] || $d['rating_max'] - $d['rating_min'] > 100)) {
            throw ValidationException::withMessages(['rating_max' => 'Maximum must exceed minimum; a rating scale may contain at most 101 values.']);
        }
        unset($d['option_lines']);

        return $d;
    }
}
