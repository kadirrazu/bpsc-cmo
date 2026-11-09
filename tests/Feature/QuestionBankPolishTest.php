<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Evaluation;
use App\Models\Expert;
use App\Models\Question;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionBankPolishTest extends TestCase
{
    use RefreshDatabase;

    private function login(UserRole $role = UserRole::Staff): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);

        return $u;
    }

    private function survey(array $extra = []): Survey
    {
        return Survey::create(array_merge(['title' => 'Reusable Survey', 'status' => 'active', 'allowed_roles' => ['chairman', 'member']], $extra));
    }

    private function question(array $extra = []): Question
    {
        return Question::create(array_merge(['prompt' => 'Reusable Question', 'type' => 'rating', 'rating_min' => 1, 'rating_max' => 5, 'is_active' => true, 'is_required' => true, 'sort_order' => 1], $extra));
    }

    private function data(array $extra = []): array
    {
        return array_merge(['prompt' => 'Bank Question', 'type' => 'rating', 'is_required' => 1, 'is_active' => 1, 'sort_order' => 1], $extra);
    }

    private function payload(Survey $s, array $answers): array
    {
        return ['survey_id' => $s->id, 'expert_id' => Expert::firstOrCreate(['name' => 'Expert'], ['type' => 'expert', 'is_active' => true])->id, 'viva_date' => now()->toDateString(), 'schema_token' => Question::fingerprint($s->questions()->where('is_active', true)->orderBy('question_survey.position')->orderBy('questions.id')->get()), 'answers' => $answers];
    }

    public function test_staff_can_create_surveys_and_questions_but_cannot_access_system_or_results(): void
    {
        $this->login();
        foreach (['/modules/evaluation/surveys/create', '/modules/evaluation/questions/create', '/modules/evaluation/experts/create'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->post('/modules/evaluation/surveys', ['title' => 'Staff Survey', 'status' => 'draft', 'allowed_roles' => ['member']])->assertRedirect();
        $this->post('/modules/evaluation/questions', $this->data())->assertRedirect();
        foreach (['/users', '/units', '/designations', '/roles', '/audit-logs', '/modules/evaluation/reports/experts', '/modules/evaluation/reports/experts?export=submissions', '/modules/evaluation/responses'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/modules/evaluation')->assertOk()->assertDontSee('href="'.route('evaluation.reports').'"', false)->assertDontSee('href="'.route('evaluations.index').'"', false);
    }

    public function test_type_order_and_question_alignment_match_the_requested_layout(): void
    {
        $this->login();
        $this->assertSame(['select', 'text', 'textarea', 'radio', 'multiple', 'checkbox', 'date', 'time', 'rating'], array_keys(Question::TYPES));
        $this->question();
        $this->get('/modules/evaluation/questions')->assertOk()->assertDontSee('<th class="text-center align-middle">Order</th>', false)->assertSee('<th class="text-center align-middle">Required</th>', false)->assertSee('<th class="text-center align-middle">Status</th>', false)->assertSee('<th class="text-start align-middle">Survey / Question</th>', false)->assertSee('<th class="text-start align-middle">Answer Type</th>', false)->assertSee('<th class="text-start align-middle">Actions</th>', false);
    }

    public function test_hints_are_optional_and_rating_defaults_are_saved(): void
    {
        $this->login();
        $this->post('/modules/evaluation/questions', $this->data())->assertRedirect();
        $q = Question::firstOrFail();
        $this->assertNull($q->hints);
        $this->assertSame(1, $q->rating_min);
        $this->assertSame(5, $q->rating_max);
        $this->get('/modules/evaluation/questions/'.$q->id.'/edit')->assertOk()->assertSee('Hints / Information')->assertSee('value="1"', false)->assertSee('value="5"', false);
    }

    public function test_standalone_question_can_be_attached_to_multiple_surveys_during_survey_creation(): void
    {
        $this->login();
        $this->post('/modules/evaluation/questions', $this->data(['hints' => '1 is Definitely No; 5 is Definitely Yes']))->assertRedirect();
        $q = Question::firstOrFail();
        $this->assertCount(0, $q->surveys);
        foreach (['Survey One', 'Survey Two'] as $title) {
            $this->post('/modules/evaluation/surveys', ['title' => $title, 'status' => 'draft', 'allowed_roles' => ['member'], 'question_ids' => [$q->id]])->assertRedirect();
        }
        $this->assertCount(2, $q->fresh()->surveys);
        $this->assertDatabaseCount('questions', 1);
        $this->get('/modules/evaluation/questions?survey_id='.Survey::first()->id)->assertOk()->assertSee('Bank Question');
    }

    public function test_questions_can_be_selected_and_removed_on_survey_edit_without_deleting_bank_questions(): void
    {
        $this->login();
        $s = $this->survey(['status' => 'draft']);
        $other = $this->survey(['title' => 'Other']);
        $q = $this->question();
        $other->questions()->attach($q);
        $d = ['title' => $s->title, 'status' => 'draft', 'allowed_roles' => ['member'], 'question_ids' => [$q->id]];
        $this->put('/modules/evaluation/surveys/'.$s->id, $d)->assertRedirect();
        $this->assertCount(1, $s->fresh()->questions);
        unset($d['question_ids']);
        $d['question_selection'] = 1;
        $this->put('/modules/evaluation/surveys/'.$s->id, $d)->assertRedirect();
        $this->assertCount(0, $s->fresh()->questions);
        $this->assertCount(1, $other->fresh()->questions);
        $this->assertNotSoftDeleted($q);
    }

    public function test_custom_rating_and_hints_render_and_are_snapshotted(): void
    {
        $this->login();
        $s = $this->survey();
        $this->post('/modules/evaluation/questions', $this->data(['survey_ids' => [$s->id], 'rating_min' => 0, 'rating_max' => 10, 'hints' => '0 = Never; 10 = Always']))->assertRedirect();
        $q = Question::firstOrFail();
        $s->questions()->attach($q->id, ['position' => 1]);
        $this->login(UserRole::Member);
        $this->payload($s, []);
        $this->get('/modules/evaluation/responses/create?survey_id='.$s->id)->assertOk()->assertSee('0 = Never; 10 = Always')->assertSee('value="10"', false);
        $this->post('/modules/evaluation/responses', $this->payload($s, [$q->id => 11]))->assertSessionHasErrors('answers.'.$q->id);
        $this->post('/modules/evaluation/responses', $this->payload($s, [$q->id => 0]))->assertRedirect();
        $v = Evaluation::firstOrFail();
        $this->assertSame(0, $v->answers[0]['rating_min']);
        $this->assertSame(10, $v->answers[0]['rating_max']);
        $this->assertSame('0 = Never; 10 = Always', $v->answers[0]['hints']);
        $q->update(['hints' => 'Changed Hint', 'rating_max' => 7]);
        $this->get('/modules/evaluation/responses/'.$v->id)->assertOk()->assertSee('0 = Never; 10 = Always')->assertDontSee('Changed Hint')->assertSee('0–10');
    }

    public function test_invalid_rating_limits_and_hints_are_rejected(): void
    {
        $this->login();
        foreach ([[5, 1], [3, 3], [0, 101], [-1001, 5], [1, 1001], ['x', 5]] as [$min,$max]) {
            $this->post('/modules/evaluation/questions', $this->data(['rating_min' => $min, 'rating_max' => $max]))->assertSessionHasErrors();
        }$this->post('/modules/evaluation/questions', $this->data(['hints' => str_repeat('x', 2001)]))->assertSessionHasErrors('hints');
        $this->assertDatabaseCount('questions', 0);
    }

    public function test_checkbox_required_and_optional_answers_are_handled_as_booleans(): void
    {
        $this->login(UserRole::Member);
        $s = $this->survey();
        $required = $this->question(['type' => 'checkbox']);
        $optional = $this->question(['prompt' => 'Optional Checkbox', 'type' => 'checkbox', 'is_required' => false]);
        $s->questions()->attach([$required->id, $optional->id]);
        $this->post('/modules/evaluation/responses', $this->payload($s, [$required->id => 0, $optional->id => 0]))->assertSessionHasErrors('answers.'.$required->id);
        $this->post('/modules/evaluation/responses', $this->payload($s, [$required->id => 'on', $optional->id => 0]))->assertRedirect();
        $v = Evaluation::firstOrFail();
        $this->assertSame(1, $v->answers[0]['value']);
        $this->assertSame(0, $v->answers[1]['value']);
        $this->get('/modules/evaluation/responses/'.$v->id)->assertOk()->assertSee('Checked')->assertSee('Unchecked');
    }

    public function test_hint_and_rating_changes_reject_a_stale_form_in_every_linked_survey(): void
    {
        $this->login(UserRole::Member);
        $a = $this->survey();
        $b = $this->survey(['title' => 'Second']);
        $q = $this->question();
        $a->questions()->attach($q);
        $b->questions()->attach($q);
        $pa = $this->payload($a, [$q->id => 4]);
        $pb = $this->payload($b, [$q->id => 4]);
        $q->update(['hints' => 'A new explanation']);
        foreach ([$pa, $pb] as $d) {
            $this->post('/modules/evaluation/responses', $d)->assertSessionHasErrors('schema_token');
        }$this->assertDatabaseCount('evaluations', 0);
    }

    public function test_shared_question_edit_and_delete_affect_future_forms_but_not_previous_responses(): void
    {
        $this->login();
        $a = $this->survey();
        $b = $this->survey(['title' => 'Second']);
        $q = $this->question();
        $a->questions()->attach($q);
        $b->questions()->attach($q);
        $this->put('/modules/evaluation/questions/'.$q->id, $this->data(['prompt' => 'Updated Shared Question', 'survey_ids' => [$a->id, $b->id]]))->assertRedirect();
        $this->assertCount(2, $q->fresh()->surveys);
        $owner = $this->login(UserRole::Member);
        $this->post('/modules/evaluation/responses', $this->payload($a, [$q->id => 4]))->assertRedirect();
        $v = Evaluation::firstOrFail();
        $this->login();
        $this->delete('/modules/evaluation/questions/'.$q->id, ['confirmation' => 'DELETE'])->assertRedirect();
        $this->assertCount(0, $a->fresh()->questions);
        $this->assertCount(0, $b->fresh()->questions);
        $this->actingAs($owner)->withSession(['auth_version' => $owner->auth_version]);
        $this->get('/modules/evaluation/responses/'.$v->id)->assertOk()->assertSee('Updated Shared Question');
    }
}
