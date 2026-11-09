<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\Survey;
use App\Services\Evaluation\SurveyQuestionOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SurveyController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->user()->can('manage-surveys') || $r->user()->can('evaluate-experts'), 403);
        $d = $r->validate(['q' => 'nullable|string|max:150']);
        $surveys = Survey::withCount(['questions' => fn ($q) => $q->where('is_active', true), 'evaluations'])->when(! $r->user()->can('manage-surveys'), fn ($q) => $q->availableTo($r->user()))->when($d['q'] ?? null, fn ($q, $s) => $q->where('title', 'like', '%'.$s.'%'))->latest('id')->paginate(15)->withQueryString();

        return view('surveys.index', compact('surveys'));
    }

    public function create()
    {
        return view('surveys.form', ['survey' => new Survey(['status' => 'draft', 'allowed_roles' => ['chairman', 'member']]), 'bank' => Question::orderBy('prompt')->orderBy('id')->get(), 'selectedQuestions' => []]);
    }

    public function store(Request $r)
    {
        $d = $this->data($r);
        $ids = $d['question_ids'] ?? [];
        $positions = $d['question_positions'] ?? [];
        unset($d['question_ids'],$d['question_positions']);
        $survey = DB::transaction(function () use ($d, $r, $ids, $positions) {
            $s = Survey::create($d + ['created_by' => $r->user()->id]);
            app(SurveyQuestionOrder::class)->sync($s, $ids, $positions);

            return $s;
        });

        return redirect()->route('surveys.show', $survey)->with('success', 'Survey created. Add questions before activating it.');
    }

    public function show(Survey $survey)
    {
        abort_unless(auth()->user()->can('manage-surveys') || $survey->canAttend(auth()->user()), 403);
        $survey->load(['questions' => fn ($q) => $q->orderBy('question_survey.position')->orderBy('questions.id')]);

        return view('surveys.show', compact('survey'));
    }

    public function edit(Survey $survey)
    {
        return view('surveys.form', ['survey' => $survey, 'bank' => Question::orderBy('prompt')->orderBy('id')->get(), 'selectedQuestions' => $survey->questions()->orderBy('question_survey.position')->orderBy('questions.id')->pluck('questions.id')->all()]);
    }

    public function update(Request $r, Survey $survey)
    {
        $d = $this->data($r);
        DB::transaction(function () use ($d, $survey) {
            $current = Survey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            $ids = $d['question_ids'] ?? $current->questions()->orderBy('question_survey.position')->orderBy('questions.id')->pluck('questions.id')->all();
            $positions = $d['question_positions'] ?? [];
            unset($d['question_ids'],$d['question_positions']);
            app(SurveyQuestionOrder::class)->sync($current, $ids, $positions);
            if ($d['status'] === 'active' && ! $current->questions()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['status' => 'Add at least one active question before activating this survey.']);
            }$current->update($d);
        });

        return redirect()->route('surveys.show', $survey)->with('success', 'Survey updated.');
    }

    public function destroy(Request $r, Survey $survey)
    {
        $r->validate(['confirmation' => 'required|in:DELETE']);
        DB::transaction(fn () => Survey::whereKey($survey->id)->lockForUpdate()->firstOrFail()->delete());

        return redirect()->route('surveys.index')->with('success', 'Survey removed. Previous responses are retained.');
    }

    private function data(Request $r): array
    {
        if ($r->has('question_selection') && ! $r->has('question_ids')) {
            $r->merge(['question_ids' => []]);
        }

        return $r->validate(['title' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'status' => ['required', Rule::in($r->isMethod('POST') ? ['draft'] : ['draft', 'active', 'closed'])], 'question_positions' => 'sometimes|array', 'question_positions.*' => 'nullable|integer|min:1|max:10000', 'question_ids' => 'sometimes|array|max:1000', 'question_ids.*' => ['required', 'integer', 'distinct', Rule::exists('questions', 'id')->whereNull('deleted_at')], 'allowed_roles' => 'required|array|min:1|max:2', 'allowed_roles.*' => 'required|distinct|in:chairman,member']);
    }
}
