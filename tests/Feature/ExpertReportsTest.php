<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Evaluation;
use App\Models\Expert;
use App\Models\Survey;
use App\Models\User;
use App\Services\Evaluation\ExpertReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpertReportsTest extends TestCase
{
    use RefreshDatabase;

    private function login(UserRole $role = UserRole::Admin): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);

        return $u;
    }

    private function submission(Expert $e, Survey $s, User $u, int $score, int $max = 5, string $date = '2026-11-01'): Evaluation
    {
        return Evaluation::create(['survey_id' => $s->id, 'survey_snapshot' => ['title' => $s->title], 'expert_id' => $e->id, 'user_id' => $u->id, 'viva_date' => $date, 'expert_snapshot' => ['name' => $e->name, 'type' => $e->type, 'designation' => null, 'organization' => 'Org'], 'evaluator_snapshot' => ['name' => $u->name, 'role' => $u->role->label()], 'answers' => [['question_id' => 1, 'prompt' => 'Professional Knowledge', 'type' => 'rating', 'options' => null, 'rating_min' => 1, 'rating_max' => $max, 'value' => $score], ['question_id' => 2, 'prompt' => '=CMD', 'hints' => 'Secret Hints', 'type' => 'textarea', 'options' => null, 'value' => 'Private narrative']], 'submitted_at' => now()]);
    }

    private function fixtures(): array
    {
        $e = Expert::create(['name' => 'Reported Expert', 'type' => 'expert', 'is_active' => true]);
        $s = Survey::create(['title' => 'Rated Survey', 'status' => 'active', 'allowed_roles' => ['member', 'chairman']]);
        $m = User::factory()->create(['role' => UserRole::Member]);
        $c = User::factory()->create(['role' => UserRole::Chairman]);
        $a = $this->submission($e, $s, $m, 2, 5, '2026-11-01');
        $b = $this->submission($e, $s, $c, 4, 5, '2026-11-02');
        $d = $this->submission($e, $s, $m, 8, 10, '2026-11-03');

        return [$e, $s, $m, $c, $a, $b, $d];
    }

    public function test_rating_averages_are_separated_by_scale_and_expert(): void
    {
        [$e,$s] = $this->fixtures();
        $summary = app(ExpertReport::class)->summary([]);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['expert_count']);
        $this->assertSame(1, $summary['survey_count']);
        $ratings = collect($summary['rows'])->where('type', 'rating')->values();
        $this->assertCount(2, $ratings);
        $this->assertEquals(3, $ratings->firstWhere('max', 5)['average']);
        $this->assertEquals(8, $ratings->firstWhere('max', 10)['average']);
        $this->assertSame(2, $ratings->firstWhere('max', 5)['answered']);
    }

    public function test_filters_limit_summaries_submissions_and_exports_consistently(): void
    {
        $this->login();
        [$e,$s] = $this->fixtures();
        $service = app(ExpertReport::class);
        $filters = ['expert_id' => $e->id, 'survey_id' => $s->id, 'from' => '2026-11-02', 'to' => '2026-11-02', 'evaluator_role' => 'Chairman', 'type' => 'expert'];
        $summary = $service->summary($filters);
        $this->assertSame(1, $summary['total']);
        $this->assertEquals(4, collect($summary['rows'])->firstWhere('type', 'rating')['average']);
        $this->get('/modules/evaluation/reports/experts?'.http_build_query($filters))->assertOk()->assertSee('4.00')->assertSee('View Answers');
    }

    public function test_staff_and_members_cannot_read_expert_reports(): void
    {
        [$e,$s,$member,$chairman,$v] = $this->fixtures();
        foreach ([UserRole::Staff, UserRole::Member] as $role) {
            $this->login($role);
            $this->get('/modules/evaluation/reports/experts')->assertForbidden();
            $this->get('/modules/evaluation/reports/experts?export=summary')->assertForbidden();
            $this->get('/modules/evaluation/reports/experts?export=submissions')->assertForbidden();
            $this->get('/modules/evaluation/responses/'.$v->id)->assertForbidden();
            if ($role === UserRole::Staff) {
                $this->get('/modules/evaluation/experts/'.$e->id)->assertOk()->assertDontSee('Evaluation History & Summary', false)->assertDontSee('Private narrative');
                $this->get('/modules/evaluation/surveys/'.$s->id)->assertOk()->assertDontSee('href="'.route('evaluations.index', ['survey_id' => $s->id]).'"', false);
            }
        }
        $this->login();
        $this->get('/modules/evaluation/experts/'.$e->id)->assertOk()->assertSee('Evaluation History & Summary', false)->assertSee('3.00')->assertSee('View Answers');
        $this->get('/modules/evaluation/reports/experts')->assertOk()->assertSee('Export Summary CSV');
    }

    public function test_csv_exports_include_filtered_answers_and_escape_spreadsheet_formulas(): void
    {
        $this->login();
        [$e,$s] = $this->fixtures();
        $r = $this->get('/modules/evaluation/reports/experts?export=submissions&from=2026-11-02&to=2026-11-02')->assertOk();
        $content = $r->streamedContent();
        $this->assertStringContainsString('Private narrative', $content);
        $this->assertStringContainsString('Secret Hints', $content);
        $this->assertStringContainsString("'=CMD", $content);
        $this->assertStringNotContainsString('2026-11-01,', $content);
        $this->assertStringNotContainsString('2026-11-03,', $content);
        $summary = $this->get('/modules/evaluation/reports/experts?export=summary')->assertOk()->streamedContent();
        $this->assertStringContainsString('Average', $summary);
        $this->assertStringContainsString('Professional Knowledge', $summary);
        $this->assertDatabaseHas('audit_logs', ['action' => 'evaluation.report.export']);
    }

    public function test_reports_preserve_data_for_soft_deleted_experts_and_surveys(): void
    {
        $this->login();
        [$e,$s] = $this->fixtures();
        $e->delete();
        $s->delete();
        $this->get('/modules/evaluation/reports/experts?expert_id='.$e->id)->assertOk()->assertSee('Reported Expert')->assertSee('Rated Survey');
        $this->assertSame(3, app(ExpertReport::class)->summary(['expert_id' => $e->id])['total']);
    }

    public function test_blank_and_to_only_reports_render_and_invalid_filters_fail(): void
    {
        $this->login();
        $this->get('/modules/evaluation/reports/experts')->assertOk()->assertSee('No matching evaluation data.');
        $this->get('/modules/evaluation/reports/experts?to=2026-11-09')->assertOk();
        $this->get('/modules/evaluation/reports/experts?from=2026-11-09&to=2026-01-01')->assertSessionHasErrors('to');
    }

    public function test_optional_answers_are_excluded_from_means_and_multi_selection_counts_are_correct(): void
    {
        [$e,$s,$m,$c,$a] = $this->fixtures();
        $answers = $a->answers;
        $answers[0]['value'] = null;
        $answers[] = ['question_id' => 3, 'prompt' => 'Skills', 'type' => 'multiple', 'options' => ['A', 'B'], 'value' => ['A', 'B']];
        $a->update(['answers' => $answers]);
        $rows = collect(app(ExpertReport::class)->summary([])['rows']);
        $rating = $rows->first(fn ($r) => $r['type'] === 'rating' && $r['max'] === 5);
        $this->assertEquals(4, $rating['average']);
        $this->assertSame(1, $rating['skipped']);
        $this->assertSame(['A' => 1, 'B' => 1], $rows->firstWhere('type', 'multiple')['distribution']);
    }
}
