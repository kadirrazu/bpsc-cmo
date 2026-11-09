<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Evaluation;
use App\Models\Expert;
use App\Models\Question;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SurveyQuestionOrderTest extends TestCase
{
    use RefreshDatabase;

    private function login(UserRole $role = UserRole::Staff): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);

        return $u;
    }

    private function questions(): array
    {
        return collect(['Alpha', 'Beta', 'Gamma'])->map(fn ($text) => Question::create(['prompt' => $text, 'type' => 'text', 'is_active' => true, 'is_required' => true]))->all();
    }

    private function survey(string $title = 'Survey'): Survey
    {
        return Survey::create(['title' => $title, 'status' => 'draft', 'allowed_roles' => ['chairman', 'member']]);
    }

    private function updateData(Survey $s, array $extra = []): array
    {
        return array_merge(['title' => $s->title, 'status' => $s->status, 'allowed_roles' => $s->allowed_roles], $extra);
    }

    private function ids(Survey $s): array
    {
        return $s->questions()->orderBy('question_survey.position')->orderBy('questions.id')->pluck('questions.id')->all();
    }

    public function test_bank_has_neither_global_order_nor_survey_assignment_controls(): void
    {
        $this->login();
        $this->get('/modules/evaluation/questions/create')->assertOk()->assertDontSee('name="sort_order"', false)->assertDontSee('name="survey_ids[]"', false);
        $this->post('/modules/evaluation/questions', ['prompt' => 'New Bank Question', 'type' => 'text', 'is_active' => 1, 'is_required' => 1])->assertRedirect();
        $q = Question::firstOrFail();
        $this->assertCount(0, $q->surveys);
        $this->get('/modules/evaluation/questions/'.$q->id.'/edit')->assertOk()->assertDontSee('name="sort_order"', false);
        $this->get('/modules/evaluation/questions')->assertOk()->assertDontSee('>Order</th>', false);
    }

    public function test_survey_creation_preserves_the_submitted_question_sequence(): void
    {
        $this->login();
        [$a,$b,$c] = $this->questions();
        $this->post('/modules/evaluation/surveys', ['title' => 'Ordered Survey', 'status' => 'draft', 'allowed_roles' => ['member'], 'question_ids' => [$c->id, $a->id, $b->id]])->assertRedirect();
        $s = Survey::firstOrFail();
        $this->assertSame([$c->id, $a->id, $b->id], $this->ids($s));
        $this->assertSame([1, 2, 3], $s->questions()->orderBy('question_survey.position')->pluck('question_survey.position')->all());
    }

    public function test_same_questions_can_have_independent_sequences_in_two_surveys(): void
    {
        $this->login();
        [$a,$b,$c] = $this->questions();
        $x = $this->survey('X');
        $y = $this->survey('Y');
        $this->put('/modules/evaluation/surveys/'.$x->id, $this->updateData($x, ['question_ids' => [$a->id, $b->id, $c->id]]))->assertRedirect();
        $this->put('/modules/evaluation/surveys/'.$y->id, $this->updateData($y, ['question_ids' => [$c->id, $b->id, $a->id]]))->assertRedirect();
        $this->put('/modules/evaluation/surveys/'.$x->id, $this->updateData($x, ['question_ids' => [$b->id, $a->id, $c->id]]))->assertRedirect();
        $this->assertSame([$b->id, $a->id, $c->id], $this->ids($x));
        $this->assertSame([$c->id, $b->id, $a->id], $this->ids($y));
    }

    public function test_question_edits_cannot_change_any_membership_or_survey_position(): void
    {
        $this->login();
        [$a,$b] = $this->questions();
        $x = $this->survey('X');
        $y = $this->survey('Y');
        $x->questions()->attach([$a->id => ['position' => 2], $b->id => ['position' => 1]]);
        $y->questions()->attach([$a->id => ['position' => 1], $b->id => ['position' => 2]]);
        $this->put('/modules/evaluation/questions/'.$a->id, ['prompt' => 'Alpha edited', 'type' => 'text', 'is_active' => 1, 'is_required' => 1, 'sort_order' => 999, 'survey_ids' => []])->assertRedirect();
        $this->assertSame([$b->id, $a->id], $this->ids($x));
        $this->assertSame([$a->id, $b->id], $this->ids($y));
        $this->assertSame(2, $x->questions()->find($a->id)->pivot->position);
    }

    public function test_numeric_order_fallback_is_saved_and_empty_unselected_positions_are_ignored(): void
    {
        $this->login();
        [$a,$b,$c] = $this->questions();
        $s = $this->survey();
        $this->put('/modules/evaluation/surveys/'.$s->id, $this->updateData($s, ['question_ids' => [$a->id, $b->id], 'question_positions' => [$a->id => 20, $b->id => 10, $c->id => null]]))->assertRedirect();
        $this->assertSame([$b->id, $a->id], $this->ids($s));
    }

    public function test_invalid_duplicate_or_deleted_question_orders_do_not_mutate_assignments(): void
    {
        $this->login();
        [$a,$b,$c] = $this->questions();
        $s = $this->survey();
        $s->questions()->attach([$a->id => ['position' => 1], $b->id => ['position' => 2]]);
        foreach ([[$a->id => 1, $b->id => 1], [$a->id => 0], [$a->id => -1], [$a->id => 'invalid']] as $positions) {
            $this->put('/modules/evaluation/surveys/'.$s->id, $this->updateData($s, ['question_ids' => [$a->id, $b->id], 'question_positions' => $positions]))->assertSessionHasErrors();
        }$c->delete();
        $this->put('/modules/evaluation/surveys/'.$s->id, $this->updateData($s, ['question_ids' => [$c->id]]))->assertSessionHasErrors('question_ids.0');
        $this->put('/modules/evaluation/surveys/'.$s->id, $this->updateData($s, ['question_ids' => [$a->id, $a->id]]))->assertSessionHasErrors();
        $this->assertSame([$a->id, $b->id], $this->ids($s));
    }

    public function test_old_tied_positions_are_displayed_in_existing_order_and_metadata_edits_preserve_it(): void
    {
        $this->login();
        [$a,$b,$c] = $this->questions();
        $s = $this->survey();
        $s->questions()->attach([$c->id => ['position' => 5], $b->id => ['position' => 0], $a->id => ['position' => 0]]);
        $before = $this->ids($s);
        $this->get('/modules/evaluation/surveys/'.$s->id.'/edit')->assertOk()->assertSeeInOrder(['data-order-selected', 'Alpha', 'Beta', 'Gamma'], false);
        $this->put('/modules/evaluation/surveys/'.$s->id, $this->updateData($s, ['title' => 'Renamed']))->assertRedirect();
        $this->assertSame($before, $this->ids($s));
    }

    public function test_legacy_bank_sort_values_do_not_affect_questionnaire_fingerprints_or_survey_order(): void
    {
        [$a,$b] = $this->questions();
        $s = $this->survey();
        $s->questions()->attach([$b->id => ['position' => 1], $a->id => ['position' => 2]]);
        $questions = fn () => $s->questions()->orderBy('question_survey.position')->get();
        $before = Question::fingerprint($questions());
        DB::table('questions')->where('id', $a->id)->update(['sort_order' => 999]);
        $this->assertSame($before, Question::fingerprint($questions()));
        $this->assertSame([$b->id, $a->id], $this->ids($s));
    }

    public function test_reordering_invalidates_open_forms_but_preserves_submitted_answer_order(): void
    {
        [$a,$b] = $this->questions();
        $s = $this->survey();
        $s->update(['status' => 'active']);
        $s->questions()->attach([$a->id => ['position' => 1], $b->id => ['position' => 2]]);
        $e = Expert::create(['name' => 'Expert', 'type' => 'expert', 'is_active' => true]);
        $owner = $this->login(UserRole::Chairman);
        $d = ['survey_id' => $s->id, 'expert_id' => $e->id, 'viva_date' => now()->toDateString(), 'schema_token' => Question::fingerprint($s->questions()->orderBy('question_survey.position')->get()), 'answers' => [$a->id => 'First', $b->id => 'Second']];
        $this->post('/modules/evaluation/responses', $d)->assertRedirect();
        $v = Evaluation::firstOrFail();
        $snapshot = $v->answers;
        $this->login();
        $this->put('/modules/evaluation/surveys/'.$s->id, $this->updateData($s, ['question_ids' => [$b->id, $a->id]]))->assertRedirect();
        $this->actingAs($owner)->withSession(['auth_version' => $owner->auth_version]);
        $this->get('/modules/evaluation/responses/create?survey_id='.$s->id)->assertOk()->assertSeeInOrder(['Beta', 'Alpha']);
        $d['viva_date'] = now()->subDay()->toDateString();
        $this->post('/modules/evaluation/responses', $d)->assertSessionHasErrors('schema_token');
        $this->assertSame($snapshot, $v->fresh()->answers);
        $this->assertDatabaseHas('audit_logs', ['action' => 'survey.questions.updated']);
    }
}
