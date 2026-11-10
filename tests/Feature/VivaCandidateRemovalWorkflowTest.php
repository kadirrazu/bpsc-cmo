<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaCandidate;
use App\Models\VivaImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VivaCandidateRemovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    private function board(string $post = '001', string $group = '01'): VivaBoard
    {
        $board = VivaBoard::create(['vdate' => '2026-11-10', 'post_code' => $post, 'post_name' => 'Post '.$post, 'board' => $group]);
        VivaCandidate::create(['viva_board_id' => $board->id, 'vdate' => '2026-11-10', 'post_code' => $post, 'reg' => '00000123']);

        return $board;
    }

    public function test_listing_contains_review_links_without_confirmation_inputs_and_single_board_review_is_read_only(): void
    {
        $this->login();
        $board = $this->board();
        $this->get(route('viva.imports.index', ['vdate' => '2026-11-10', 'post_code' => '001']))->assertOk()
            ->assertDontSee('name="confirmation"', false)->assertDontSee('Type <strong>DELETE</strong>', false)
            ->assertSee(route('viva.imports.removal', ['mode' => 'board', 'board_id' => $board->id]))
            ->assertSee(route('viva.imports.removal', ['mode' => 'scope', 'vdate' => '2026-11-10', 'post_code' => '001']));
        $this->get(route('viva.imports.removal', ['mode' => 'board', 'board_id' => $board->id]))->assertOk()
            ->assertSee('Single unassigned board')->assertSee('name="confirmation"', false)->assertSee('Confirm & Delete', false)
            ->assertViewHas('action', route('viva.imports.destroy', $board));
        $this->assertDatabaseCount('viva_boards', 1);
        $this->assertDatabaseCount('viva_candidates', 1);
        $this->get(route('viva.imports.index', ['vdate' => '2026-11-10', 'post_code' => '001']))->assertOk();
        $this->assertModelExists($board);
        $this->delete(route('viva.imports.destroy', $board), ['confirmation' => 'yes'])->assertSessionHasErrors('confirmation');
        $this->assertModelExists($board);
        $this->delete(route('viva.imports.destroy', $board), ['confirmation' => 'DELETE'])->assertRedirect(route('viva.imports.index', ['vdate' => '2026-11-10', 'post_code' => '001']));
        $this->assertModelMissing($board);
    }

    public function test_scope_and_all_confirmation_counts_cannot_confuse_listing_filters_with_all_data(): void
    {
        $admin = $this->login();
        $this->board('001');
        $this->board('002');
        $preview = VivaImport::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'payload' => ['rows' => []], 'expires_at' => now()->addHour()]);
        $this->get(route('viva.imports.removal', ['mode' => 'scope', 'vdate' => '2026-11-10', 'post_code' => '001']))->assertOk()
            ->assertViewHas('counts', ['boards' => 1, 'candidates' => 1, 'assignments' => 0])
            ->assertViewHas('action', route('viva.imports.destroy-scope'))->assertSee('name="vdate" value="2026-11-10"', false)->assertSee('name="post_code" value="001"', false);
        $this->get(route('viva.imports.removal', ['mode' => 'all', 'vdate' => '2026-11-10', 'post_code' => '001']))->assertOk()
            ->assertSee('All dates and all post codes')->assertViewHas('counts', ['boards' => 2, 'candidates' => 2, 'assignments' => 0, 'imports' => 1])
            ->assertViewHas('action', route('viva.imports.destroy-all'))->assertDontSee('name="post_code"', false);
        $this->assertDatabaseCount('viva_boards', 2);
        $this->assertDatabaseCount('viva_candidates', 2);
        $this->assertModelExists($preview);
    }

    public function test_assigned_state_is_rechecked_after_confirmation_screen(): void
    {
        $admin = $this->login();
        $board = $this->board();
        $this->get(route('viva.imports.removal', ['mode' => 'board', 'board_id' => $board->id]))->assertOk();
        VivaAssignment::create(['vdate' => '2026-11-10', 'viva_board_id' => $board->id, 'user_id' => $admin->id, 'member_snapshot' => ['name' => $admin->name], 'mode' => 'manual']);
        $this->delete(route('viva.imports.destroy', $board), ['confirmation' => 'DELETE'])->assertSessionHasErrors('board');
        $this->get(route('viva.imports.removal', ['mode' => 'board', 'board_id' => $board->id]))->assertStatus(409);
        $this->assertModelExists($board);
        $this->assertDatabaseCount('viva_candidates', 1);
        $this->assertDatabaseCount('viva_assignments', 1);
    }

    public function test_confirmation_screens_are_admin_only_and_validate_the_requested_scope(): void
    {
        $board = $this->board();
        foreach (['chairman', 'member', 'staff'] as $role) {
            $this->login($role);
            foreach (['board', 'scope', 'all'] as $mode) {
                $this->get(route('viva.imports.removal', ['mode' => $mode, 'board_id' => $board->id, 'vdate' => '2026-11-10', 'post_code' => '001']))->assertForbidden();
            }
        }
        $this->login();
        foreach ([['mode' => 'unknown'], ['mode' => 'board'], ['mode' => 'scope', 'vdate' => '2026-11-10'], ['mode' => 'scope', 'post_code' => '001'], ['mode' => 'scope', 'vdate' => 'invalid', 'post_code' => '001']] as $params) {
            $this->get(route('viva.imports.removal', $params))->assertSessionHasErrors();
        }
        $this->get(route('viva.imports.removal', ['mode' => 'board', 'board_id' => 999]))->assertNotFound();
        $this->get(route('viva.imports.removal', ['mode' => 'scope', 'vdate' => '2026-11-10', 'post_code' => '999']))->assertOk()->assertDontSee('name="confirmation"', false);
        $this->assertModelExists($board);
    }

    public function test_all_confirmation_can_remove_only_staged_imports_even_when_no_boards_exist(): void
    {
        $admin = $this->login();
        $preview = VivaImport::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'payload' => [], 'expires_at' => now()->addHour()]);
        $this->get(route('viva.imports.removal', ['mode' => 'all']))->assertOk()->assertViewHas('canRemove', true)->assertSee('name="confirmation"', false);
        $this->assertModelExists($preview);
        $this->delete(route('viva.imports.destroy-all'), ['confirmation' => 'DELETE'])->assertSessionHasNoErrors();
        $this->assertModelMissing($preview);
    }
}
