<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Evaluation;
use App\Models\Expert;
use App\Models\Question;
use App\Models\Survey;
use App\Services\Evaluation\ExpertLookup;
use App\Services\Library\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EvaluationController extends Controller
{
    private function access(): void
    {
        abort_unless(auth()->user()->can('view-evaluation-data') || auth()->user()->can('evaluate-experts'), 403);
    }

    public function index(Request $r)
    {
        $this->access();
        $d = $r->validate(['q' => 'nullable|string|max:150', 'viva_date' => 'nullable|date_format:Y-m-d', 'survey_id' => 'nullable|integer']);
        $evaluations = Evaluation::with(['expert', 'evaluator'])->when(! $r->user()->can('view-evaluation-data'), fn ($q) => $q->where('user_id', $r->user()->id))->when($d['survey_id'] ?? null, fn ($q, $id) => $q->where('survey_id', $id))->when($d['viva_date'] ?? null, fn ($q, $s) => $q->whereDate('viva_date', $s))->when($d['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->whereHas('expert', fn ($e) => $e->where('name', 'like', '%'.$s.'%'))->orWhere('reference', 'like', '%'.$s.'%')))->latest('id')->paginate(20)->withQueryString();

        return view('evaluations.index', compact('evaluations'));
    }

    public function create(Request $r, ExpertLookup $lookup)
    {
        $d = $r->validate(['survey_id' => 'nullable|integer', 'expert_q' => 'nullable|string|max:150', 'expert_id' => 'nullable|integer|min:1']);
        $survey = null;
        if (! empty($d['survey_id'])) {
            $survey = Survey::findOrFail($d['survey_id']);
            abort_unless($survey->canAttend($r->user()), 403);
        }
        $questions = $survey ? $survey->questions()->where('is_active', true)->orderBy('question_survey.position')->orderBy('questions.id')->get() : collect();
        $experts = $lookup->query(trim($d['expert_q'] ?? ''))->limit(20)->get();
        $selectedId = filter_var(old('expert_id', $d['expert_id'] ?? null), FILTER_VALIDATE_INT);
        $selectedExpert = $selectedId ? $lookup->query()->where('id', $selectedId)->first() : null;
        if ($selectedExpert && ! $experts->contains('id', $selectedExpert->id)) {
            $experts->prepend($selectedExpert);
        }

        return view('evaluations.form', ['survey' => $survey, 'surveys' => Survey::availableTo($r->user())->orderBy('title')->get(), 'questions' => $questions, 'schema_token' => Question::fingerprint($questions), 'experts' => $experts, 'selectedExpert' => $selectedExpert, 'hasExperts' => Expert::where('is_active', true)->exists()]);
    }

    public function expertOptions(Request $r, ExpertLookup $lookup)
    {
        $d = $r->validate(['survey_id' => 'required|integer', 'q' => 'nullable|string|max:150', 'page' => 'nullable|integer|min:1|max:10000']);
        $survey = Survey::findOrFail($d['survey_id']);
        abort_unless($survey->canAttend($r->user()), 403);
        $results = $lookup->query(trim($d['q'] ?? ''))->simplePaginate(20);

        return response()->json(['data' => $results->getCollection()->map(fn ($e) => $lookup->item($e))->values(), 'has_more' => $results->hasMorePages(), 'next_page' => $results->hasMorePages() ? $results->currentPage() + 1 : null]);
    }

    public function store(Request $r)
    {
        Gate::authorize('evaluate-experts');
        $r->merge(['answers' => $r->input('answers', [])]);
        $d = $r->validate(['survey_id' => 'required|integer', 'expert_id' => 'required|integer', 'viva_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'reference' => 'nullable|string|max:255', 'schema_token' => 'required|string|size:64', 'answers' => 'present|array']);
        try {
            $evaluation = DB::transaction(function () use ($r, $d) {
                $survey = Survey::whereKey($d['survey_id'])->lockForUpdate()->firstOrFail();
                abort_unless($survey->canAttend($r->user()), 403);
                $expert = Expert::whereKey($d['expert_id'])->where('is_active', true)->lockForUpdate()->first();
                if (! $expert) {
                    throw ValidationException::withMessages(['expert_id' => 'Select an active expert.']);
                }
                $questions = $survey->questions()->where('is_active', true)->orderBy('question_survey.position')->orderBy('questions.id')->lockForUpdate()->get();
                if ($questions->isEmpty() || ! hash_equals(Question::fingerprint($questions), $d['schema_token'])) {
                    throw ValidationException::withMessages(['schema_token' => 'The questionnaire has changed. Reload this page and complete the current questions.']);
                }
                // Allow an empty answer set when every question is optional, but never accept keys from other surveys.
                $rules = ['answers' => ['present', 'array:'.implode(',', $questions->modelKeys())]];
                foreach ($questions as $q) {
                    $key = 'answers.'.$q->id;
                    $rules[$key] = [$q->is_required ? 'required' : 'nullable', ...match ($q->type) {
                        'rating' => ['integer', 'between:'.($q->rating_min ?? 1).','.($q->rating_max ?? 5)],
                        'checkbox' => [$q->is_required ? 'accepted' : 'boolean'],
                        'select','radio' => ['string', Rule::in($q->options ?? [])],
                        'date' => ['date_format:Y-m-d'],
                        'time' => ['date_format:H:i'],
                        'multiple' => ['array', 'max:50'],
                        'text' => ['string', 'max:255'],
                        default => ['string', 'max:5000']
                    }];
                    if ($q->type === 'multiple') {
                        $rules[$key.'.*'] = ['required', 'string', 'distinct', Rule::in($q->options ?? [])];
                    }
                }
                Validator::make($d, $rules)->validate();
                if ($r->user()->role === UserRole::Member) {
                    if (Evaluation::where('expert_id', $expert->id)->where('user_id', $r->user()->id)->whereDate('viva_date', $d['viva_date'])->exists()) {
                        throw ValidationException::withMessages(['expert_id' => 'You have already evaluated this expert for this viva date. A Member may submit only once per expert per day, across all surveys.']);
                    }
                    DB::table('member_evaluation_days')->insert(['user_id' => $r->user()->id, 'expert_id' => $expert->id, 'viva_date' => $d['viva_date']]);
                }
                if (Evaluation::where('survey_id', $survey->id)->where('expert_id', $expert->id)->where('user_id', $r->user()->id)->whereDate('viva_date', $d['viva_date'])->exists()) {
                    throw ValidationException::withMessages(['expert_id' => 'You have already evaluated this expert in this survey for this viva date.']);
                }
                $answers = $questions->map(fn ($q) => ['question_id' => $q->id, 'prompt' => $q->prompt, 'hints' => $q->hints, 'rating_min' => $q->rating_min, 'rating_max' => $q->rating_max, 'type' => $q->type, 'options' => $q->options, 'is_required' => $q->is_required, 'value' => $q->type === 'checkbox' ? (in_array($d['answers'][$q->id] ?? 0, [1, '1', true, 'yes', 'on'], true) ? 1 : 0) : ($d['answers'][$q->id] ?? null)])->all();
                $evaluation = Evaluation::create(['survey_id' => $survey->id, 'survey_snapshot' => $survey->only(['title', 'description']), 'expert_id' => $expert->id, 'user_id' => $r->user()->id, 'viva_date' => $d['viva_date'], 'reference' => $d['reference'] ?? null, 'expert_snapshot' => $expert->only(['name', 'type', 'designation', 'organization', 'specialization']), 'evaluator_snapshot' => ['name' => $r->user()->name, 'role' => $r->user()->role->label()], 'answers' => $answers, 'submitted_at' => now()]);
                app(Audit::class)->record('evaluation.submitted', ['survey_id' => $survey->id, 'expert_id' => $expert->id, 'viva_date' => $d['viva_date'], 'question_count' => count($answers)], $evaluation);

                return $evaluation;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['expert_id' => $r->user()->role === UserRole::Member ? 'You have already evaluated this expert for this viva date. A Member may submit only once per expert per day, across all surveys.' : 'You have already evaluated this expert in this survey for this viva date.']);
        }

        return redirect()->route('evaluations.show', $evaluation)->with('success', 'Evaluation submitted successfully.');
    }

    public function show(Evaluation $evaluation)
    {
        $this->access();
        abort_unless(auth()->user()->can('view-expert-reports') || $evaluation->user_id === auth()->id(), 403);

        return view('evaluations.show', compact('evaluation'));
    }
}
