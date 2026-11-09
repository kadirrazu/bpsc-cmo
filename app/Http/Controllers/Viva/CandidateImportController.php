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
        $r->validate(['vdate' => 'nullable|date_format:Y-m-d']);
        $date = $workspace->date($r->input('vdate'));
        $boards = VivaBoard::withCount('candidates')->with('assignment')->where('vdate', $date)->orderBy('post_code')->orderBy('board')->paginate(20)->withQueryString();

        return view('viva.imports.index', compact('date', 'boards'));
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
        DB::transaction(function () use ($r, $batch) {
            $this->owned($r, $batch, true)->delete();
            app(Audit::class)->record('viva.candidates.preview.cancelled', ['batch' => $batch]);
        });

        return redirect()->route('viva.imports.index')->with('success', 'Preview cancelled. No candidate records were changed.');
    }

    public function destroy(Request $r, VivaBoard $board, Workspace $workspace)
    {
        $r->validate(['confirmation' => 'required|in:REMOVE']);
        DB::transaction(function () use ($board, $workspace) {
            $workspace->lockDay($board->vdate->toDateString());
            $board = VivaBoard::whereKey($board->id)->lockForUpdate()->firstOrFail();
            if ($board->assignment()->exists()) {
                throw ValidationException::withMessages(['board' => 'Assigned boards cannot be removed or have their candidate data changed.']);
            }
            app(Audit::class)->record('viva.board.import.removed', ['board_id' => $board->id, 'date' => $board->vdate->toDateString(), 'post_code' => $board->post_code, 'board' => $board->board, 'candidate_count' => $board->candidates()->count()]);
            $board->delete();
        });

        return back()->with('success', 'Unassigned board and its candidates removed.');
    }

    private function owned(Request $r, string $id, bool $lock = false): VivaImport
    {
        $batch = VivaImport::whereKey($id)->where('user_id', $r->user()->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($batch->status === 'pending' && $batch->expires_at->isFuture(), 410, 'Preview expired or already used. Upload again.');

        return $batch;
    }
}
