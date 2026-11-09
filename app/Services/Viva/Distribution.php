<?php

namespace App\Services\Viva;

use App\Models\User;
use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaPool;
use App\Services\Library\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Random\Randomizer;

class Distribution
{
    public function assign(array $data, User $actor): void
    {
        DB::transaction(function () use ($data, $actor) {
            $workspace = app(Workspace::class);
            $workspace->lockDay($data['vdate']);
            // Recheck both time and privileges when saving, not only when rendering the form.
            abort_unless($actor->fresh()?->can('distribute-viva'), 403);
            $workspace->ensureOpen($data['vdate']);
            $pool = VivaPool::whereKey($data['pool_id'])->where('is_active', true)->lockForUpdate()->first();
            if (! $pool) {
                $this->fail('This member pool is no longer active or available. Refresh and choose another pool.');
            }
            $ids = array_map('intval', $data['board_ids']);
            sort($ids);
            $boards = VivaBoard::whereIn('id', $ids)->where('vdate', $data['vdate'])->where('post_code', $data['post_code'])->orderBy('id')->lockForUpdate()->get();
            if ($boards->count() !== count($ids) || $boards->contains(fn ($b) => ! $b->candidates()->exists() || $b->assignment()->exists())) {
                $this->fail('A selected board is unavailable, empty, already assigned or belongs to another date/post. Refresh and try again.');
            }
            if ($data['mode'] === 'random') {
                $members = array_map('intval', $data['member_ids'] ?? []);
                if (count($members) !== count($ids)) {
                    $this->fail('Pick exactly one different member per selected board.');
                }
                $members = (new Randomizer)->shuffleArray($members);
            } else {
                $members = array_map(fn ($id) => (int) ($data['mapping'][$id] ?? 0), $ids);
            }
            if (count(array_unique($members)) !== count($members) || in_array(0, $members, true)) {
                $this->fail('Each board needs one different member. A member cannot receive two boards on the same date.');
            }
            $eligible = $workspace->eligible()->whereIn('users.id', $members)->whereHas('vivaPools', fn ($q) => $q->whereKey($pool->id))->orderBy('users.id')->lockForUpdate()->get()->keyBy('id');
            if ($eligible->count() !== count($members)) {
                $this->fail('Selected users must be active Member/Chairman users in this pool.');
            }
            if (VivaAssignment::where('vdate', $data['vdate'])->whereIn('user_id', $members)->exists()) {
                $this->fail('A selected member already has a board on this date, including other post codes and pools.');
            }
            $created = [];
            foreach ($boards as $i => $board) {
                $assignment = VivaAssignment::create(['vdate' => $data['vdate'], 'viva_board_id' => $board->id, 'user_id' => $members[$i], 'viva_pool_id' => $pool->id, 'assigned_by' => $actor->id, 'member_snapshot' => $workspace->member($eligible[$members[$i]]), 'mode' => $data['mode']]);
                $created[] = ['assignment_id' => $assignment->id, 'board_id' => $board->id, 'post_code' => $board->post_code, 'board' => $board->board, 'member_id' => $members[$i]];
            }
            app(Audit::class)->record('viva.boards.distributed', ['date' => $data['vdate'], 'pool_id' => $pool->id, 'mode' => $data['mode'], 'assignments' => $created]);
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['distribution' => $message]);
    }
}
