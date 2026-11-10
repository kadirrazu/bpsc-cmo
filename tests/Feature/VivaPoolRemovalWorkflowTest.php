<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaCandidate;
use App\Models\VivaPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VivaPoolRemovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role = 'chairman'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    public function test_pool_cards_have_only_action_links_and_review_cancel_does_not_remove_pool(): void
    {
        $chair = $this->login();
        $pool = VivaPool::create(['name' => 'Pool A', 'description' => 'Review fixture', 'is_active' => true]);
        $pool->users()->attach($chair);
        $this->get(route('viva.pools.index'))->assertOk()->assertSee('Edit Pool')->assertSee('Use Pool')->assertSee('Delete')
            ->assertSee(route('viva.pools.removal', $pool))->assertDontSee('name="confirmation"', false)->assertDontSee('action="'.route('viva.pools.destroy', $pool).'"', false);
        $this->get(route('viva.pools.removal', $pool))->assertOk()->assertSee('Pool A')->assertSee('Review fixture')->assertSee('name="confirmation"', false)->assertSee('Confirm & Delete Pool', false)->assertViewHas('assignmentCount', 0);
        $this->get(route('viva.pools.index'))->assertOk();
        $this->assertNotSoftDeleted($pool);
        $this->assertSame(1, $pool->users()->count());
    }

    public function test_final_pool_delete_requires_exact_delete_and_preserves_assignments_candidates_and_users(): void
    {
        $chair = $this->login();
        $pool = VivaPool::create(['name' => 'Assigned pool', 'is_active' => true]);
        $pool->users()->attach($chair);
        $board = VivaBoard::create(['vdate' => '2026-11-10', 'post_code' => '001', 'post_name' => 'Post', 'board' => '01']);
        $candidate = VivaCandidate::create(['vdate' => '2026-11-10', 'post_code' => '001', 'viva_board_id' => $board->id, 'reg' => '00000123']);
        $assignment = VivaAssignment::create(['vdate' => '2026-11-10', 'viva_board_id' => $board->id, 'viva_pool_id' => $pool->id, 'user_id' => $chair->id, 'member_snapshot' => ['name' => $chair->name], 'mode' => 'manual']);
        $this->get(route('viva.pools.removal', $pool))->assertOk()->assertViewHas('assignmentCount', 1);
        foreach (['', 'yes', 'delete', 'DELETE '] as $confirmation) {
            $this->delete(route('viva.pools.destroy', $pool), ['confirmation' => $confirmation])->assertSessionHasErrors('confirmation');
            $this->assertNotSoftDeleted($pool);
        }
        $this->delete(route('viva.pools.destroy', $pool), ['confirmation' => 'DELETE'])->assertSessionHasNoErrors()->assertRedirect(route('viva.pools.index'));
        $this->assertSoftDeleted($pool);
        $this->assertModelExists($chair);
        $this->assertModelExists($board);
        $this->assertModelExists($candidate);
        $this->assertModelExists($assignment);
        $this->assertSame($pool->id, $assignment->fresh()->viva_pool_id);
        $this->assertDatabaseCount('viva_pool_user', 1);
        $this->get(route('viva.pools.removal', $pool))->assertNotFound();
    }

    public function test_pool_review_is_strictly_chairman_only(): void
    {
        $pool = VivaPool::create(['name' => 'Restricted pool', 'is_active' => true]);
        foreach (['admin', 'member', 'staff'] as $role) {
            $this->login($role);
            $this->get(route('viva.pools.removal', $pool))->assertForbidden();
        }
        auth()->forgetGuards();
        $this->get(route('viva.pools.removal', $pool))->assertRedirect(route('login'));
        $this->assertNotSoftDeleted($pool);
    }
}
