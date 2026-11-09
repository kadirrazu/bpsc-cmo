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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function login(UserRole $role = UserRole::Member): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);

        return $u;
    }

    private function survey(array $extra = []): Survey
    {
        $s = Survey::create(array_merge(['title' => 'Open Survey', 'status' => 'active', 'allowed_roles' => ['member', 'chairman']], $extra));
        $s->questions()->create(['prompt' => 'Assessment', 'type' => 'rating', 'is_required' => true, 'is_active' => true, 'rating_min' => 1, 'rating_max' => 5, 'sort_order' => 1]);

        return $s;
    }

    private function expert(array $extra = []): Expert
    {
        return Expert::create(array_merge(['name' => 'Expert A', 'type' => 'expert', 'is_active' => true], $extra));
    }

    private function payload(Survey $s, Expert $e, ?string $date = null): array
    {
        $q = $s->questions()->where('is_active', true)->orderBy('question_survey.position')->orderBy('questions.id')->get();

        return ['survey_id' => $s->id, 'expert_id' => $e->id, 'viva_date' => $date ?? now()->toDateString(), 'schema_token' => Question::fingerprint($q), 'answers' => [$q->first()->id => 4]];
    }

    public function test_member_dashboard_links_only_to_active_surveys_for_members(): void
    {
        $this->login();
        $s = $this->survey(['title' => 'Visible Member Survey']);
        $this->survey(['title' => 'Chairman Only', 'allowed_roles' => ['chairman']]);
        $this->survey(['title' => 'Draft Hidden', 'status' => 'draft']);
        $this->survey(['title' => 'Closed Hidden', 'status' => 'closed']);
        $removed = $this->survey(['title' => 'Removed Hidden']);
        $removed->delete();
        $this->get('/dashboard')->assertOk()->assertSee('Active Surveys')->assertSee('Visible Member Survey')->assertSee('href="'.route('evaluations.create', ['survey_id' => $s->id]).'"', false)->assertDontSee('Chairman Only')->assertDontSee('Draft Hidden')->assertDontSee('Closed Hidden')->assertDontSee('Removed Hidden');
    }

    public function test_member_dashboard_handles_no_available_surveys_and_other_roles_keep_common_dashboard(): void
    {
        $this->login();
        $this->get('/dashboard')->assertOk()->assertSee('No active surveys are currently available to your role.');
        foreach ([UserRole::Admin, UserRole::Chairman, UserRole::Staff] as $role) {
            $this->login($role);
            $this->get('/dashboard')->assertOk()->assertDontSee('id="active-surveys-title"', false);
        }
    }

    public function test_member_cannot_evaluate_same_expert_and_viva_date_across_different_surveys(): void
    {
        $this->login();
        $a = $this->survey();
        $b = $this->survey(['title' => 'Second Survey']);
        $e = $this->expert();
        $this->post('/modules/evaluation/responses', $this->payload($a, $e))->assertRedirect();
        $this->post('/modules/evaluation/responses', $this->payload($b, $e))->assertSessionHasErrors('expert_id');
        $this->assertDatabaseCount('evaluations', 1);
        $this->assertDatabaseCount('member_evaluation_days', 1);
    }

    public function test_other_dates_experts_and_members_are_independent(): void
    {
        $owner = $this->login();
        $s = $this->survey();
        $e = $this->expert();
        $other = $this->expert(['name' => 'Other Expert']);
        foreach ([[$e, now()->toDateString()], [$e, now()->subDay()->toDateString()], [$other, now()->toDateString()]] as [$expert,$date]) {
            $this->post('/modules/evaluation/responses', $this->payload($s, $expert, $date))->assertRedirect();
        }$this->login();
        $this->post('/modules/evaluation/responses', $this->payload($s, $e))->assertRedirect();
        $this->assertDatabaseCount('evaluations', 4);
        $this->assertDatabaseCount('member_evaluation_days', 4);
    }

    public function test_day_reservation_unique_constraint_rejects_a_competing_submission(): void
    {
        $u = $this->login();
        $s = $this->survey();
        $e = $this->expert();
        DB::table('member_evaluation_days')->insert(['user_id' => $u->id, 'expert_id' => $e->id, 'viva_date' => now()->toDateString()]);
        $this->post('/modules/evaluation/responses', $this->payload($s, $e))->assertSessionHasErrors('expert_id');
        $this->assertDatabaseCount('evaluations', 0);
        $this->assertDatabaseCount('member_evaluation_days', 1);
    }

    public function test_invalid_submission_does_not_reserve_the_expert_day(): void
    {
        $this->login();
        $s = $this->survey();
        $e = $this->expert();
        $d = $this->payload($s, $e);
        $d['answers'] = [];
        $this->post('/modules/evaluation/responses', $d)->assertSessionHasErrors();
        $this->assertDatabaseCount('member_evaluation_days', 0);
        $this->post('/modules/evaluation/responses', $this->payload($s, $e))->assertRedirect();
    }

    public function test_large_expert_pool_is_searched_on_the_server_and_paginated(): void
    {
        $this->login();
        $s = $this->survey();
        $rows = [];
        for ($i = 0; $i < 505; $i++) {
            $rows[] = ['name' => sprintf('Pool Expert %03d', $i), 'type' => 'expert', 'is_active' => true, 'email' => 'secret-'.$i.'@example.test', 'mobile_number' => '01700123456', 'created_at' => now(), 'updated_at' => now()];
        }DB::table('experts')->insert($rows);
        $url = '/modules/evaluation/responses/expert-options?survey_id='.$s->id;
        $r = $this->getJson($url)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('has_more', true)->assertJsonPath('next_page', 2);
        $this->assertArrayNotHasKey('email', $r->json('data.0'));
        $this->assertArrayNotHasKey('mobile_number', $r->json('data.0'));
        $this->getJson($url.'&q=Pool%20Expert%20504')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Pool Expert 504');
        $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('data.0.name', 'Pool Expert 020');
        $this->get('/modules/evaluation/responses/create?survey_id='.$s->id)->assertOk()->assertSee('data-expert-picker', false)->assertDontSee('Pool Expert 504');
    }

    public function test_expert_search_matches_details_and_excludes_inactive_or_deleted_experts(): void
    {
        $this->login();
        $s = $this->survey();
        $e = $this->expert(['name' => 'Target', 'organization' => 'Special Organization', 'designation' => 'Professor', 'specialization' => 'Marine Science', 'type' => 'departmental']);
        $this->expert(['name' => 'Inactive Marine', 'is_active' => false]);
        $deleted = $this->expert(['name' => 'Deleted Marine']);
        $deleted->delete();
        $url = '/modules/evaluation/responses/expert-options?survey_id='.$s->id;
        foreach (['Marine', 'Special Organization', 'Professor', 'Target'] as $term) {
            $this->getJson($url.'&q='.urlencode($term))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $e->id);
        }$this->getJson($url.'&q=NoSuchExpert')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'&page=0')->assertUnprocessable();
    }

    public function test_lookup_requires_login_evaluator_role_and_an_available_survey(): void
    {
        $s = $this->survey();
        $url = '/modules/evaluation/responses/expert-options?survey_id='.$s->id;
        $this->getJson($url)->assertUnauthorized();
        foreach ([UserRole::Admin, UserRole::Staff] as $role) {
            $this->login($role);
            $this->getJson($url)->assertForbidden();
        }$this->login();
        $s->update(['allowed_roles' => ['chairman']]);
        $this->getJson($url)->assertForbidden();
        $s->update(['allowed_roles' => ['member'], 'status' => 'closed']);
        $this->getJson($url)->assertForbidden();
    }

    public function test_selected_expert_outside_first_page_is_preserved_after_validation(): void
    {
        $this->login();
        $s = $this->survey();
        for ($i = 0; $i < 25; $i++) {
            $this->expert(['name' => 'A Expert '.$i]);
        }$last = $this->expert(['name' => 'Z Selected Expert']);
        $this->withSession(['_old_input' => ['expert_id' => $last->id]])->get('/modules/evaluation/responses/create?survey_id='.$s->id)->assertOk()->assertSee('Z Selected Expert')->assertSee('value="'.$last->id.'" selected', false);
    }

    public function test_time_defaults_required_markers_and_responsive_cards_render_without_overwriting_old_answers(): void
    {
        $this->login();
        $s = $this->survey();
        $time = $s->questions()->create(['prompt' => 'Meeting Time', 'type' => 'time', 'is_active' => true, 'is_required' => false]);
        $this->expert();
        $url = '/modules/evaluation/responses/create?survey_id='.$s->id;
        $this->get($url)->assertOk()->assertSee('value="10:00"', false)->assertSee('Default: 10:00 AM')->assertSee('Questions marked with a red star require an answer. All other questions are optional.')->assertSee('survey-required', false)->assertSee('col-lg-6', false)->assertSee('survey-answer-card', false);
        $this->withSession(['_old_input' => ['answers' => [$time->id => '14:45']]])->get($url)->assertOk()->assertSee('value="14:45"', false);
        $this->withSession(['_old_input' => ['answers' => [$time->id => '']]])->get($url)->assertOk()->assertDontSee('value="10:00"', false);
    }

    public function test_legacy_duplicate_submissions_are_preserved_and_migration_backfills_one_day_reservation(): void
    {
        $u = $this->login();
        $e = $this->expert();
        $a = $this->survey();
        $b = $this->survey(['title' => 'Other']);
        foreach ([$a, $b] as $s) {
            Evaluation::create(['survey_id' => $s->id, 'survey_snapshot' => ['title' => $s->title], 'expert_id' => $e->id, 'user_id' => $u->id, 'viva_date' => now()->toDateString(), 'expert_snapshot' => ['name' => $e->name, 'designation' => null, 'organization' => null], 'evaluator_snapshot' => ['name' => $u->name, 'role' => 'Member'], 'answers' => [], 'submitted_at' => now()]);
        }Schema::drop('member_evaluation_days');
        $migration = require database_path('migrations/2026_11_09_000004_enforce_member_evaluation_days.php');
        $migration->up();
        $this->assertDatabaseCount('evaluations', 2);
        $this->assertDatabaseCount('member_evaluation_days', 1);
        $this->post('/modules/evaluation/responses', $this->payload($a, $e))->assertSessionHasErrors('expert_id');
    }
}
