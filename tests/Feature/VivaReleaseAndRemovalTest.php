<?php

namespace Tests\Feature;

use App\Http\Middleware\AuditActivity;
use App\Models\User;
use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaCandidate;
use App\Models\VivaImport;
use App\Models\VivaPool;
use App\Models\VivaSetting;
use App\Services\Library\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class VivaReleaseAndRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    private function board(string $group, string $reg, string $date = '2026-11-10'): VivaBoard
    {
        $board = VivaBoard::create(['vdate' => $date, 'post_code' => '001', 'post_name' => 'Post', 'unit' => 'Unit 07', 'ministry' => 'Education', 'board' => $group]);
        VivaCandidate::create(['viva_board_id' => $board->id, 'vdate' => $date, 'post_code' => '001', 'reg' => $reg, 'name' => 'Candidate '.$reg]);

        return $board;
    }

    private function assign(VivaBoard $board, User $member): void
    {
        VivaAssignment::create(['vdate' => $board->vdate, 'viva_board_id' => $board->id, 'user_id' => $member->id, 'member_snapshot' => ['name' => $member->name, 'room_number' => $member->room_number], 'mode' => 'random']);
    }

    public function test_settings_are_grouped_admin_only_and_forms_do_not_overwrite_each_other(): void
    {
        $this->assertFalse(VivaSetting::firstOrFail()->distribution_downloads_enabled);
        foreach (['chairman', 'member', 'staff'] as $role) {
            $this->login($role);
            $this->get(route('viva.settings.edit'))->assertForbidden();
            foreach (['settings.update' => ['display_username' => 'hack'], 'settings.timing' => ['start_time' => '01:00'], 'settings.downloads' => ['candidate_downloads_enabled' => 1], 'settings.distribution-downloads' => ['distribution_downloads_enabled' => 1]] as $route => $data) {
                $this->put(route('viva.'.$route), $data)->assertForbidden();
            }
        }
        $this->login('admin');
        $this->get(route('viva.settings.edit'))->assertOk()->assertSeeInOrder(['Distribution Timing', 'Shared Display Access', 'Download Controls', 'Administrator Candidate Lists', 'Chairman Distribution Reports']);
        $this->put(route('viva.settings.timing'), ['start_time' => '10:15', 'distribution_downloads_enabled' => 1])->assertSessionHasNoErrors();
        $this->assertFalse(VivaSetting::first()->distribution_downloads_enabled);
        $this->put(route('viva.settings.update'), ['display_username' => 'hall', 'display_password' => 'Hall@Password123', 'display_password_confirmation' => 'Hall@Password123'])->assertSessionHasNoErrors();
        $this->assertSame('10:15', VivaSetting::first()->start_time);
        $version = VivaSetting::first()->auth_version;
        $this->put(route('viva.settings.distribution-downloads'), ['distribution_downloads_enabled' => 1, 'start_time' => '01:00'])->assertSessionHasNoErrors();
        $this->assertTrue(VivaSetting::first()->distribution_downloads_enabled);
        $this->assertSame('10:15', VivaSetting::first()->start_time);
        $this->assertSame($version, VivaSetting::first()->auth_version);
        $this->put(route('viva.settings.distribution-downloads'), ['distribution_downloads_enabled' => 'invalid'])->assertSessionHasErrors('distribution_downloads_enabled');
        $this->assertDatabaseHas('audit_logs', ['action' => 'viva.distribution-downloads.updated']);
    }

    public function test_chairman_reports_require_release_and_include_only_assigned_boards_in_pdf_and_excel(): void
    {
        $this->login('chairman');
        $first = User::factory()->create(['role' => 'member', 'name' => 'First Member', 'room_number' => '501']);
        $second = User::factory()->create(['role' => 'member', 'name' => 'Second Member', 'room_number' => '802']);
        $a = $this->board('01', '00000002');
        $b = $this->board('02', '00000010');
        $unassigned = $this->board('03', '99999999');
        $this->assign($a, $first);
        $this->assign($b, $second);
        $params = ['vdate' => '2026-11-10', 'format' => 'xlsx'];
        $this->get(route('viva.distribution-reports', $params))->assertForbidden();
        $this->get(route('viva.report', ['vdate' => '2026-11-10']))->assertForbidden();
        $this->get(route('viva.boards.index', ['vdate' => '2026-11-10']))->assertOk()->assertDontSee('Distribution PDF')->assertDontSee('Candidate PDF downloads are disabled')->assertDontSee('Manage download setting');
        VivaSetting::first()->update(['distribution_downloads_enabled' => true]);
        $this->get(route('viva.boards.index', ['vdate' => '2026-11-10']))->assertOk()->assertSee('Distribution PDF')->assertSee('Distribution Excel');
        $response = $this->get(route('viva.distribution-reports', $params))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        $this->assertSame(3, $book->getSheetByName('Candidates')->getHighestRow());
        $this->assertSame('00000002', $book->getSheetByName('Candidates')->getCell('H2')->getValue());
        $this->assertSame('00000010', $book->getSheetByName('Candidates')->getCell('H3')->getValue());
        $this->assertSame('First Member', $book->getSheetByName('Assignments')->getCell('G2')->getValue());
        $book->disconnectWorksheets();
        unlink($path);
        $pdf = $this->get(route('viva.distribution-reports', ['vdate' => '2026-11-10', 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(2, preg_match_all('~/Type\s*/Page\b~', $pdf->getContent()));
        $single = $this->get(route('viva.distribution-reports', $params + ['board_id' => $b->id]))->assertOk();
        $path = $single->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        $this->assertSame(2, $book->getSheetByName('Candidates')->getHighestRow());
        $this->assertSame('00000010', $book->getSheetByName('Candidates')->getCell('H2')->getValue());
        $book->disconnectWorksheets();
        unlink($path);
        $this->get(route('viva.distribution-reports', $params + ['board_id' => $unassigned->id]))->assertNotFound();
        $this->get(route('viva.distribution-reports', ['vdate' => '2026-11-11', 'board_id' => $a->id, 'format' => 'pdf']))->assertNotFound();
        VivaSetting::first()->update(['distribution_downloads_enabled' => false]);
        $this->get(route('viva.distribution-reports', $params))->assertForbidden();
        VivaSetting::first()->update(['distribution_downloads_enabled' => true, 'candidate_downloads_enabled' => true]);
        foreach (['admin', 'member', 'staff'] as $role) {
            $this->login($role);
            $this->get(route('viva.distribution-reports', $params))->assertForbidden();
        }
    }

    public function test_candidate_feature_is_hidden_and_administrator_only_even_when_enabled(): void
    {
        $this->login('admin');
        $this->get(route('viva.imports.index'))->assertOk()->assertDontSee('Candidate PDF downloads are disabled')->assertDontSee('Manage download setting')->assertDontSee('Download Board Lists (PDF)');
        $member = $this->login('member');
        $board = $this->board('01', '00000123');
        $this->assign($board, $member);
        VivaSetting::first()->update(['candidate_downloads_enabled' => true]);
        $this->get(route('viva.my-board.index', ['vdate' => '2026-11-10']))->assertOk()->assertSee('Download Candidate List (PDF)')->assertDontSee('downloads are currently disabled');
        $this->get(route('viva.my-board.download', ['vdate' => '2026-11-10', 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('viva.imports.report', ['vdate' => '2026-11-10', 'format' => 'pdf']))->assertForbidden();
    }

    public function test_bulk_remove_requires_exact_delete_and_preserves_pools_users_settings(): void
    {
        $admin = $this->login('admin');
        $a = $this->board('01', '00000123');
        $this->board('02', '00000456', '2026-11-11');
        $member = User::factory()->create(['role' => 'member']);
        $this->assign($a, $member);
        $pool = VivaPool::create(['name' => 'Keep pool', 'is_active' => true]);
        $pool->users()->attach($member);
        VivaImport::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'payload' => [], 'expires_at' => now()->addHour()]);
        foreach (['', 'REMOVE', 'yes', 'delete', 'DELETE ', ' DELETE'] as $confirmation) {
            $this->delete(route('viva.imports.destroy-all'), ['confirmation' => $confirmation])->assertSessionHasErrors('confirmation');
            $this->assertDatabaseCount('viva_boards', 2);
            $this->assertDatabaseCount('viva_candidates', 2);
            $this->assertDatabaseCount('viva_assignments', 1);
        }
        foreach (['chairman', 'member', 'staff'] as $role) {
            $this->login($role);
            $this->delete(route('viva.imports.destroy-all'), ['confirmation' => 'DELETE'])->assertForbidden();
        }
        $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version]);
        $settings = VivaSetting::first()->getAttributes();
        $users = User::count();
        $this->delete(route('viva.imports.destroy-all'), ['confirmation' => 'DELETE'])->assertSessionHasNoErrors()->assertRedirect(route('viva.imports.index'));
        foreach (['viva_boards', 'viva_candidates', 'viva_assignments', 'viva_imports'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('viva_pools', 1);
        $this->assertDatabaseCount('viva_pool_user', 1);
        $this->assertDatabaseCount('users', $users);
        $this->assertSame($settings, VivaSetting::first()->getAttributes());
        $this->assertDatabaseHas('audit_logs', ['action' => 'viva.candidates.all-removed']);
    }

    public function test_single_removal_and_member_pool_changes_require_typed_delete(): void
    {
        $this->login('admin');
        $board = $this->board('01', '00000123');
        foreach (['', 'REMOVE', 'delete', 'DELETE '] as $confirmation) {
            $this->delete(route('viva.imports.destroy', $board), ['confirmation' => $confirmation])->assertSessionHasErrors('confirmation');
            $this->assertModelExists($board);
        }
        $this->delete(route('viva.imports.destroy', $board), ['confirmation' => 'DELETE'])->assertSessionHasNoErrors();
        $this->assertModelMissing($board);
        $chair = $this->login('chairman');
        $member = User::factory()->create(['role' => 'member']);
        $pool = VivaPool::create(['name' => 'Pool', 'is_active' => true]);
        $pool->users()->attach([$chair->id, $member->id]);
        $data = ['name' => 'Pool', 'is_active' => 1, 'selection' => 'selected', 'member_ids' => [$member->id]];
        $this->put(route('viva.pools.update', $pool), $data)->assertSessionHasErrors('confirmation');
        $this->assertSame(2, $pool->users()->count());
        $this->put(route('viva.pools.update', $pool), $data + ['confirmation' => 'DELETE'])->assertSessionHasNoErrors();
        $this->assertSame(1, $pool->users()->count());
        $this->delete(route('viva.pools.destroy', $pool), ['confirmation' => 'yes'])->assertSessionHasErrors('confirmation');
        $this->delete(route('viva.pools.destroy', $pool), ['confirmation' => 'DELETE'])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($pool);
    }

    public function test_bulk_remove_rolls_back_on_audit_failure(): void
    {
        $this->login('admin');
        $this->board('01', '00000123');
        $this->withoutMiddleware(AuditActivity::class);
        $this->mock(Audit::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->delete(route('viva.imports.destroy-all'), ['confirmation' => 'DELETE'])->assertStatus(500);
        $this->assertDatabaseCount('viva_boards', 1);
        $this->assertDatabaseCount('viva_candidates', 1);
    }

    public function test_cancelling_a_staged_import_requires_exact_delete(): void
    {
        $admin = $this->login('admin');
        $batch = VivaImport::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'payload' => [], 'expires_at' => now()->addHour()]);
        foreach (['', 'yes', 'DELETE '] as $confirmation) {
            $this->delete(route('viva.imports.cancel', $batch->id), ['confirmation' => $confirmation])->assertSessionHasErrors('confirmation');
            $this->assertModelExists($batch);
        }
        $this->delete(route('viva.imports.cancel', $batch->id), ['confirmation' => 'DELETE'])->assertSessionHasNoErrors();
        $this->assertModelMissing($batch);
    }
}
