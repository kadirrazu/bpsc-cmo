<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaCandidate;
use App\Models\VivaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VivaDownloadSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    public function test_downloads_default_off_and_only_administrator_can_change_setting_without_display_credentials(): void
    {
        $this->assertFalse(VivaSetting::findOrFail(1)->candidate_downloads_enabled);
        foreach (['chairman', 'member', 'staff'] as $role) {
            $this->login($role);
            $this->put(route('viva.settings.downloads'), ['candidate_downloads_enabled' => 1])->assertForbidden();
            $this->get(route('viva.settings.edit'))->assertForbidden();
            $this->assertFalse(VivaSetting::findOrFail(1)->candidate_downloads_enabled);
        }
        $this->login('admin');
        $before = VivaSetting::findOrFail(1)->only(['start_time', 'display_username', 'display_password', 'auth_version']);
        $this->get(route('viva.settings.edit'))->assertOk()->assertSee('Enable candidate PDF downloads');
        $this->put(route('viva.settings.downloads'), ['candidate_downloads_enabled' => 1, 'start_time' => '01:00', 'auth_version' => 999])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(VivaSetting::findOrFail(1)->candidate_downloads_enabled);
        $this->assertSame($before, VivaSetting::findOrFail(1)->only(array_keys($before)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'viva.candidate-downloads.updated']);
        $this->put(route('viva.settings.downloads'), ['candidate_downloads_enabled' => 'invalid'])->assertSessionHasErrors('candidate_downloads_enabled');
        $this->assertTrue(VivaSetting::findOrFail(1)->candidate_downloads_enabled);
        $this->put(route('viva.settings.downloads'), ['candidate_downloads_enabled' => 0])->assertSessionHasNoErrors();
        $this->assertFalse(VivaSetting::findOrFail(1)->candidate_downloads_enabled);
    }

    public function test_administrator_candidate_switch_is_independent_from_member_own_board_pdf(): void
    {
        $member = $this->login('member');
        $board = VivaBoard::create(['vdate' => '2026-11-10', 'post_code' => '001', 'post_name' => 'Post', 'board' => '01']);
        VivaCandidate::create(['viva_board_id' => $board->id, 'vdate' => '2026-11-10', 'post_code' => '001', 'reg' => '00000123']);
        VivaAssignment::create(['vdate' => '2026-11-10', 'viva_board_id' => $board->id, 'user_id' => $member->id, 'member_snapshot' => ['name' => $member->name], 'mode' => 'manual']);
        $params = ['vdate' => '2026-11-10', 'format' => 'pdf'];
        $this->get(route('viva.my-board.index', $params))->assertOk()->assertSee('Download Candidate List (PDF)')->assertDontSee('Print Candidate List')->assertDontSee('Excel (.xlsx)');
        foreach (['xlsx', 'print'] as $format) {
            $this->get(route('viva.my-board.download', ['vdate' => '2026-11-10', 'format' => $format]))->assertSessionHasErrors('format');
        }
        $this->login('admin');
        $this->get(route('viva.imports.index', $params))->assertOk()->assertDontSee('Download Board Lists (PDF)')->assertDontSee('Print Board Lists');
        foreach (['pdf', 'print', 'xlsx'] as $format) {
            $this->get(route('viva.imports.report', ['vdate' => '2026-11-10', 'format' => $format]))->assertForbidden();
        }
        $this->put(route('viva.settings.downloads'), ['candidate_downloads_enabled' => 1])->assertSessionHasNoErrors();
        $this->get(route('viva.imports.index', $params))->assertOk()->assertSee('Download Board Lists (PDF)')->assertDontSee('Print Board Lists');
        $this->get(route('viva.imports.report', $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        foreach (['print', 'xlsx'] as $format) {
            $this->get(route('viva.imports.report', ['vdate' => '2026-11-10', 'format' => $format]))->assertSessionHasErrors('format');
        }
        $this->actingAs($member)->withSession(['auth_version' => $member->auth_version]);
        $this->get(route('viva.my-board.index', $params))->assertOk()->assertSee('Download Candidate List (PDF)')->assertDontSee('Print Candidate List')->assertDontSee('Excel (.xlsx)');
        $this->get(route('viva.my-board.download', $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        foreach (['xlsx', 'print'] as $format) {
            $this->get(route('viva.my-board.download', ['vdate' => '2026-11-10', 'format' => $format]))->assertSessionHasErrors('format');
        }
        VivaSetting::findOrFail(1)->update(['candidate_downloads_enabled' => false]);
        $this->get(route('viva.my-board.download', $params))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->login('admin');
        $this->get(route('viva.imports.report', $params))->assertForbidden();
        $this->assertDatabaseCount('viva_candidates', 1);
        $this->assertDatabaseCount('viva_assignments', 1);
    }
}
