<?php

namespace App\Http\Controllers\Viva;

use App\Http\Controllers\Controller;
use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaPool;
use App\Services\Library\Audit;
use App\Services\Viva\Distribution;
use App\Services\Viva\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoardController extends Controller
{
    public function index(Request $r, Workspace $workspace)
    {
        $filters = $r->validate(['vdate' => 'nullable|date_format:Y-m-d', 'post_code' => 'nullable|string|max:20', 'pool_id' => 'nullable|integer|min:1']);
        $date = $workspace->date($filters['vdate'] ?? null);
        $posts = VivaBoard::where('vdate', $date)->orderBy('post_code')->get(['post_code', 'post_name'])->uniqueStrict('post_code');
        $post = $filters['post_code'] ?? $posts->first()?->post_code;
        $pools = VivaPool::where('is_active', true)->withCount('users')->orderBy('name')->get();
        $pool = isset($filters['pool_id']) ? $pools->firstWhere('id', (int) $filters['pool_id']) : null;
        $used = VivaAssignment::where('vdate', $date)->pluck('user_id')->all();
        $members = $pool ? $workspace->eligible()->whereHas('vivaPools', fn ($q) => $q->whereKey($pool->id))->whereNotIn('users.id', $used)->get() : collect();
        $boards = VivaBoard::withCount('candidates')->with('assignment.member')->where('vdate', $date)->when($post, fn ($q) => $q->where('post_code', $post))->orderByRaw('length(board)')->orderBy('board')->get();
        $opens = $workspace->opens($date);
        $open = now('Asia/Dhaka')->greaterThanOrEqualTo($opens);
        $dayAssignments = VivaAssignment::with('board', 'member')->where('vdate', $date)->orderBy('id')->paginate(20)->withQueryString();

        return view('viva.boards', compact('date', 'posts', 'post', 'pools', 'pool', 'members', 'boards', 'opens', 'open', 'dayAssignments'));
    }

    public function store(Request $r, Distribution $distribution)
    {
        $data = $r->validate(['vdate' => 'required|date_format:Y-m-d', 'post_code' => 'required|string|max:20', 'pool_id' => 'required|integer|exists:viva_pools,id', 'mode' => 'required|in:manual,random', 'board_ids' => 'required|array|min:1|max:500', 'board_ids.*' => 'required|integer|distinct|min:1', 'member_ids' => 'nullable|array|max:500', 'member_ids.*' => 'required|integer|distinct|min:1', 'mapping' => 'nullable|array|max:500', 'mapping.*' => 'nullable|integer|min:1']);
        try {
            $distribution->assign($data, $r->user());
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['distribution' => 'A board or member was assigned by another request. Nothing was assigned; refresh and try again.']);
        }

        return redirect()->route('viva.boards.index', array_intersect_key($data, array_flip(['vdate', 'post_code', 'pool_id'])))->with('success', 'Boards assigned. The live display will update automatically.');
    }

    public function destroy(Request $r, VivaAssignment $assignment, Workspace $workspace)
    {
        $r->validate(['confirmation' => 'required|in:REMOVE', 'reason' => 'required|string|max:500']);
        DB::transaction(function () use ($r, $assignment, $workspace) {
            $workspace->lockDay($assignment->vdate->toDateString());
            $workspace->ensureOpen($assignment->vdate->toDateString());
            $assignment = VivaAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            app(Audit::class)->record('viva.assignment.removed', ['assignment_id' => $assignment->id, 'board_id' => $assignment->viva_board_id, 'member_id' => $assignment->user_id, 'date' => $assignment->vdate->toDateString(), 'reason' => $r->input('reason')]);
            $assignment->delete();
        });

        return back()->with('success','Assignment removed. The board and member can now be assigned again.');
    }
}
