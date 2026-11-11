<?php

namespace App\Http\Controllers\Viva;

use App\Http\Controllers\Controller;
use App\Models\VivaBoard;
use App\Models\VivaImport;
use App\Services\Library\Audit;
use App\Services\Viva\CandidateWorkbook;
use App\Services\Viva\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CandidateImportController extends Controller
{
    public function index(Request $r, Workspace $workspace)
    {
        $r->validate(['vdate' => 'nullable|date_format:Y-m-d', 'post_code' => 'nullable|string|max:20']);
        $date = $workspace->date($r->input('vdate'));
        $post = $r->input('post_code');
        $posts = VivaBoard::where('vdate', $date)->orderBy('post_code')->get(['post_code', 'post_name'])->uniqueStrict('post_code');
        $boards = VivaBoard::withCount('candidates')->with('assignment')->where('vdate', $date)->when($post !== null && $post !== '', fn ($q) => $q->where('post_code', $post))->orderBy('post_code')->orderByRaw('length(board)')->orderBy('board')->paginate(20)->withQueryString();

        $scopeCounts = null;
        if ($post !== null && $post !== '') {
            $scope = VivaBoard::where('vdate', $date)->where('post_code', $post);
            $scopeCounts = [
                'boards' => (clone $scope)->count(),
                'candidates' => DB::table('viva_candidates')->whereIn('viva_board_id', (clone $scope)->select('id'))->count(),
                'assignments' => DB::table('viva_assignments')->whereIn('viva_board_id', (clone $scope)->select('id'))->count(),
            ];
        }

        return view('viva.imports.index', compact('date', 'boards', 'post', 'posts', 'scopeCounts'));
    }

    public function sample(Request $r, CandidateWorkbook $workbooks)
    {
        File::ensureDirectoryExists(storage_path('app/private'), 0700);
        $path = tempnam(storage_path('app/private'), 'viva-sample-');
        $book = $workbooks->sample($r->boolean('blank'));
        try {
            (new Xlsx($book))->save($path);
        } finally {
            $book->disconnectWorksheets();
        }

        return response()->download($path, 'viva-candidates-'.($r->boolean('blank') ? 'template' : 'sample').'.xlsx')->deleteFileAfterSend();
    }

    public function stage(Request $r, CandidateWorkbook $workbooks)
    {
        $r->validate(['file' => 'required|file|mimes:xlsx|max:10240']);
        try {
            $rows = $workbooks->read($r->file('file')->getRealPath());
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => 'Unable to read this workbook. Use the downloadable .xlsx sample/template.']);
        }
        $plan = $workbooks->plan($rows);
        VivaImport::where('expires_at', '<', now())->delete();
        $batch = VivaImport::create(['id' => (string) Str::uuid(), 'user_id' => $r->user()->id, 'payload' => ['rows' => $rows, 'plan' => $plan], 'expires_at' => now()->addHour()]);
        app(Audit::class)->record('viva.candidates.staged', ['batch' => $batch->id, 'create' => $plan['create'], 'update' => $plan['update'], 'errors' => count($plan['errors'])]);

        return redirect()->route('viva.imports.preview', $batch);
    }

    public function preview(Request $r, string $batch)
    {
        $batch = $this->owned($r, $batch);
        $payload = $batch->payload;
        $page = max(1, (int) $r->query('page', 1));
        $rows = new LengthAwarePaginator(array_slice($payload['rows'], ($page - 1) * 20, 20), count($payload['rows']), 20, $page, ['path' => $r->url()]);

        return view('viva.imports.preview', ['batch' => $batch, 'plan' => $payload['plan'], 'rows' => $rows]);
    }

    public function confirm(Request $r, string $batch, CandidateWorkbook $workbooks, Workspace $workspace)
    {
        $r->validate(['confirmation' => 'required|accepted']);
        try {
            $dates = DB::transaction(function () use ($r, $batch, $workbooks, $workspace) {
                $batch = $this->owned($r, $batch, true);
                $payload = $batch->payload;
                if ($payload['plan']['errors']) {
                    throw ValidationException::withMessages(['import' => 'Correct invalid rows and upload again.']);
                }
                foreach ($payload['plan']['dates'] as $date) {
                    $workspace->lockDay($date);
                }
                $plan = $workbooks->plan($payload['rows']);
                if ($plan['errors'] || $plan['versions'] !== $payload['plan']['versions']) {
                    throw ValidationException::withMessages(['import' => 'Candidate/board data changed or became unavailable after preview. Upload again for a fresh preview.']);
                }
                $workbooks->apply($plan);
                $batch->update(['status' => 'applied', 'payload' => []]);
                app(Audit::class)->record('viva.candidates.imported', ['batch' => $batch->id, 'create' => $plan['create'], 'update' => $plan['update'], 'dates' => $plan['dates']]);

                return $plan['dates'];
            });
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['import' => 'Import conflicted with another change. No rows were saved; upload again.']);
        }

        return redirect()->route('viva.imports.index', ['vdate' => $dates[0]])->with('success', 'Candidate import completed for '.implode(', ', $dates).'. Post codes and groups are ready for distribution.');
    }

    public function cancel(Request $r, string $batch)
    {
        $r->validate(['confirmation' => 'required|string|in:DELETE']);
        DB::transaction(function () use ($r, $batch) {
            $this->owned($r, $batch, true)->delete();
            app(Audit::class)->record('viva.candidates.preview.cancelled', ['batch' => $batch]);
        });

        return redirect()->route('viva.imports.index')->with('success', 'Preview cancelled. No candidate records were changed.');
    }

    public function removal(Request $request)
    {
        $data = $request->validate([
            'mode' => 'required|in:board,scope,all',
            'board_id' => 'required_if:mode,board|nullable|integer|min:1',
            'vdate' => 'required_if:mode,scope|nullable|date_format:Y-m-d',
            'post_code' => 'required_if:mode,scope|nullable|string|regex:/^[0-9]{1,20}$/',
        ]);
        $board = null;
        $date = $data['vdate'] ?? null;
        $post = $data['post_code'] ?? null;
        if ($data['mode'] === 'board') {
            $board = VivaBoard::with('assignment')->findOrFail($data['board_id']);
            abort_if($board->assignment, 409, 'Assigned boards cannot be removed individually.');
            $date = $board->vdate->toDateString();
            $post = $board->post_code;
            $scope = VivaBoard::whereKey($board->id);
            $title = 'Remove Board & Candidates';
            $action = route('viva.imports.destroy', $board);
        } elseif ($data['mode'] === 'scope') {
            $scope = VivaBoard::where('vdate', $date)->where('post_code', $post);
            $title = 'Remove Selected Date & Post';
            $action = route('viva.imports.destroy-scope');
        } else {
            $scope = VivaBoard::query();
            $title = 'Remove All Imported Candidate Data';
            $action = route('viva.imports.destroy-all');
        }
        $counts = [
            'boards' => (clone $scope)->count(),
            'candidates' => DB::table('viva_candidates')->whereIn('viva_board_id', (clone $scope)->select('id'))->count(),
            'assignments' => DB::table('viva_assignments')->whereIn('viva_board_id', (clone $scope)->select('id'))->count(),
        ];
        if ($data['mode'] === 'all') {
            $counts['imports'] = VivaImport::count();
        }
        $canRemove = $counts['boards'] > 0 || ($data['mode'] === 'all' && $counts['imports'] > 0);
        $backUrl = route('viva.imports.index', array_filter(['vdate' => $date, 'post_code' => $post], fn ($value) => $value !== null && $value !== ''));
        $mode = $data['mode'];

        return view('viva.imports.removal', compact('mode', 'board', 'date', 'post', 'title', 'action', 'counts', 'backUrl', 'canRemove'));
    }

    public function destroy(Request $r, VivaBoard $board, Workspace $workspace)
    {
        $r->validate(['confirmation' => 'required|string|in:DELETE']);
        DB::transaction(function () use ($board, $workspace) {
            $workspace->lockDay($board->vdate->toDateString());
            $board = VivaBoard::whereKey($board->id)->lockForUpdate()->firstOrFail();
            if ($board->assignment()->exists()) {
                throw ValidationException::withMessages(['board' => 'Assigned boards cannot be removed or have their candidate data changed.']);
            }
            app(Audit::class)->record('viva.board.import.removed', ['board_id' => $board->id, 'date' => $board->vdate->toDateString(), 'post_code' => $board->post_code, 'board' => $board->board, 'candidate_count' => $board->candidates()->count()]);
            $board->delete();
        });

        return redirect()->route('viva.imports.index', ['vdate' => $board->vdate->toDateString(), 'post_code' => $board->post_code])->with('success', 'Unassigned board and its candidates removed.');
    }

    public function destroyScope(Request $request, Workspace $workspace, CandidateWorkbook $workbooks)
    {
        $data = $request->validate([
            'vdate' => 'required|date_format:Y-m-d',
            'post_code' => 'required|string|regex:/^[0-9]{1,20}$/',
            'confirmation' => 'required|string|in:DELETE',
        ]);
        $counts = DB::transaction(function () use ($data, $workspace, $workbooks) {
            // Import confirmation locks its batch before the day mutex. Keep the
            // same order so an affected staged import cannot restore removed data.
            $previews = VivaImport::where('status', 'pending')->orderBy('id')->lockForUpdate()->get();
            $workspace->lockDay($data['vdate']);
            $ids = VivaBoard::where('vdate', $data['vdate'])->where('post_code', $data['post_code'])->orderBy('id')->lockForUpdate()->pluck('id');
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['removal' => 'No imported boards match the selected date and post code. Nothing was removed.']);
            }
            $counts = [
                'assignments' => DB::table('viva_assignments')->whereIn('viva_board_id', $ids)->delete(),
                'candidates' => DB::table('viva_candidates')->whereIn('viva_board_id', $ids)->delete(),
                'boards' => DB::table('viva_boards')->whereIn('id', $ids)->delete(),
                'previews_cancelled' => 0,
            ];
            foreach ($previews as $preview) {
                $payload = $preview->payload;
                $matches = false;
                foreach ($payload['rows'] ?? [] as $row) {
                    if (! is_array($row) || (string) ($row['post_code'] ?? '') !== $data['post_code']) {
                        continue;
                    }
                    try {
                        if ($workbooks->date((string) ($row['vdate'] ?? '')) === $data['vdate']) {
                            $matches = true;
                            break;
                        }
                    } catch (ValidationException) {
                        // An invalid date cannot be imported as this selected date.
                    }
                }
                if ($matches) {
                    $preview->update(['status' => 'cancelled', 'payload' => []]);
                    $counts['previews_cancelled']++;
                }
            }
            app(Audit::class)->record('viva.candidates.scope-removed', ['vdate' => $data['vdate'], 'post_code' => $data['post_code'], 'board_ids' => $ids->all(), 'deleted_rows' => $counts]);

            return $counts;
        }, 3);

        return redirect()->route('viva.imports.index', ['vdate' => $data['vdate'], 'post_code' => $data['post_code']])
            ->with('success', 'Removed date '.$data['vdate'].' / post '.$data['post_code'].': '.$counts['boards'].' boards, '.$counts['candidates'].' candidates and '.$counts['assignments'].' assignments. '.$counts['previews_cancelled'].' related pending previews cancelled. Other dates and posts were retained.');
    }

    public function destroyAll(Request $request, Workspace $workspace)
    {
        $request->validate(['confirmation' => 'required|string|in:DELETE']);
        $counts = DB::transaction(function () use ($workspace) {
            $dates = DB::table('viva_boards')->pluck('vdate')->merge(DB::table('viva_assignments')->pluck('vdate'))->unique()->sort();
            foreach ($dates as $date) {
                $workspace->lockDay($date);
            }
            $counts = [];
            foreach (['viva_assignments', 'viva_candidates', 'viva_boards', 'viva_imports'] as $table) {
                $counts[$table] = DB::table($table)->delete();
            }
            app(Audit::class)->record('viva.candidates.all-removed', ['deleted_rows' => $counts]);

            return $counts;
        }, 3);

        return redirect()->route('viva.imports.index')->with('success', 'All imported candidate data removed: '.$counts['viva_boards'].' boards, '.$counts['viva_candidates'].' candidates and '.$counts['viva_assignments'].' assignments.');
    }

    private function owned(Request $r, string $id, bool $lock = false): VivaImport
    {
        $batch = VivaImport::whereKey($id)->where('user_id', $r->user()->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($batch->status === 'pending' && $batch->expires_at->isFuture(), 410, 'Preview expired or already used. Upload again.');

        return $batch;
    }
}
