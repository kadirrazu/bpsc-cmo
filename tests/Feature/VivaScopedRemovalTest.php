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
use Tests\TestCase;

class VivaScopedRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    private function board(string $date, string $post, string $group, string $reg): VivaBoard
    {
        $board = VivaBoard::create(['vdate' => $date, 'post_code' => $post, 'post_name' => 'Post '.$post, 'board' => $group]);
        VivaCandidate::create(['vdate' => $date, 'post_code' => $post, 'viva_board_id' => $board->id, 'reg' => $reg, 'name' => 'Candidate '.$reg]);

        return $board;
    }

    private function preview(User $user, array $rows): VivaImport
    {
        return VivaImport::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'payload' => ['rows' => $rows], 'expires_at' => now()->addHour()]);
    }

    private function requestData(array $changes = []): array
    {
        return $changes + ['vdate' => '2026-11-10', 'post_code' => '001', 'confirmation' => 'DELETE'];
    }

    public function test_scoped_removal_spans_pagination_and_preserves_other_dates_posts_and_previews(): void
    {
        $admin = $this->login();
        $targets = collect();
        for ($i = 1; $i <= 21; $i++) {
            $targets->push($this->board('2026-11-10', '001', str_pad((string) $i, 2, '0', STR_PAD_LEFT), str_pad((string) $i, 8, '0', STR_PAD_LEFT)));
        }
        $otherPost = $this->board('2026-11-10', '1', '01', '00000201');
        $otherDate = $this->board('2026-11-11', '001', '01', '00000301');
        $otherBoth = $this->board('2026-11-11', '999', '01', '00000401');
        $member = User::factory()->create(['role' => 'member']);
        $otherMember = User::factory()->create(['role' => 'member']);
        $pool = VivaPool::create(['name' => 'Retain pool', 'is_active' => true]);
        $pool->users()->attach($member);
        foreach ([[$targets->first(), $member], [$otherPost, $otherMember]] as [$board,$user]) {
            VivaAssignment::create(['vdate' => $board->vdate, 'viva_board_id' => $board->id, 'user_id' => $user->id, 'viva_pool_id' => $pool->id, 'member_snapshot' => ['name' => $user->name], 'mode' => 'manual']);
        }
        $affected = $this->preview($admin, [['vdate' => '101126', 'post_code' => '001'], ['vdate' => '111126', 'post_code' => '999']]);
        $unrelated = $this->preview($admin, [['vdate' => '111126', 'post_code' => '001'], ['vdate' => '101126', 'post_code' => '1']]);
        $settings = VivaSetting::first()->getAttributes();
        $users = User::count();
        $this->get(route('viva.imports.index', ['vdate' => '2026-11-10', 'post_code' => '001', 'page' => 2]))->assertOk()->assertViewHas('scopeCounts', ['boards' => 21, 'candidates' => 21, 'assignments' => 1])->assertSee('Remove Selected Date & Post', false);
        $this->delete(route('viva.imports.destroy-scope'), $this->requestData())->assertSessionHasNoErrors()->assertRedirect(route('viva.imports.index', ['vdate' => '2026-11-10', 'post_code' => '001']));
        $this->assertSame(0, VivaBoard::where('vdate', '2026-11-10')->where('post_code', '001')->count());
        $this->assertDatabaseCount('viva_boards', 3);
        $this->assertDatabaseCount('viva_candidates', 3);
        $this->assertDatabaseCount('viva_assignments', 1);
        foreach ([$otherPost, $otherDate, $otherBoth] as $board) {
            $this->assertModelExists($board);
        }
        $this->assertSame('cancelled', $affected->fresh()->status);
        $this->assertSame([], $affected->fresh()->payload);
        $this->assertSame('pending', $unrelated->fresh()->status);
        $this->assertSame($unrelated->payload, $unrelated->fresh()->payload);
        $this->post(route('viva.imports.confirm', $affected->id), ['confirmation' => 1])->assertStatus(410);
        $this->assertDatabaseCount('users', $users);
        $this->assertDatabaseCount('viva_pools', 1);
        $this->assertDatabaseCount('viva_pool_user', 1);
        $this->assertSame($settings, VivaSetting::first()->getAttributes());
        $this->get(route('viva.imports.index', ['vdate' => '2026-11-10', 'post_code' => '001']))->assertOk()->assertSee('001 · No imported records')->assertViewHas('scopeCounts', ['boards' => 0, 'candidates' => 0, 'assignments' => 0]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'viva.candidates.scope-removed']);
    }

    public function test_missing_invalid_or_nonmatching_scope_never_deletes_any_data(): void
    {
        $this->login();
        $board = $this->board('2026-11-10', '001', '01', '00000123');
        foreach ([['vdate' => ''], ['vdate' => 'invalid'], ['post_code' => ''], ['post_code' => 'all'], ['confirmation' => 'yes'], ['confirmation' => 'delete'], ['confirmation' => 'DELETE '], ['confirmation' => ' DELETE']] as $changes) {
            $this->delete(route('viva.imports.destroy-scope'), $this->requestData($changes))->assertSessionHasErrors();
            $this->assertModelExists($board);
            $this->assertDatabaseCount('viva_candidates', 1);
        }
        $this->delete(route('viva.imports.destroy-scope'), $this->requestData(['post_code' => '999']))->assertSessionHasErrors('removal');
        $this->assertModelExists($board);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'viva.candidates.scope-removed']);
    }

    public function test_only_administrator_can_remove_a_selected_scope(): void
    {
        $board = $this->board('2026-11-10', '001', '01', '00000123');
        foreach (['chairman', 'member', 'staff'] as $role) {
            $this->login($role);
            $this->delete(route('viva.imports.destroy-scope'), $this->requestData())->assertForbidden();
            $this->assertModelExists($board);
        }
        auth()->forgetGuards();
        $this->delete(route('viva.imports.destroy-scope'), $this->requestData())->assertRedirect(route('login'));
        $this->assertModelExists($board);
    }

    public function test_zero_post_code_and_all_posts_filter_cannot_expand_scope(): void
    {
        $this->login();
        $zero = $this->board('2026-11-10', '0', '01', '00000123');
        $other = $this->board('2026-11-10', '001', '01', '00000456');
        $this->get(route('viva.imports.index', ['vdate' => '2026-11-10']))->assertOk()->assertViewHas('scopeCounts', null);
        $this->get(route('viva.imports.index', ['vdate' => '2026-11-10', 'post_code' => '0']))->assertOk()->assertViewHas('scopeCounts', ['boards' => 1, 'candidates' => 1, 'assignments' => 0])->assertViewHas('boards', fn ($boards) => $boards->total() === 1);
        $this->delete(route('viva.imports.destroy-scope'), $this->requestData(['post_code' => '0']))->assertSessionHasNoErrors();
        $this->assertModelMissing($zero);
        $this->assertModelExists($other);
    }

    public function test_scoped_removal_rolls_back_candidates_assignments_and_preview_changes_on_failure(): void
    {
        $admin = $this->login();
        $board = $this->board('2026-11-10', '001', '01', '00000123');
        VivaAssignment::create(['vdate' => $board->vdate, 'viva_board_id' => $board->id, 'user_id' => $admin->id, 'member_snapshot' => ['name' => $admin->name], 'mode' => 'manual']);
        $preview = $this->preview($admin, [['vdate' => '10112026', 'post_code' => '001']]);
        $this->withoutMiddleware(AuditActivity::class);
        $this->mock(Audit::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->delete(route('viva.imports.destroy-scope'), $this->requestData())->assertStatus(500);
        $this->assertModelExists($board);
        $this->assertDatabaseCount('viva_candidates', 1);
        $this->assertDatabaseCount('viva_assignments', 1);
        $this->assertSame('pending', $preview->fresh()->status);
        $this->assertSame($preview->payload, $preview->fresh()->payload);
    }
}
