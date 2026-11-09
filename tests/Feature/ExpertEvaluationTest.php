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

class ExpertEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(UserRole $role): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);

        return $u;
    }

    private function expert(array $extra = []): Expert
    {
        return Expert::create(array_merge(['name' => 'Test Expert', 'type' => 'expert', 'organization' => 'BPSC', 'is_active' => true, 'is_public' => false], $extra));
    }

    private function question(array $extra = []): Question
    {
        $question = Question::create(array_merge(['survey_id' => $this->survey()->id, 'prompt' => 'Professional conduct', 'type' => 'rating', 'is_active' => true, 'is_required' => true, 'sort_order' => 1], $extra));
        $this->survey()->questions()->attach($question->id, ['position' => $extra['sort_order'] ?? 1]);

        return $question;
    }

    private function survey(): Survey
    {
        return Survey::firstOrCreate(['title' => 'Test Survey'], ['status' => 'active', 'allowed_roles' => ['chairman', 'member']]);
    }

    private function payload(Expert $e, array $answers): array
    {
        return ['survey_id' => $this->survey()->id, 'expert_id' => $e->id, 'viva_date' => now()->toDateString(), 'schema_token' => Question::fingerprint(Question::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()), 'answers' => $answers];
    }

    public function test_no_public_directory_or_expert_profile_exists(): void
    {
        $e = $this->expert(['is_public' => true]);
        $this->get('/')->assertRedirect('/login');
        $this->get('/experts/'.$e->id)->assertNotFound();
        $this->get('/modules/evaluation/experts')->assertRedirect('/login');
        $this->signIn(UserRole::Staff);
        $this->get('/modules/evaluation/experts/create')->assertOk()->assertDontSee('name="is_public"', false);
    }

    public function test_internal_expert_search_and_type_filters(): void
    {
        $this->signIn(UserRole::Staff);
        $this->expert(['name' => 'Search Target', 'type' => 'departmental', 'specialization' => 'Physics']);
        $this->get('/modules/evaluation/experts?q=Physics&type=departmental')->assertOk()->assertSee('Search Target');
        $this->get('/modules/evaluation/experts?type=expert')->assertOk()->assertDontSee('Search Target');
    }

    public function test_only_admin_and_staff_can_manage_experts(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->signIn($role);
            $allow = in_array($role, [UserRole::Admin, UserRole::Staff]);
            $this->get('/modules/evaluation/experts')->assertStatus($role !== UserRole::Member ? 200 : 403);
            $this->get('/modules/evaluation/experts/create')->assertStatus($allow ? 200 : 403);
        }
    }

    public function test_staff_expert_crud_is_audited_and_removal_hides_public_profile(): void
    {
        $this->signIn(UserRole::Staff);
        $d = ['name' => 'New Expert', 'type' => 'departmental', 'is_active' => 1, 'is_public' => 1];
        $this->post('/modules/evaluation/experts', $d)->assertRedirect();
        $e = Expert::where('name', 'New Expert')->firstOrFail();
        $this->get('/modules/evaluation/experts/'.$e->id)->assertOk();
        $this->get('/modules/evaluation/experts/'.$e->id.'/edit')->assertOk();
        $d['name'] = 'Edited Expert';
        $this->put('/modules/evaluation/experts/'.$e->id, $d)->assertRedirect();
        $this->delete('/modules/evaluation/experts/'.$e->id, ['confirmation' => 'DELETE'])->assertForbidden();
        $this->signIn(UserRole::Admin);
        $this->delete('/modules/evaluation/experts/'.$e->id, ['confirmation' => 'DELETE'])->assertRedirect('/modules/evaluation/experts');
        $this->assertSoftDeleted($e);
        $this->get('/experts/'.$e->id)->assertNotFound();
        foreach (['expert.created', 'expert.updated', 'expert.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    public function test_question_crud_and_choice_validation(): void
    {
        $this->signIn(UserRole::Admin);
        $this->get('/modules/evaluation/questions/create')->assertOk();
        $d = ['survey_id' => $this->survey()->id, 'prompt' => 'Suitable?', 'type' => 'radio', 'option_lines' => "Yes\nNo", 'sort_order' => 2, 'is_required' => 1, 'is_active' => 1];
        $this->post('/modules/evaluation/questions', $d)->assertRedirect(route('questions.index'));
        $q = Question::firstOrFail();
        $this->assertSame(['Yes', 'No'], $q->options);
        $this->get('/modules/evaluation/questions/'.$q->id.'/edit')->assertOk();
        $d['option_lines'] = 'Yes';
        $this->put('/modules/evaluation/questions/'.$q->id, $d)->assertSessionHasErrors('option_lines');
        $d['option_lines'] = "Yes\nMaybe\nNo";
        $this->put('/modules/evaluation/questions/'.$q->id, $d)->assertRedirect();
        $this->delete('/modules/evaluation/questions/'.$q->id, ['confirmation' => 'DELETE'])->assertRedirect();
        $this->assertSoftDeleted($q);
    }

    public function test_chairman_and_member_can_submit_survey_types(): void
    {
        $e = $this->expert();
        $rating = $this->question();
        $choice = $this->question(['prompt' => 'Recommendation', 'type' => 'radio', 'options' => ['Yes', 'No'], 'sort_order' => 2]);
        $text = $this->question(['prompt' => 'Remarks', 'type' => 'textarea', 'is_required' => false, 'sort_order' => 3]);
        foreach ([UserRole::Chairman, UserRole::Member] as $role) {
            $u = $this->signIn($role);
            $this->get('/modules/evaluation/responses/create?survey_id='.$this->survey()->id)->assertOk();
            $this->post('/modules/evaluation/responses', $this->payload($e, [$rating->id => 5, $choice->id => 'Yes', $text->id => 'Helpful']))->assertRedirect();
            $evaluation = Evaluation::where('user_id', $u->id)->firstOrFail();
            $this->get('/modules/evaluation/responses/'.$evaluation->id)->assertOk()->assertSee('Helpful');
            $this->assertDatabaseHas('audit_logs', ['action' => 'evaluation.submitted', 'actor_id' => $u->id]);
        }
    }

    public function test_admin_and_staff_cannot_submit_evaluations(): void
    {
        foreach ([UserRole::Admin, UserRole::Staff] as $role) {
            $this->signIn($role);
            $this->get('/modules/evaluation/responses/create')->assertForbidden();
            $this->post('/modules/evaluation/responses', [])->assertForbidden();
        }
    }

    public function test_only_owner_and_authorized_report_readers_can_read_evaluation_answers(): void
    {
        $e = $this->expert();
        $q = $this->question(['type' => 'textarea']);
        $owner = $this->signIn(UserRole::Member);
        $this->post('/modules/evaluation/responses', $this->payload($e, [$q->id => 'Private answer']));
        $evaluation = Evaluation::firstOrFail();
        foreach ([UserRole::Chairman, UserRole::Member, UserRole::Staff] as $role) {
            $this->signIn($role);
            $this->get('/modules/evaluation/responses/'.$evaluation->id)->assertStatus($role === UserRole::Chairman ? 200 : 403);
            $this->get('/dashboard')->assertDontSee('Private answer');
            if ($role !== UserRole::Staff) {
                $this->get('/modules/evaluation/responses')->assertOk()->assertDontSee('Test Expert');
            } else {
                $this->get('/modules/evaluation/responses')->assertForbidden();
            }
        }$this->signIn(UserRole::Admin);
        $this->get('/modules/evaluation/responses/'.$evaluation->id)->assertOk()->assertSee('Private answer');
        $this->get('/modules/evaluation/responses')->assertOk()->assertSee('Test Expert');
    }

    public function test_validation_rejects_invalid_missing_or_unknown_answers(): void
    {
        $this->signIn(UserRole::Chairman);
        $e = $this->expert();
        $q = $this->question();
        foreach ([[], [$q->id => 6], [$q->id => 'bad'], [$q->id => 5, 999 => 'Injected']] as $answers) {
            $this->post('/modules/evaluation/responses', $this->payload($e, $answers))->assertSessionHasErrors();
        }$this->assertDatabaseCount('evaluations', 0);
    }

    public function test_text_and_choice_validation_and_optional_skip(): void
    {
        $this->signIn(UserRole::Member);
        $e = $this->expert();
        $q = $this->question(['type' => 'radio', 'options' => ['Yes', 'No']]);
        $t = $this->question(['type' => 'textarea', 'sort_order' => 2, 'is_required' => false]);
        $this->post('/modules/evaluation/responses', $this->payload($e, [$q->id => 'Invalid']))->assertSessionHasErrors('answers.'.$q->id);
        $this->post('/modules/evaluation/responses', $this->payload($e, [$q->id => 'Yes', $t->id => str_repeat('x', 5001)]))->assertSessionHasErrors('answers.'.$t->id);
        $this->post('/modules/evaluation/responses', $this->payload($e, [$q->id => 'No']))->assertRedirect();
        $this->assertNull(Evaluation::firstOrFail()->answers[1]['value']);
    }

    public function test_inactive_experts_future_dates_and_duplicate_evaluations_are_rejected(): void
    {
        $this->signIn(UserRole::Member);
        $e = $this->expert(['is_active' => false]);
        $q = $this->question();
        $d = $this->payload($e, [$q->id => 4]);
        $this->post('/modules/evaluation/responses', $d)->assertSessionHasErrors('expert_id');
        $e->update(['is_active' => true]);
        $d['viva_date'] = now()->addDay()->toDateString();
        $this->post('/modules/evaluation/responses', $d)->assertSessionHasErrors('viva_date');
        $d['viva_date'] = now()->toDateString();
        $this->post('/modules/evaluation/responses', $d)->assertRedirect();
        $this->post('/modules/evaluation/responses', $d)->assertSessionHasErrors('expert_id');
        $this->assertDatabaseCount('evaluations', 1);
    }

    public function test_question_changes_reject_stale_form(): void
    {
        $this->signIn(UserRole::Chairman);
        $e = $this->expert();
        $q = $this->question();
        $d = $this->payload($e, [$q->id => 4]);
        $q->update(['prompt' => 'Updated question']);
        $this->post('/modules/evaluation/responses', $d)->assertSessionHasErrors('schema_token');
        $this->assertDatabaseCount('evaluations', 0);
    }

    public function test_previous_evaluations_preserve_question_and_identity_snapshots(): void
    {
        $u = $this->signIn(UserRole::Member);
        $e = $this->expert();
        $q = $this->question();
        $this->post('/modules/evaluation/responses', $this->payload($e, [$q->id => 4]))->assertRedirect();
        $v = Evaluation::firstOrFail();
        $q->update(['prompt' => 'Changed question']);
        $q->delete();
        $e->update(['name' => 'Renamed Expert']);
        $e->delete();
        $this->get('/modules/evaluation/responses/'.$v->id)->assertOk()->assertSee('Professional conduct')->assertSee('Test Expert')->assertDontSee('Changed question');
        $this->assertSame($u->name, $v->evaluator_snapshot['name']);
        $this->put('/modules/evaluation/responses/'.$v->id, [])->assertStatus(405);
        $this->delete('/modules/evaluation/responses/'.$v->id)->assertStatus(405);
    }

    public function test_no_active_questions_prevents_submission(): void
    {
        $this->signIn(UserRole::Member);
        $e = $this->expert();
        $this->get('/modules/evaluation/responses/create?survey_id='.$this->survey()->id)->assertOk()->assertSee('Evaluation is not available yet');
        $this->post('/modules/evaluation/responses', ['survey_id' => $this->survey()->id, 'expert_id' => $e->id, 'viva_date' => now()->toDateString(), 'schema_token' => str_repeat('a', 64), 'answers' => [1 => 3]])->assertSessionHasErrors('schema_token');
    }

    public function test_unauthenticated_management_redirects_to_login(): void
    {
        foreach (['/dashboard', '/modules/evaluation/experts', '/modules/evaluation/questions', '/modules/evaluation/responses', '/users', '/audit-logs'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }
}
