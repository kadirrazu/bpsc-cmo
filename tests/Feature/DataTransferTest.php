<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DataImport;
use App\Models\Designation;
use App\Models\Expert;
use App\Models\Question;
use App\Models\Survey;
use App\Models\Unit;
use App\Models\User;
use App\Services\Transfer\ImportPlan;
use App\Services\Transfer\Workbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DataTransferTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($actor)->withSession(['auth_version' => $actor->auth_version]);

        return $actor;
    }

    private function upload(array $rows, bool $formula = false): UploadedFile
    {
        $book = app(Workbook::class)->export(array_keys($rows), true, false);
        foreach ($rows as $sheet => $items) {
            foreach ($items as $index => $row) {
                foreach (Workbook::HEADERS[$sheet] as $col => $field) {
                    $book->getSheetByName($sheet)->setCellValueExplicit([$col + 1, $index + 2], (string) ($row[$field] ?? ''), DataType::TYPE_STRING);
                }
            }
        }
        if ($formula) {
            $book->getSheetByName('Experts')->setCellValue('B2', '=1+1');
        }

        return $this->file($book);
    }

    private function file(Spreadsheet $book): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cmo-test-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $bytes = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('transfer.xlsx', $bytes);
    }

    private function expert(array $changes = []): array
    {
        return $changes + ['key' => (string) Str::uuid(), 'name' => 'Imported Expert', 'type' => 'expert', 'is_active' => '1'];
    }

    private function stage(array $rows): DataImport
    {
        $response = $this->post(route('transfers.stage'), ['file' => $this->upload($rows)]);
        $response->assertSessionHasNoErrors();
        $batch = DataImport::latest('created_at')->firstOrFail();
        $response->assertRedirect(route('transfers.preview', $batch));

        return $batch;
    }

    public function test_only_administrator_can_access_every_transfer_endpoint(): void
    {
        foreach ([UserRole::Staff, UserRole::Member, UserRole::Chairman] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor)->withSession(['auth_version' => $actor->auth_version]);
            $this->get('/data-transfer')->assertForbidden();
            $this->post('/data-transfer/download', ['scope' => 'experts'])->assertForbidden();
            $this->post('/data-transfer/stage')->assertForbidden();
            $this->get('/data-transfer/'.Str::uuid())->assertForbidden();
            $this->post('/data-transfer/'.Str::uuid().'/confirm')->assertForbidden();
            $this->delete('/data-transfer/'.Str::uuid())->assertForbidden();
            $this->get('/dashboard')->assertDontSee('Excel Import &amp; Export', false)->assertSee('Expert Evaluation System');
        }
        $this->admin();
        $this->get('/data-transfer')->assertOk()->assertSee('Administrator access only');
    }

    public function test_preview_stages_without_writes_and_confirm_is_single_use(): void
    {
        $this->admin();
        $row = $this->expert(['name' => 'বাংলা বিশেষজ্ঞ']);
        $batch = $this->stage(['Experts' => [$row]]);
        $this->assertDatabaseMissing('experts', ['name' => $row['name']]);
        $this->get(route('transfers.preview', $batch))->assertOk()->assertSee($row['name'])->assertSee('All rows passed');
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertRedirect(route('transfers.index'));
        $this->assertDatabaseHas('experts', ['name' => $row['name']]);
        $this->assertSame([], $batch->fresh()->payload);
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertGone();
        $this->assertSame(1, Expert::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'data.import.confirmed']);
    }

    public function test_invalid_row_blocks_entire_workbook_and_can_be_cancelled(): void
    {
        $this->admin();
        $batch = $this->stage(['Experts' => [$this->expert(), $this->expert(['type' => 'invalid'])]]);
        $this->get(route('transfers.preview', $batch))->assertOk()->assertSee('Import is blocked');
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertSessionHasErrors('import');
        $this->assertSame(0, Expert::count());
        $this->delete(route('transfers.cancel', $batch))->assertRedirect();
        $this->assertDatabaseMissing('data_imports', ['id' => $batch->id]);
    }

    public function test_preview_is_owned_expiring_and_paginates_rows(): void
    {
        $this->admin();
        $batch = $this->stage(['Experts' => array_map(fn ($i) => $this->expert(['name' => 'Expert '.$i]), range(1, 23))]);
        $this->get(route('transfers.preview', $batch))->assertSee('Expert 20')->assertDontSee('Expert 21');
        $this->get(route('transfers.preview', ['batch' => $batch, 'page' => 2]))->assertSee('Expert 23');
        $owner = auth()->user();
        $this->admin();
        $this->get(route('transfers.preview', $batch))->assertNotFound();
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertNotFound();
        $this->actingAs($owner)->withSession(['auth_version' => $owner->auth_version]);
        $batch->update(['expires_at' => now()->subMinute()]);
        $this->get(route('transfers.preview', $batch))->assertGone();
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertGone();
    }

    public function test_changed_target_requires_fresh_preview(): void
    {
        $this->admin();
        $expert = Expert::create($this->expert());
        $batch = $this->stage(['Experts' => [$this->expert(['key' => $expert->transfer_uuid, 'name' => 'Proposed name'])]]);
        $expert->update(['name' => 'Concurrent edit']);
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertSessionHasErrors('import');
        $this->assertSame('Concurrent edit', $expert->fresh()->name);
    }

    public function test_duplicate_natural_keys_and_missing_links_are_rejected(): void
    {
        $actor = $this->admin();
        $planner = app(ImportPlan::class);
        $base = ['name' => 'New Designation', 'sort_order' => '1', 'is_active' => '1', '_row' => 2];
        $plan = $planner->build(['Designations' => [['key' => (string) Str::uuid()] + $base, ['key' => (string) Str::uuid(), '_row' => 3] + $base]], $actor);
        $this->assertCount(1, $plan['errors']);
        $plan = $planner->build(['Surveys' => [['key' => (string) Str::uuid(), '_row' => 2, 'title' => 'Missing links', 'description' => '', 'status' => 'active', 'allowed_roles' => '["member"]', 'question_keys' => '["'.Str::uuid().'"]']]], $actor);
        $this->assertCount(1, $plan['errors']);
    }

    public function test_formula_and_malformed_workbooks_are_rejected(): void
    {
        $this->admin();
        $this->post(route('transfers.stage'), ['file' => $this->upload(['Experts' => [$this->expert()]], true)])->assertSessionHasErrors('file');
        $this->post(route('transfers.stage'), ['file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not an excel file')])->assertSessionHasErrors('file');
        $this->assertSame(0, DataImport::count());
    }

    public function test_export_uses_literal_cells_and_excludes_passwords_by_default(): void
    {
        $this->admin();
        Expert::create($this->expert(['name' => '=HYPERLINK("https://example.test")']));
        $book = app(Workbook::class)->export(['Users', 'Experts'], false, false);
        $this->assertSame('s', $book->getSheetByName('Experts')->getCell('B2')->getDataType());
        $this->assertSame('', $book->getSheetByName('Users')->getCell('J2')->getValue());
        $this->assertSame('', $book->getSheetByName('Users')->getCell('I2')->getValue());
        $book->disconnectWorksheets();
        $response = $this->post(route('transfers.download'), ['scope' => 'surveys', 'template' => '1']);
        $response->assertOk()->assertDownload('bpsc-cmo-surveys-template.xlsx');
        @unlink($response->baseResponse->getFile()->getPathname());
    }

    public function test_user_passwords_are_hidden_in_preview_and_encrypted_in_staging(): void
    {
        $actor = $this->admin();
        $this->seed();
        $row = ['key' => (string) Str::uuid(), 'name' => 'Bulk User', 'email' => 'bulk@example.test', 'role' => 'member', 'is_active' => '1', 'designation_key' => Designation::first()->transfer_uuid, 'unit_key' => Unit::first()->transfer_uuid, 'password' => 'Secret@Pass123'];
        $batch = $this->stage(['Users' => [$row]]);
        $this->get(route('transfers.preview', $batch))->assertOk()->assertDontSee('Secret@Pass123')->assertSee('Included');
        $this->assertStringNotContainsString('Secret@Pass123', DB::table('data_imports')->where('id', $batch->id)->value('payload'));
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(Hash::check('Secret@Pass123', User::where('email', 'bulk@example.test')->firstOrFail()->password));
        $this->assertTrue($actor->fresh()->is_active);
    }

    public function test_full_export_migrates_users_hashes_shared_questions_and_order_across_different_ids(): void
    {
        $actor = $this->admin();
        $this->seed();
        $designation = Designation::first();
        $unit = Unit::first();
        $member = User::factory()->create(['role' => UserRole::Member, 'designation_id' => $designation->id, 'unit' => $unit->code, 'password' => 'Portable@Pass123']);
        $q1 = Question::create(['prompt' => 'প্রথম প্রশ্ন', 'type' => 'rating', 'rating_min' => 2, 'rating_max' => 7, 'is_active' => true, 'is_required' => true]);
        $q2 = Question::create(['prompt' => 'Options', 'type' => 'select', 'options' => ['হ্যাঁ', 'না'], 'is_active' => true, 'is_required' => false]);
        $s1 = Survey::create(['title' => 'Survey one', 'status' => 'active', 'allowed_roles' => ['member'], 'created_by' => $actor->id]);
        $s2 = Survey::create(['title' => 'Survey two', 'status' => 'draft', 'allowed_roles' => ['chairman'], 'created_by' => $actor->id]);
        $s1->questions()->sync([$q2->id => ['position' => 1], $q1->id => ['position' => 2]]);
        $s2->questions()->attach($q1, ['position' => 1]);
        Expert::create($this->expert());
        // Export the member account only; the acting destination admin remains local.
        $book = app(Workbook::class)->export(array_keys(Workbook::HEADERS), false, true);
        $users = $book->getSheetByName('Users');
        $users->removeRow(2); // source Administrator has no assigned masters
        $file = $this->file($book);
        $keys = [$q1->transfer_uuid, $q2->transfer_uuid];
        $oldIds = [$q1->id, $q2->id];
        DB::table('question_survey')->delete();
        DB::table('surveys')->delete();
        DB::table('questions')->delete();
        DB::table('experts')->delete();
        $member->forceDelete();
        // Simulate independently seeded destination masters with different reference keys.
        // Unique per-row keys are required; refresh each separately.
        foreach (Designation::all() as $d) {
            DB::table('designations')->where('id', $d->id)->update(['transfer_uuid' => (string) Str::uuid()]);
        }
        foreach (Unit::all() as $u) {
            DB::table('units')->where('id', $u->id)->update(['transfer_uuid' => (string) Str::uuid()]);
        }
        $this->post(route('transfers.stage'), ['file' => $file])->assertSessionHasNoErrors()->assertRedirect();
        $batch = DataImport::latest('created_at')->firstOrFail();
        $this->assertSame([], $batch->payload['plan']['errors']);
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $newMember = User::where('email', $member->email)->firstOrFail();
        $this->assertTrue(Hash::check('Portable@Pass123', $newMember->password));
        $this->assertSame($designation->id, $newMember->designation_id);
        $this->assertNotSame($oldIds, Question::orderBy('id')->pluck('id')->all());
        $newSurvey = Survey::where('title', 'Survey one')->firstOrFail();
        $this->assertSame(array_reverse($keys), $newSurvey->questions()->orderBy('question_survey.position')->pluck('questions.transfer_uuid')->all());
        $this->assertSame(2, Question::where('transfer_uuid', $keys[0])->firstOrFail()->surveys()->count());
        $this->assertSame(['হ্যাঁ', 'না'], Question::where('transfer_uuid', $keys[1])->firstOrFail()->options);
        $this->assertDatabaseHas('questions', ['transfer_uuid' => $keys[0], 'rating_min' => 2, 'rating_max' => 7]);
        // Reimporting the same bytes updates, rather than duplicates, every record.
        $this->post(route('transfers.stage'), ['file' => $file])->assertSessionHasNoErrors();
        $batch = DataImport::where('status', 'pending')->firstOrFail();
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, Question::count());
        $this->assertSame(2, Survey::count());
        $this->assertSame(1, Expert::count());
    }

    public function test_samples_for_every_dataset_are_valid_and_downloadable(): void
    {
        $actor = $this->admin();
        foreach (array_keys(Workbook::SCOPES) as $scope) {
            $response = $this->post(route('transfers.download'), ['scope' => $scope, 'sample' => '1']);
            $response->assertOk()->assertDownload('bpsc-cmo-'.$scope.'-sample.xlsx');
            $path = $response->baseResponse->getFile()->getPathname();
            $rows = app(Workbook::class)->read($path, $actor);
            $plan = app(ImportPlan::class)->build($rows, $actor);
            $this->assertSame([], $plan['errors'], $scope);
            @unlink($path);
        }
        $this->get('/data-transfer')->assertSee('Download Sample .xlsx');
    }

    public function test_import_cannot_remove_own_administrator_access(): void
    {
        $actor = $this->admin();
        $this->seed();
        $row = ['key' => $actor->transfer_uuid, 'name' => $actor->name, 'email' => $actor->email, 'role' => 'staff', 'is_active' => '1', 'designation_key' => Designation::first()->transfer_uuid, 'unit_key' => Unit::first()->transfer_uuid];
        $batch = $this->stage(['Users' => [$row]]);
        $this->assertCount(1, $batch->payload['plan']['errors']);
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertSessionHasErrors('import');
        $this->assertSame(UserRole::Admin, $actor->fresh()->role);
    }

    public function test_late_failure_rolls_back_all_rows_and_keeps_batch_pending(): void
    {
        $this->admin();
        $batch = $this->stage(['Experts' => [$this->expert(['name' => 'First']), $this->expert(['name' => 'Second'])]]);
        $planner = \Mockery::mock(ImportPlan::class)->makePartial();
        $planner->shouldReceive('apply')->once()->andReturnUsing(function () {
            Expert::create(['name' => 'Partial write', 'type' => 'expert', 'is_active' => true]);
            throw ValidationException::withMessages(['import' => 'Simulated failure']);
        });
        $this->app->instance(ImportPlan::class, $planner);
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertSessionHasErrors('import');
        $this->assertSame(0, Expert::count());
        $this->assertSame('pending', $batch->fresh()->status);
        $this->assertNotEmpty($batch->fresh()->payload);
    }

    public function test_confirmation_requires_explicit_review_and_current_administrator_role(): void
    {
        $actor = $this->admin();
        $batch = $this->stage(['Experts' => [$this->expert()]]);
        $this->post(route('transfers.confirm', $batch))->assertSessionHasErrors('confirmation');
        $actor->update(['role' => UserRole::Staff]);
        $this->withSession(['auth_version' => $actor->fresh()->auth_version]);
        $this->post(route('transfers.confirm', $batch), ['confirmation' => '1'])->assertForbidden();
        $this->assertSame(0, Expert::count());
    }
}
