<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Evaluation;
use App\Models\Expert;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportBrowsingTest extends TestCase
{
    use RefreshDatabase;

    private function login(UserRole $role = UserRole::Admin): void
    {
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);
    }

    private function fixtures(int $alphaCount = 25): array
    {
        $a = Expert::create(['name' => 'Alpha Expert', 'type' => 'expert', 'is_active' => true]);
        $b = Expert::create(['name' => 'Beta Expert', 'type' => 'departmental', 'is_active' => true]);
        $s = Survey::create(['title' => 'Browsing Survey', 'status' => 'active', 'allowed_roles' => ['member']]);
        $u = User::factory()->create(['role' => UserRole::Member]);
        foreach ([[$a, $alphaCount, 'Alpha-only question'], [$b, 3, 'Beta-only question']] as [$e,$count,$prompt]) {
            for ($i = 0; $i < $count; $i++) {
                Evaluation::create(['survey_id' => $s->id, 'survey_snapshot' => ['title' => $s->title], 'expert_id' => $e->id, 'user_id' => $u->id, 'viva_date' => now()->subDays($i)->toDateString(), 'expert_snapshot' => ['name' => $e->name, 'type' => $e->type, 'designation' => null, 'organization' => null], 'evaluator_snapshot' => ['name' => $u->name, 'role' => 'Member'], 'answers' => [['question_id' => 1, 'prompt' => $prompt, 'type' => 'rating', 'value' => 4]], 'submitted_at' => now()]);
            }
        }

        return [$a, $b, $s];
    }

    public function test_report_shows_one_expert_summary_and_matching_submissions_at_a_time(): void
    {
        $this->login();
        [$a,$b] = $this->fixtures();
        $this->get('/modules/evaluation/reports/experts')->assertOk()->assertSee('Alpha-only question')->assertDontSee('Beta-only question')->assertSee('Expert summary navigation top')->assertSee('Expert summary navigation bottom')->assertViewHas('expertPages', fn ($p) => $p->total() === 2 && $p->currentPage() === 1)->assertViewHas('submissions', fn ($p) => $p->count() === 20 && $p->total() === 25 && $p->every(fn ($v) => $v->expert_id === $a->id));
        $this->get('/modules/evaluation/reports/experts?expert_page=2')->assertOk()->assertSee('Beta-only question')->assertDontSee('Alpha-only question')->assertViewHas('submissions', fn ($p) => $p->total() === 3 && $p->every(fn ($v) => $v->expert_id === $b->id));
    }

    public function test_submissions_use_twenty_records_with_numeric_top_bottom_navigation(): void
    {
        $this->login();
        [$a] = $this->fixtures(45);
        $r = $this->get('/modules/evaluation/reports/experts?per_page=10&submissions_page=2')->assertOk()->assertSee('Submission pages top')->assertSee('Submission pages bottom')->assertViewHas('submissions', fn ($p) => $p->currentPage() === 2 && $p->count() === 20 && $p->total() === 45);
        $this->assertStringContainsString('submissions_page=3', $r->viewData('submissions')->nextPageUrl());
        $this->assertSame(2, substr_count($r->getContent(), 'aria-current="page">2</span>'));
        $this->get($r->viewData('submissions')->url(3))->assertOk()->assertViewHas('submissions', fn ($p) => $p->currentPage() === 3 && $p->count() === 5 && $p->perPage() === 20);
        $this->get('/modules/evaluation/reports/experts?per_page=50')->assertOk()->assertViewHas('submissions', fn ($p) => $p->count() === 20 && $p->perPage() === 20);
    }

    public function test_expert_navigation_preserves_filters_and_resets_only_submission_page(): void
    {
        $this->login();
        [$a,$b,$s] = $this->fixtures();
        $r = $this->get('/modules/evaluation/reports/experts?survey_id='.$s->id.'&evaluator_role=Member&per_page=10&submissions_page=2')->assertOk();
        $next = $r->viewData('expertPages')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame('2', $query['expert_page']);
        $this->assertSame((string) $s->id, $query['survey_id']);
        $this->assertSame('Member', $query['evaluator_role']);
        $this->assertSame('20', $query['per_page']);
        $this->assertArrayNotHasKey('submissions_page', $query);
        $this->get($next)->assertOk()->assertViewHas('submissions', fn ($p) => $p->currentPage() === 1 && $p->total() === 3);
    }

    public function test_explicit_expert_filter_limits_navigation_to_that_expert(): void
    {
        $this->login();
        [$a,$b] = $this->fixtures();
        $this->get('/modules/evaluation/reports/experts?expert_id='.$b->id)->assertOk()->assertViewHas('expertPages', fn ($p) => $p->total() === 1)->assertSee('Beta-only question')->assertDontSee('Alpha-only question');
    }

    public function test_pagination_does_not_limit_filtered_csv_exports(): void
    {
        $this->login();
        $this->fixtures();
        $csv = $this->get('/modules/evaluation/reports/experts?expert_page=2&per_page=10&export=submissions')->assertOk()->streamedContent();
        $this->assertStringContainsString('Alpha-only question', $csv);
        $this->assertStringContainsString('Beta-only question', $csv);
        $this->assertSame(28, substr_count($csv, '-only question'));
    }

    public function test_reports_handle_removed_experts_empty_results_and_out_of_range_pages(): void
    {
        $this->login();
        [$a,$b] = $this->fixtures();
        $b->delete();
        $this->get('/modules/evaluation/reports/experts?expert_page=999')->assertOk()->assertViewHas('currentReportExpert', fn ($e) => $e->id === $b->id)->assertSee('Removed from pool');
        $this->get('/modules/evaluation/reports/experts?submissions_page=999&per_page=10')->assertOk()->assertViewHas('submissions', fn ($p) => $p->currentPage() === 2 && $p->count() === 5);
        $this->get('/modules/evaluation/reports/experts?expert_id=99999')->assertOk()->assertSee('No matching evaluation data.')->assertSee('No matching submissions.');
    }

    public function test_large_report_lookup_is_paginated_searchable_and_preserves_selected_expert(): void
    {
        $this->login();
        $rows = [];
        for ($i = 0; $i < 505; $i++) {
            $rows[] = ['name' => sprintf('Report Expert %03d', $i), 'type' => 'expert', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }DB::table('experts')->insert($rows);
        $url = '/modules/evaluation/reports/expert-options';
        $r = $this->getJson($url)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('has_more', true);
        $this->getJson($url.'?q=Report%20Expert%20504')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Report Expert 504');
        $last = Expert::where('name', 'Report Expert 504')->firstOrFail();
        $this->get('/modules/evaluation/reports/experts?expert_id='.$last->id)->assertOk()->assertSee('data-allow-empty="true"', false)->assertSee('Report Expert 504')->assertViewHas('experts', fn ($items) => $items->count() === 21);
    }

    public function test_report_lookup_includes_inactive_removed_experts_and_enforces_report_roles(): void
    {
        $this->login();
        $inactive = Expert::create(['name' => 'Inactive Historical Expert', 'designation' => 'Professor', 'type' => 'expert', 'is_active' => false]);
        $deleted = Expert::create(['name' => 'Removed Historical Expert', 'type' => 'departmental', 'is_active' => true]);
        $deleted->delete();
        $url = '/modules/evaluation/reports/expert-options?q=Historical';
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.label', 'Inactive Historical Expert · Professor · Expert (inactive)');
        $this->login(UserRole::Chairman);
        $this->getJson($url)->assertOk();
        foreach ([UserRole::Member, UserRole::Staff] as $role) {
            $this->login($role);
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_expert_profile_submission_page_size_uses_the_same_pagination_controls(): void
    {
        $this->login();
        [$a] = $this->fixtures();
        $this->get('/modules/evaluation/experts/'.$a->id.'?per_page=10&submissions_page=2')->assertOk()->assertSee('Submission pages top')->assertSee('Submission pages bottom')->assertViewHas('submissions', fn ($p) => $p->count() === 5 && $p->currentPage() === 2);
    }

    public function test_invalid_page_sizes_and_page_numbers_are_rejected(): void
    {
        $this->login();
        foreach (['per_page=500', 'expert_page=0', 'submissions_page=-1'] as $query) {
            $this->get('/modules/evaluation/reports/experts?'.$query)->assertSessionHasErrors();
        }
    }
}
