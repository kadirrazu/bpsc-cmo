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

class SurveyModuleTest extends TestCase
{
    use RefreshDatabase;

    private function login(UserRole $role = UserRole::Admin): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);

        return $u;
    }

    private function survey(array $extra = []): Survey
    {
        return Survey::create(array_merge(['title' => 'Survey A', 'status' => 'active', 'allowed_roles' => ['chairman', 'member']], $extra));
    }

    private function question(Survey $survey, string $type = 'rating', array $extra = []): Question
    {
        return $survey->questions()->create(array_merge(['prompt' => ucfirst($type).' question', 'type' => $type, 'is_active' => true, 'is_required' => true, 'sort_order' => 1, 'options' => in_array($type, ['select', 'radio', 'multiple']) ? ['Alpha', 'Beta', 'Gamma'] : null], $extra));
    }

    private function payload(Survey $survey, array $answers): array
    {
        return ['survey_id' => $survey->id, 'expert_id' => Expert::firstOrCreate(['name' => 'Expert A'], ['type' => 'expert', 'is_active' => true])->id, 'viva_date' => now()->toDateString(), 'schema_token' => Question::fingerprint($survey->questions()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()), 'answers' => $answers];
    }

    public function test_common_navigation_and_module_navigation_are_separate_for_every_role(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->login($role);
            $this->get('/dashboard')->assertOk()->assertSee('Open Module')->assertDontSee('aria-label="Expert Evaluation System navigation"', false)->assertDontSee('Public Directory');
            $this->get('/profile')->assertOk()->assertDontSee('aria-label="Expert Evaluation System navigation"', false);
            $response = $this->get('/modules/evaluation')->assertOk()->assertSee('aria-label="Expert Evaluation System navigation"', false);
            if ($role === UserRole::Staff) {
                $response->assertSee('href="'.route('surveys.index').'"', false)->assertDontSee('href="'.route('evaluations.index').'"', false);
            }if ($role === UserRole::Member) {
                $response->assertDontSee('href="'.route('experts.index').'"', false);
            }
        }
    }

    public function test_participant_roles_cannot_manage_surveys(): void
    {
        $s = $this->survey();
        foreach ([UserRole::Chairman, UserRole::Member] as $role) {
            $this->login($role);
            $this->get('/modules/evaluation/surveys/create')->assertForbidden();
            $this->get('/modules/evaluation/surveys/'.$s->id.'/edit')->assertForbidden();
            $this->post('/modules/evaluation/surveys', [])->assertForbidden();
            $this->put('/modules/evaluation/surveys/'.$s->id, [])->assertForbidden();
            $this->delete('/modules/evaluation/surveys/'.$s->id, ['confirmation' => 'DELETE'])->assertForbidden();
        }
    }

    public function test_administrator_can_create_configure_activate_close_and_delete_a_survey(): void
    {
        $this->login();
        $this->get('/modules/evaluation/surveys/create')->assertOk();
        $d = ['title' => 'Configured Survey', 'description' => 'Instructions', 'status' => 'draft', 'allowed_roles' => ['member']];
        $this->post('/modules/evaluation/surveys', $d)->assertRedirect();
        $s = Survey::firstOrFail();
        $this->get('/modules/evaluation/surveys/'.$s->id)->assertOk()->assertSee('Create Bank Question');
        $this->get('/modules/evaluation/surveys/'.$s->id.'/edit')->assertOk();
        $d['status'] = 'active';
        $this->put('/modules/evaluation/surveys/'.$s->id, $d)->assertSessionHasErrors('status');
        $this->question($s);
        $this->put('/modules/evaluation/surveys/'.$s->id, $d)->assertRedirect();
        $this->assertSame('active', $s->fresh()->status);
        $d['status'] = 'closed';
        $this->put('/modules/evaluation/surveys/'.$s->id, $d)->assertRedirect();
        $this->delete('/modules/evaluation/surveys/'.$s->id, ['confirmation' => 'DELETE'])->assertRedirect();
        $this->assertSoftDeleted($s);
        foreach (['survey.created', 'survey.updated', 'survey.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    public function test_survey_participants_only_see_active_surveys_for_their_role(): void
    {
        $member = $this->survey(['title' => 'Member Assigned', 'allowed_roles' => ['member']]);
        $chairman = $this->survey(['title' => 'Chairman Assigned', 'allowed_roles' => ['chairman']]);
        $draft = $this->survey(['title' => 'Draft Hidden', 'status' => 'draft']);
        $closed = $this->survey(['title' => 'Closed Hidden', 'status' => 'closed']);
        foreach ([UserRole::Member, UserRole::Chairman] as $role) {
            $this->login($role);
            $allowed = $role === UserRole::Member ? $member : $chairman;
            $denied = $role === UserRole::Member ? $chairman : $member;
            $this->get('/modules/evaluation/surveys')->assertOk()->assertSee($allowed->title)->assertDontSee($denied->title)->assertDontSee('Draft Hidden')->assertDontSee('Closed Hidden');
            $this->get('/modules/evaluation/surveys/'.$allowed->id)->assertOk();
            foreach ([$denied, $draft, $closed] as $s) {
                $this->get('/modules/evaluation/surveys/'.$s->id)->assertForbidden();
                $this->get('/modules/evaluation/responses/create?survey_id='.$s->id)->assertForbidden();
                $this->post('/modules/evaluation/responses', $this->payload($s, []))->assertForbidden();
            }
        }
    }

    public function test_all_eight_question_types_render_and_submit_with_valid_values(): void
    {
        $this->login(UserRole::Member);
        $s = $this->survey();
        $values = ['select' => 'Alpha', 'date' => '2026-11-09', 'time' => '14:30', 'rating' => 5, 'text' => 'Short answer', 'textarea' => "Long answer\nSecond line", 'radio' => 'Beta', 'multiple' => ['Alpha', 'Gamma']];
        $answers = [];
        foreach ($values as $type => $value) {
            $q = $this->question($s, $type);
            $answers[$q->id] = $value;
        }
        $this->payload($s, $answers);
        $response = $this->get('/modules/evaluation/responses/create?survey_id='.$s->id)->assertOk();
        foreach (['type="date"', 'type="time"', 'type="text"', 'type="radio"', 'type="checkbox"', '<textarea', '<select'] as $markup) {
            $response->assertSee($markup, false);
        }$response->assertSee('value="'.now()->toDateString().'"', false);
        $this->post('/modules/evaluation/responses', $this->payload($s, $answers))->assertRedirect();
        $v = Evaluation::firstOrFail();
        $this->assertCount(8, $v->answers);
        $this->assertSame(['Alpha', 'Gamma'], $v->answers[7]['value']);
        $this->get('/modules/evaluation/responses/'.$v->id)->assertOk()->assertSee('Alpha, Gamma')->assertSee('Long answer')->assertSee('Survey A');
    }

    public function test_all_eight_types_can_be_created_through_question_management(): void
    {
        $this->login();
        $s = $this->survey(['status' => 'draft']);
        foreach (Question::TYPES as $type => $label) {
            $this->post('/modules/evaluation/questions', ['survey_id' => $s->id, 'prompt' => $label, 'type' => $type, 'option_lines' => "Alpha\nBeta", 'sort_order' => 1, 'is_required' => 1, 'is_active' => 1])->assertRedirect();
            $this->assertDatabaseHas('questions', ['type' => $type, 'prompt' => $label]);
        }$this->get('/modules/evaluation/questions?survey_id='.$s->id)->assertOk();
        $this->get('/modules/evaluation/questions/create?survey_id='.$s->id)->assertOk();
    }

    public function test_date_time_and_short_text_validation_is_strict(): void
    {
        $this->login(UserRole::Member);
        $s = $this->survey();
        $date = $this->question($s, 'date');
        $time = $this->question($s, 'time');
        $text = $this->question($s, 'text');
        $valid = [$date->id => '2026-11-09', $time->id => '10:15', $text->id => 'Okay'];
        foreach ([[$date->id, '2026-02-30'], [$date->id, '09/11/2026'], [$time->id, '25:00'], [$time->id, '10:15:00'], [$text->id, str_repeat('x', 256)]] as [$id,$value]) {
            $d = $valid;
            $d[$id] = $value;
            $this->post('/modules/evaluation/responses', $this->payload($s, $d))->assertSessionHasErrors('answers.'.$id);
        }$this->assertDatabaseCount('evaluations', 0);
    }

    public function test_multiple_select_rejects_scalar_unknown_duplicate_and_empty_required_values(): void
    {
        $this->login(UserRole::Member);
        $s = $this->survey();
        $q = $this->question($s, 'multiple');
        foreach (['Alpha', ['Unknown'], ['Alpha', 'Alpha'], []] as $invalid) {
            $this->post('/modules/evaluation/responses', $this->payload($s, [$q->id => $invalid]))->assertSessionHasErrors();
        }$this->assertDatabaseCount('evaluations', 0);
    }

    public function test_optional_only_survey_accepts_no_answers_and_validation_can_render_again(): void
    {
        $this->login(UserRole::Member);
        $s = $this->survey();
        $q = $this->question($s, 'multiple', ['is_required' => false]);
        $d = $this->payload($s, []);
        unset($d['answers']);
        $this->post('/modules/evaluation/responses', $d)->assertRedirect();
        $this->assertNull(Evaluation::firstOrFail()->answers[0]['value']);
        $this->withSession(['_old_input' => ['answers' => [$q->id => 'Forged scalar']]])->get('/modules/evaluation/responses/create?survey_id='.$s->id)->assertOk();
    }

    public function test_questions_and_fingerprints_cannot_be_mixed_between_surveys(): void
    {
        $this->login(UserRole::Member);
        $a = $this->survey();
        $b = $this->survey(['title' => 'Survey B']);
        $qa = $this->question($a);
        $qb = $this->question($b);
        $this->post('/modules/evaluation/responses', $this->payload($a, [$qb->id => 5]))->assertSessionHasErrors();
        $d = $this->payload($a, [$qa->id => 5]);
        $d['survey_id'] = $b->id;
        $this->post('/modules/evaluation/responses', $d)->assertSessionHasErrors('schema_token');
        $this->assertDatabaseCount('evaluations', 0);
    }

    public function test_chairman_can_submit_in_different_surveys_on_same_day(): void
    {
        $this->login(UserRole::Chairman);
        foreach (['First Survey', 'Second Survey'] as $title) {
            $s = $this->survey(['title' => $title]);
            $q = $this->question($s);
            $this->post('/modules/evaluation/responses', $this->payload($s, [$q->id => 4]))->assertRedirect();
        }$this->assertDatabaseCount('evaluations', 2);
    }

    public function test_closed_or_deleted_surveys_block_open_forms_and_preserve_previous_responses(): void
    {
        $this->login(UserRole::Member);
        $s = $this->survey();
        $q = $this->question($s);
        $d = $this->payload($s, [$q->id => 4]);
        $this->post('/modules/evaluation/responses', $d)->assertRedirect();
        $v = Evaluation::firstOrFail();
        $s->update(['title' => 'Renamed Survey', 'status' => 'closed']);
        $this->post('/modules/evaluation/responses', $d)->assertForbidden();
        $s->delete();
        $this->get('/modules/evaluation/responses/create?survey_id='.$s->id)->assertNotFound();
        $this->post('/modules/evaluation/responses', $d)->assertNotFound();
        $this->get('/modules/evaluation/responses/'.$v->id)->assertOk()->assertSee('Survey A')->assertDontSee('Renamed Survey');
    }

    public function test_guests_cannot_access_any_evaluation_module_page(): void
    {
        foreach (['/modules/evaluation', '/modules/evaluation/experts', '/modules/evaluation/surveys', '/modules/evaluation/questions', '/modules/evaluation/responses/create', '/modules/evaluation/responses'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }$this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertDontSee('Directory');
        foreach (['/experts/1', '/admin/experts', '/questions', '/evaluations'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}
