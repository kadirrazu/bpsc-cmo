<?php

namespace App\Http\Controllers\Viva;

use App\Http\Controllers\Controller;
use App\Models\VivaAssignment;
use App\Services\Viva\MyBoardDownload;
use App\Services\Viva\Workspace;
use Illuminate\Http\Request;

class MyBoardController extends Controller
{
    public function index(Request $request, Workspace $workspace)
    {
        [$date, $assignment] = $this->ownBoard($request, $workspace);
        $candidates = $assignment?->board->candidates()->orderBy('reg')->paginate(50)->withQueryString();

        return view('viva.my-board', compact('date', 'assignment', 'candidates'));
    }

    public function download(Request $request, Workspace $workspace, MyBoardDownload $download)
    {
        $request->validate(['format' => 'required|in:pdf']);
        [$date, $assignment] = $this->ownBoard($request, $workspace);
        abort_unless($assignment, 404, 'No board has been assigned to you for this date.');

        return $download->response($assignment, $date, $request->string('format')->toString());
    }

    private function ownBoard(Request $request, Workspace $workspace): array
    {
        $request->validate(['vdate' => 'nullable|date_format:Y-m-d']);
        $date = $workspace->date($request->input('vdate'));
        // Scope at the database boundary. IDs supplied by a client never select a board.
        $assignment = VivaAssignment::with('board')->where('user_id', $request->user()->id)->where('vdate', $date)->first();

        return [$date, $assignment];
    }
}
