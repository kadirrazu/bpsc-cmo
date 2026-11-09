<?php

namespace App\Http\Controllers;

use App\Models\DataImport;
use App\Services\Library\Audit;
use App\Services\Transfer\ImportPlan;
use App\Services\Transfer\Workbook;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class DataTransferController extends Controller
{
    public function index()
    {
        return view('transfers.index', ['scopes' => array_keys(Workbook::SCOPES)]);
    }

    public function download(Request $request, Workbook $workbooks)
    {
        $data = $request->validate(['scope' => 'required|in:'.implode(',', array_keys(Workbook::SCOPES)), 'template' => 'sometimes|boolean', 'sample' => 'sometimes|boolean', 'credentials' => 'sometimes|boolean']);
        $sheets = $workbooks->sheets($data['scope'], $request->user());
        $sample = $request->boolean('sample');
        $template = $request->boolean('template') || $sample;
        $credentials = $request->boolean('credentials') && ! $template && in_array('Users', $sheets, true);
        File::ensureDirectoryExists(storage_path('app/private'), 0700);
        $path = tempnam(storage_path('app/private'), 'cmo-export-');
        $book = null;
        try {
            $book = DB::transaction(fn () => $workbooks->export($sheets, $template, $credentials, $sample));
            (new Xlsx($book))->save($path);
            app(Audit::class)->record($sample ? 'data.sample.downloaded' : ($template ? 'data.template.downloaded' : 'data.exported'), ['sheets' => $sheets, 'credentials_included' => $credentials]);

            return response()->download($path, 'bpsc-cmo-'.$data['scope'].($sample ? '-sample' : ($template ? '-template' : '-'.now()->format('Ymd-His'))).'.xlsx', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    public function stage(Request $request, Workbook $workbooks, ImportPlan $planner)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx|max:10240']);
        try {
            $rows = $workbooks->read($request->file('file')->getRealPath(), $request->user());
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => 'Unable to read this workbook. Download a template and upload a valid .xlsx file.']);
        }
        $plan = $planner->build($rows, $request->user());
        // Confirmation rebuilds normalized data; store only preview metadata once.
        foreach ($plan['items'] as &$items) {
            foreach ($items as &$item) {
                unset($item['data']);
            }
            unset($item);
        }
        unset($items);
        DataImport::where('expires_at', '<', now())->delete();
        $batch = DataImport::create(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'payload' => ['rows' => $rows, 'plan' => $plan], 'expires_at' => now()->addHour()]);
        app(Audit::class)->record('data.import.staged', ['batch' => $batch->id, 'counts' => $plan['counts'], 'error_count' => count($plan['errors'])]);

        return redirect()->route('transfers.preview', $batch);
    }

    public function preview(Request $request, string $batch)
    {
        $batch = $this->owned($request, $batch);
        $payload = $batch->payload;
        $sheet = $request->query('sheet', array_key_first($payload['rows']));
        abort_unless(is_string($sheet) && isset($payload['rows'][$sheet]), 404);
        $page = max(1, (int) $request->query('page', 1));
        $rows = new LengthAwarePaginator(array_slice($payload['rows'][$sheet], ($page - 1) * 20, 20), count($payload['rows'][$sheet]), 20, $page, ['path' => $request->url(), 'query' => ['sheet' => $sheet]]);

        return response()->view('transfers.preview', ['batch' => $batch, 'plan' => $payload['plan'], 'sheets' => array_keys($payload['rows']), 'sheet' => $sheet, 'rows' => $rows])->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, string $batch, ImportPlan $planner)
    {
        $request->validate(['confirmation' => 'required|accepted']);
        try {
            DB::transaction(function () use ($request, $batch, $planner) {
                $batch = $this->owned($request, $batch, true);
                $payload = $batch->payload;
                if ($payload['plan']['errors']) {
                    throw ValidationException::withMessages(['import' => 'This preview contains invalid rows. Correct the workbook and upload it again.']);
                }
                $plan = $planner->build($payload['rows'], $request->user(), true);
                if ($plan['errors']) {
                    throw ValidationException::withMessages(['import' => 'Validation failed. Cancel this preview, correct the workbook and upload it again.']);
                }
                if ($plan['versions'] !== $payload['plan']['versions']) {
                    throw ValidationException::withMessages(['import' => 'Data changed after this preview. Upload again to review a fresh preview before confirming.']);
                }
                $planner->apply($plan, $request->user());
                $batch->update(['status' => 'applied', 'payload' => []]);
                app(Audit::class)->record('data.import.confirmed', ['batch' => $batch->id, 'counts' => $plan['counts'], 'password_setup' => $plan['password_setup']]);
            });

        } catch (QueryException $e) {
            throw ValidationException::withMessages(['import' => 'The import could not be saved because data conflicts with this installation or another change occurred. No rows were saved. Resolve conflicting keys, names or emails and upload again.']);
        }

        return redirect()->route('transfers.index')->with('success', 'Import completed. All reviewed rows were saved together. New users without a supplied password need Administrator password setup from Users → Edit / Password.');
    }

    public function cancel(Request $request, string $batch)
    {
        DB::transaction(function () use ($request, $batch) {
            $batch = $this->owned($request, $batch, true);
            $batch->delete();
            app(Audit::class)->record('data.import.cancelled', ['batch' => $batch->id]);
        });

        return redirect()->route('transfers.index')->with('success', 'Preview cancelled. No imported records were saved.');
    }

    private function owned(Request $request, string $id, bool $lock = false): DataImport
    {
        $batch = DataImport::whereKey($id)->where('user_id', $request->user()->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($batch->status === 'pending' && $batch->expires_at->isFuture(), 410, 'This preview has expired or was already used. Upload the workbook again.');

        return $batch;
    }
}
