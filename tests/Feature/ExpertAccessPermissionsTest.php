<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Evaluation;
use App\Models\Expert;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpertAccessPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function login(UserRole $role): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);

        return $u;
    }

    private function fixture(): array
    {
        $e = Expert::create(['name' => 'Test Profile Expert', 'type' => 'departmental', 'is_active' => true]);
        $s = Survey::create(['title' => 'Profile Survey', 'status' => 'active', 'allowed_roles' => ['member']]);
        $m = User::factory()->create(['role' => UserRole::Member]);
        $v = Evaluation::create(['survey_id' => $s->id, 'survey_snapshot' => ['title' => $s->title], 'expert_id' => $e->id, 'user_id' => $m->id, 'viva_date' => now()->toDateString(), 'expert_snapshot' => ['name' => $e->name, 'type' => $e->type, 'designation' => null, 'organization' => null], 'evaluator_snapshot' => ['name' => $m->name, 'role' => 'Member'], 'answers' => [['question_id' => 1, 'prompt' => 'Profile Rating', 'type' => 'rating', 'value' => 4]], 'submitted_at' => now()]);

        return [$e, $v];
    }

    public function test_profile_section_visibility_and_removal_card_position_match_each_role(): void
    {
        [$e] = $this->fixture();
        foreach (UserRole::cases() as $role) {
            $this->login($role);
            $r = $this->get('/modules/evaluation/experts/'.$e->id);
            if ($role === UserRole::Member) {
                $r->assertForbidden();

                continue;
            }$r->assertOk();
            if (in_array($role, [UserRole::Admin, UserRole::Chairman], true)) {
                $r->assertSee('Evaluation History & Summary', false)->assertSee('Profile Rating')->assertSee('Open Report');
            } else {
                $r->assertDontSee('Evaluation History & Summary', false)->assertDontSee('Profile Rating')->assertDontSee('Open Report');
            }if ($role === UserRole::Admin) {
                $r->assertSeeInOrder(['Evaluation History & Summary', 'Submissions', 'Remove from pool'], false)->assertSee('Delete Expert');
            } else {
                $r->assertDontSee('Remove from pool')->assertDontSee('Delete Expert');
            }if ($role === UserRole::Chairman) {
                $r->assertDontSee('Edit Expert');
            }
        }
    }

    public function test_only_administrator_can_remove_an_expert_even_with_a_direct_delete_request(): void
    {
        [$e] = $this->fixture();
        foreach ([UserRole::Member, UserRole::Staff, UserRole::Chairman] as $role) {
            $this->login($role);
            $this->delete('/modules/evaluation/experts/'.$e->id, ['confirmation' => 'DELETE'])->assertForbidden();
            $this->assertNotSoftDeleted($e);
        }$this->login(UserRole::Admin);
        $this->delete('/modules/evaluation/experts/'.$e->id, ['confirmation' => 'DELETE'])->assertRedirect('/modules/evaluation/experts');
        $this->assertSoftDeleted($e);
        $this->assertDatabaseCount('evaluations', 1);
    }

    public function test_chairman_can_read_filtered_reports_exports_and_other_evaluators_answer_details(): void
    {
        [$e,$v] = $this->fixture();
        $this->login(UserRole::Chairman);
        $url = '/modules/evaluation/reports/experts?expert_id='.$e->id;
        $this->get($url)->assertOk()->assertSee('Profile Rating')->assertSee('4.00');
        $this->assertStringContainsString('Profile Rating', $this->get($url.'&export=summary')->assertOk()->streamedContent());
        $this->assertStringContainsString('Profile Rating', $this->get($url.'&export=submissions')->assertOk()->streamedContent());
        $this->get('/modules/evaluation/responses/'.$v->id)->assertOk()->assertSee('Profile Rating');
        $this->get('/modules/evaluation/experts')->assertOk()->assertDontSee('Add Expert')->assertDontSee('href="'.route('experts.edit', $e).'"', false);
    }

    public function test_report_pages_and_exports_reject_member_and_staff_direct_urls(): void
    {
        [$e] = $this->fixture();
        foreach ([UserRole::Staff, UserRole::Member] as $role) {
            $this->login($role);
            foreach (['', '&export=summary', '&export=submissions'] as $suffix) {
                $this->get('/modules/evaluation/reports/experts?expert_id='.$e->id.$suffix)->assertForbidden();
            }
        }
    }

    public function test_chairman_report_access_does_not_grant_system_or_expert_write_permissions(): void
    {
        [$e] = $this->fixture();
        $this->login(UserRole::Chairman);
        foreach (['/users', '/roles', '/units', '/designations', '/audit-logs', '/modules/evaluation/experts/create', '/modules/evaluation/experts/'.$e->id.'/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }$this->post('/modules/evaluation/experts', [])->assertForbidden();
        $this->put('/modules/evaluation/experts/'.$e->id, [])->assertForbidden();
        $this->get('/modules/evaluation')->assertOk()->assertSee('href="'.route('evaluation.reports').'"', false)->assertSee('href="'.route('experts.index').'"', false);
    }
}
