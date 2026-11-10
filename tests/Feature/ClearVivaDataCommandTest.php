<?php

namespace Tests\Feature;

use App\Console\Commands\ClearVivaData;
use App\Models\Expert;
use App\Models\User;
use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaCandidate;
use App\Models\VivaImport;
use App\Models\VivaPool;
use App\Models\VivaSetting;
use App\Services\Library\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClearVivaDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): void
    {
        $user = User::factory()->create(['role' => 'member']);
        $pool = VivaPool::create(['name' => 'Reset fixture', 'is_active' => true]);
        $pool->users()->attach($user);
        $removed = VivaPool::create(['name' => 'Previously removed', 'is_active' => false]);
        $removed->users()->attach($user);
        $removed->delete();
        $board = VivaBoard::create(['vdate' => '2026-11-10', 'post_code' => '001', 'post_name' => 'Post', 'board' => '01']);
        VivaCandidate::create(['viva_board_id' => $board->id, 'vdate' => '2026-11-10', 'post_code' => '001', 'reg' => '000001']);
        VivaAssignment::create(['vdate' => '2026-11-10', 'viva_board_id' => $board->id, 'user_id' => $user->id, 'viva_pool_id' => $pool->id, 'member_snapshot' => ['name' => $user->name], 'mode' => 'random']);
        DB::table('viva_days')->insert(['vdate' => '2026-11-10']);
        VivaImport::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'payload' => ['fixture' => true], 'expires_at' => now()->addHour()]);
        app(Audit::class)->record('fixture.retained');
    }

    public function test_invalid_scope_noninteractive_and_declined_confirmation_never_delete(): void
    {
        $this->fixture();
        $this->artisan('cmo:viva-clear unknown')->assertExitCode(2);
        $this->artisan('cmo:viva-clear all --no-interaction')->assertExitCode(1);
        $this->artisan('cmo:viva-clear all')->expectsQuestion(ClearVivaData::CONFIRMATION, 'no')->expectsOutput('Cancelled. No data was deleted.')->assertSuccessful();
        foreach (['viva_assignments', 'viva_candidates', 'viva_boards', 'viva_imports', 'viva_days'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('viva_pools', 2);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'viva.data.cleared']);
    }

    public function test_distribution_scope_only_clears_assignments(): void
    {
        $this->fixture();
        $this->artisan('cmo:viva-clear distributions')->expectsQuestion(ClearVivaData::CONFIRMATION, 'DELETE')->assertSuccessful();
        $this->assertDatabaseCount('viva_assignments', 0);
        foreach (['viva_candidates', 'viva_boards', 'viva_imports'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('viva_pool_user', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'viva.data.cleared', 'channel' => 'console']);
    }

    public function test_pool_scope_removes_soft_deleted_pools_and_memberships_but_keeps_assigned_boards(): void
    {
        $this->fixture();
        $this->artisan('cmo:viva-clear pools')->expectsQuestion(ClearVivaData::CONFIRMATION, 'DELETE')->assertSuccessful();
        $this->assertDatabaseCount('viva_pools', 0);
        $this->assertDatabaseCount('viva_pool_user', 0);
        $this->assertDatabaseCount('viva_assignments', 1);
        $this->assertNull(VivaAssignment::firstOrFail()->viva_pool_id);
        $this->assertDatabaseCount('viva_candidates', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_candidate_scope_removes_dependencies_and_staging_but_retains_pools_and_settings(): void
    {
        $this->fixture();
        $settings = VivaSetting::firstOrFail()->getAttributes();
        $this->artisan('cmo:viva-clear candidates')->expectsQuestion(ClearVivaData::CONFIRMATION, 'DELETE')->assertSuccessful();
        foreach (['viva_assignments', 'viva_candidates', 'viva_boards', 'viva_imports', 'viva_days'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('viva_pools', 2);
        $this->assertDatabaseCount('viva_pool_user', 2);
        $this->assertSame($settings, VivaSetting::firstOrFail()->getAttributes());
    }

    public function test_all_scope_is_repeatable_and_keeps_users_other_module_data_and_audit_history(): void
    {
        $this->fixture();
        $expert = Expert::create(['name' => 'Retained expert', 'type' => 'expert']);
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('cmo:viva-clear all')->expectsQuestion(ClearVivaData::CONFIRMATION, 'DELETE')->assertSuccessful();
        }
        foreach (['viva_assignments', 'viva_pool_user', 'viva_pools', 'viva_candidates', 'viva_boards', 'viva_imports', 'viva_days'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('viva_settings', 1);
        $this->assertModelExists($expert);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fixture.retained']);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'viva.data.cleared')->count());
    }

    public function test_failure_rolls_back_every_delete(): void
    {
        $this->fixture();
        $this->mock(Audit::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            $this->artisan('cmo:viva-clear all')->expectsQuestion(ClearVivaData::CONFIRMATION, 'DELETE')->run();
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('viva_assignments', 1);
        $this->assertDatabaseCount('viva_candidates', 1);
        $this->assertDatabaseCount('viva_boards', 1);
        $this->assertDatabaseCount('viva_pools', 2);
        $this->assertDatabaseCount('viva_pool_user', 2);
        $this->assertDatabaseCount('viva_imports', 1);
    }

    public static function menuScopes(): array
    {
        return [
            'pools' => ['Member pools only', 'viva_pools', 'viva_candidates'],
            'distributions' => ['Board distributions only', 'viva_assignments', 'viva_candidates'],
            'candidates' => ['Candidate imports and their board distributions', 'viva_candidates', 'viva_pools'],
            'all' => ['All Viva operational data', 'viva_pools', 'users'],
        ];
    }

    #[DataProvider('menuScopes')]
    public function test_bare_command_menu_selects_the_scope_then_requires_confirmation(string $choice, string $cleared, string $retained): void
    {
        $this->fixture();
        $before = DB::table($retained)->count();
        $this->artisan('cmo:viva-clear')
            ->expectsQuestion(ClearVivaData::MENU_QUESTION, $choice)
            ->expectsQuestion(ClearVivaData::CONFIRMATION, 'DELETE')
            ->assertSuccessful();
        $this->assertDatabaseCount($cleared, 0);
        $this->assertDatabaseCount($retained, $before);
        $this->assertDatabaseHas('audit_logs', ['action' => 'viva.data.cleared']);
    }

    public function test_menu_cancel_and_declining_final_confirmation_preserve_data(): void
    {
        $this->fixture();
        $this->artisan('cmo:viva-clear')
            ->expectsQuestion(ClearVivaData::MENU_QUESTION, 'Cancel (no changes)')
            ->expectsOutput('Cancelled. No data was deleted.')
            ->assertSuccessful();
        $this->artisan('cmo:viva-clear')
            ->expectsQuestion(ClearVivaData::MENU_QUESTION, 'All Viva operational data')
            ->expectsQuestion(ClearVivaData::CONFIRMATION, 'no')
            ->assertSuccessful();
        $this->assertDatabaseCount('viva_assignments', 1);
        $this->assertDatabaseCount('viva_pools', 2);
        $this->assertDatabaseCount('viva_candidates', 1);
        $this->assertDatabaseCount('viva_imports', 1);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'viva.data.cleared']);
    }

    public function test_bare_command_refuses_noninteractive_execution_instead_of_choosing_a_scope(): void
    {
        $this->fixture();
        $this->artisan('cmo:viva-clear --no-interaction')->assertExitCode(1);
        $this->assertDatabaseCount('viva_assignments', 1);
        $this->assertDatabaseCount('viva_candidates', 1);
    }

    public function test_cleanup_requires_exact_uppercase_delete_not_yes_or_whitespace(): void
    {
        $this->fixture();
        foreach (['yes', 'delete', 'DELETE ', ' DELETE', ''] as $confirmation) {
            $this->artisan('cmo:viva-clear all')->expectsQuestion(ClearVivaData::CONFIRMATION, $confirmation)->assertSuccessful();
            $this->assertDatabaseCount('viva_assignments', 1);
            $this->assertDatabaseCount('viva_candidates', 1);
            $this->assertDatabaseCount('viva_pools', 2);
        }
    }
}
