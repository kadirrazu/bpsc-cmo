<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Designation;
use App\Models\Unit;
use App\Models\User;
use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaCandidate;
use App\Models\VivaImport;
use App\Models\VivaPool;
use App\Models\VivaSetting;
use App\Services\Transfer\ImportPlan;
use App\Services\Transfer\Workbook;
use App\Services\Viva\CandidateWorkbook;
use App\Services\Viva\DisplayData;
use App\Services\Viva\Distribution;
use App\Services\Viva\Workspace;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class VivaBoardDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function login(UserRole $role = UserRole::Chairman): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    private function pool(array $members): VivaPool
    {
        $pool = VivaPool::create(['name' => 'Viva Pool', 'is_active' => true]);
        $pool->users()->sync(array_map(fn ($u) => $u->id, $members));

        return $pool;
    }

    private function board(string $date = '2026-11-10', string $post = '250019', string $group = '01', array $regs = ['00123456']): VivaBoard
    {
        $board = VivaBoard::create(['vdate' => $date, 'post_code' => $post, 'post_name' => 'Fisheries Survey Officer', 'board' => $group]);
        foreach ($regs as $reg) {
            VivaCandidate::create(['viva_board_id' => $board->id, 'vdate' => $date, 'post_code' => $post, 'reg' => $reg, 'name' => 'Private Candidate Name']);
        }

        return $board;
    }

    private function data(VivaPool $pool, VivaBoard $board, User $member, array $extra = []): array
    {
        return $extra + ['vdate' => $board->vdate->toDateString(), 'post_code' => $board->post_code, 'pool_id' => $pool->id, 'mode' => 'manual', 'board_ids' => [$board->id], 'mapping' => [$board->id => $member->id]];
    }

    private function open(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 09:30:00', 'Asia/Dhaka'));
    }

    private function upload(array $rows, bool $formula = false): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(CandidateWorkbook::HEADERS);
        foreach ($rows as $i => $row) {
            foreach (CandidateWorkbook::HEADERS as $j => $key) {
                $sheet->setCellValueExplicit([$j + 1, $i + 2], (string) ($row[$key] ?? ''), DataType::TYPE_STRING);
            }
        }
        if ($formula) {
            $sheet->setCellValue('B2', '=1+1');
        }
        $path = tempnam(sys_get_temp_dir(), 'viva-test-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $bytes = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('candidates.xlsx', $bytes);
    }

    private function row(array $extra = []): array
    {
        return $extra + ['post_code' => '250019', 'post_name' => 'Fisheries Survey Officer', 'unit' => 'Unit 01', 'ministry' => 'Ministry of Fisheries', 'vdate' => '101126', 'board' => '01', 'reg' => '00123456', 'name' => ''];
    }

    private function stage(array $rows): VivaImport
    {
        $this->post(route('viva.imports.stage'), ['file' => $this->upload($rows)])->assertSessionHasNoErrors()->assertRedirect();

        return VivaImport::latest('created_at')->firstOrFail();
    }

    private function displaySettings(): void
    {
        VivaSetting::findOrFail(1)->update(['display_username' => 'noticeboard', 'display_password' => 'Display@Pass123']);
    }

    public function test_chairman_has_exactly_two_sequential_options_and_administrator_owns_import_settings(): void
    {
        $this->login();
        $response = $this->get(route('viva.pools.index'))->assertOk()->assertSeeInOrder(['1. Member Pools', '2. Board Distribution'])->assertDontSee('Candidate Import')->assertDontSee('>Settings<', false);
        $this->get(route('viva.home'))->assertRedirect(route('viva.pools.index'));
        foreach (['viva.imports.index', 'viva.settings.edit'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->post(route('viva.imports.stage'))->assertForbidden();
        $this->put(route('viva.settings.update'))->assertForbidden();
        $this->login(UserRole::Admin);
        $this->get(route('viva.home'))->assertRedirect(route('viva.imports.index'));
        $this->get(route('viva.imports.index'))->assertOk();
        $this->get(route('viva.settings.edit'))->assertOk();
        $this->get(route('viva.boards.index'))->assertOk()->assertDontSee('Distribute Selected Boards');
        $this->post(route('viva.boards.store'))->assertForbidden();
        $this->get(route('viva.pools.index'))->assertForbidden();
    }

    public function test_member_staff_and_guests_cannot_use_backend_routes(): void
    {
        foreach ([UserRole::Member, UserRole::Staff] as $role) {
            $this->login($role);
            if ($role === UserRole::Member) {
                $this->get(route('viva.home'))->assertRedirect(route('viva.my-board.index'));
            } else {
                $this->get(route('viva.home'))->assertForbidden();
            }
            foreach (['viva.pools.index', 'viva.boards.index', 'viva.imports.index', 'viva.settings.edit'] as $route) {
                $this->get(route($route))->assertForbidden();
            }$this->post(route('viva.boards.store'))->assertForbidden();
            $this->get(route('viva.report', ['vdate' => '2026-11-10']))->assertForbidden();
        }
    }

    public function test_pool_all_selected_update_and_remove_work_without_allowing_other_roles(): void
    {
        $chair = $this->login();
        $member = User::factory()->create(['role' => 'member']);
        User::factory()->create(['role' => 'staff']);
        $inactive = User::factory()->create(['role' => 'member', 'is_active' => false]);
        $this->post(route('viva.pools.store'), ['name' => 'Everyone', 'is_active' => 1, 'selection' => 'all_eligible'])->assertSessionHasNoErrors();
        $pool = VivaPool::firstOrFail();
        $this->assertSame(2, $pool->users()->count());
        $this->put(route('viva.pools.update', $pool), ['name' => 'Selected', 'is_active' => 1, 'selection' => 'selected', 'member_ids' => [$member->id]])->assertSessionHasNoErrors();
        $this->assertSame([$member->id], $pool->users()->pluck('users.id')->all());
        $this->put(route('viva.pools.update', $pool), ['name' => 'Bad', 'is_active' => 1, 'selection' => 'selected', 'member_ids' => [$inactive->id]])->assertSessionHasErrors('member_ids');
        $this->delete(route('viva.pools.destroy', $pool), ['confirmation' => 'REMOVE'])->assertRedirect();
        $this->assertSoftDeleted($pool);
    }

    public function test_pool_form_and_distribution_pages_render_for_chairman(): void
    {
        $chair = $this->login();
        $member = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$member, $chair]);
        $this->board();
        $this->open();
        $this->get(route('viva.pools.create'))->assertOk();
        $this->get(route('viva.pools.edit', $pool))->assertOk()->assertSee($member->name);
        $this->get(route('viva.boards.index', ['vdate' => '2026-11-10', 'pool_id' => $pool->id]))->assertOk()->assertSee('Specific assignment')->assertSee('Random assignment')->assertSee('Distribute Selected Boards');
    }

    public function test_dates_and_zero_padded_identifiers_are_normalized_without_losing_registration_zeroes(): void
    {
        $service = app(CandidateWorkbook::class);
        foreach (['091126', '09112026', '91126', '9112026'] as $date) {
            $this->assertSame('2026-11-09', $service->date($date));
        }
        $this->assertSame('1997-10-10', $service->date('101097'));
        $this->assertSame('2000-10-10', $service->date('101000'));
        $this->login(UserRole::Admin);
        $batch = $this->stage([$this->row(['board' => '1', 'vdate' => '091126'])]);
        $this->assertSame('01', $batch->payload['plan']['items'][0]['board']);
        $this->assertSame('00123456', $batch->payload['plan']['items'][0]['reg']);
        $this->expectException(ValidationException::class);
        $service->date('310226');
    }

    public function test_candidate_stage_preview_confirm_repeat_and_owner_rules(): void
    {
        $admin = $this->login(UserRole::Admin);
        $rows = [$this->row(), $this->row(['reg' => '00123457', 'name' => 'Second'])];
        $batch = $this->stage($rows);
        $this->assertDatabaseCount('viva_candidates', 0);
        $this->get(route('viva.imports.preview', $batch->id))->assertOk()->assertSee('00123456')->assertSee('2026-11-10');
        $this->assertStringNotContainsString('00123456', DB::table('viva_imports')->where('id', $batch->id)->value('payload'));
        $this->login(UserRole::Admin);
        $this->get(route('viva.imports.preview', $batch->id))->assertNotFound();
        $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version]);
        $this->post(route('viva.imports.confirm', $batch->id), ['confirmation' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('viva_candidates', 2);
        $this->assertDatabaseCount('viva_boards', 1);
        $this->assertSame([], $batch->fresh()->payload);
        $this->post(route('viva.imports.confirm', $batch->id), ['confirmation' => 1])->assertGone();
        $batch = $this->stage($rows);
        $this->post(route('viva.imports.confirm', $batch->id), ['confirmation' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('viva_candidates', 2);
    }

    public function test_invalid_candidate_rows_and_formulas_block_all_writes(): void
    {
        $this->login(UserRole::Admin);
        $batch = $this->stage([$this->row(), $this->row(['reg' => '00123457', 'vdate' => '310226'])]);
        $this->assertCount(1, $batch->payload['plan']['errors']);
        $this->post(route('viva.imports.confirm', $batch->id), ['confirmation' => 1])->assertSessionHasErrors('import');
        $this->assertDatabaseCount('viva_candidates', 0);
        $this->post(route('viva.imports.stage'), ['file' => $this->upload([$this->row()], true)])->assertSessionHasErrors('file');
        $batch = $this->stage([$this->row(), $this->row()]);
        $this->assertCount(1, $batch->payload['plan']['errors']);
    }

    public function test_candidate_preview_expires_cancels_and_rechecks_database_changes(): void
    {
        $this->login(UserRole::Admin);
        $batch = $this->stage([$this->row()]);
        $batch->update(['expires_at' => now()->subMinute()]);
        $this->post(route('viva.imports.confirm', $batch->id), ['confirmation' => 1])->assertGone();
        $batch = $this->stage([$this->row()]);
        $this->board();
        $this->post(route('viva.imports.confirm', $batch->id), ['confirmation' => 1])->assertSessionHasErrors('import');
        $this->delete(route('viva.imports.cancel', $batch->id))->assertRedirect();
        $this->assertDatabaseMissing('viva_imports', ['id' => $batch->id]);
    }

    public function test_distribution_time_gate_uses_target_date_and_bangladesh_time_at_save(): void
    {
        $chair = $this->login();
        $member = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$member]);
        $board = $this->board();
        $data = $this->data($pool, $board, $member);
        $this->travelTo(Carbon::parse('2026-11-10 03:29:59', 'UTC'));
        $this->post(route('viva.boards.store'), $data)->assertSessionHasErrors('distribution');
        $this->assertDatabaseCount('viva_assignments', 0);
        $this->travelTo(Carbon::parse('2026-11-10 03:30:00', 'UTC'));
        $this->post(route('viva.boards.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('viva_assignments', 1);
    }

    public function test_specific_assignment_keeps_group_together_and_rejects_same_member_other_post_pool(): void
    {
        $this->login();
        $member = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$member]);
        $board = $this->board(regs: ['001', '002']);
        $other = $this->board(post: '250020', regs: ['003']);
        $this->open();
        $this->post(route('viva.boards.store'), $this->data($pool, $board, $member))->assertSessionHasNoErrors();
        $this->assertSame(2, $board->candidates()->count());
        $otherPool = $this->pool([$member]);
        $this->post(route('viva.boards.store'), $this->data($otherPool, $other, $member))->assertSessionHasErrors('distribution');
        $this->assertDatabaseCount('viva_assignments', 1);
        $this->assertDatabaseHas('viva_assignments', ['viva_board_id' => $board->id, 'user_id' => $member->id]);
    }

    public function test_random_assignment_is_one_to_one_and_rejects_insufficient_picks(): void
    {
        $chair = $this->login();
        $m = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$chair, $m]);
        $a = $this->board();
        $b = $this->board(group: '02', regs: ['009']);
        $this->open();
        $data = $this->data($pool, $a, $m, ['mode' => 'random', 'board_ids' => [$a->id, $b->id], 'member_ids' => [$m->id]]);
        $this->post(route('viva.boards.store'), $data)->assertSessionHasErrors('distribution');
        $this->assertDatabaseCount('viva_assignments', 0);
        $data['member_ids'] = [$chair->id, $m->id];
        $this->post(route('viva.boards.store'), $data)->assertSessionHasNoErrors();
        $this->assertSame(2, VivaAssignment::where('mode', 'random')->distinct('user_id')->count('user_id'));
    }

    public function test_wrong_date_out_of_pool_inactive_and_duplicate_mapping_fail_atomically(): void
    {
        $this->login();
        $m = User::factory()->create(['role' => 'member']);
        $outside = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$m]);
        $a = $this->board();
        $b = $this->board(group: '02', regs: ['009']);
        $this->open();
        $this->post(route('viva.boards.store'), $this->data($pool, $a, $outside))->assertSessionHasErrors('distribution');
        $this->post(route('viva.boards.store'), $this->data($pool, $a, $m, ['board_ids' => [$a->id, $b->id], 'mapping' => [$a->id => $m->id, $b->id => $m->id]]))->assertSessionHasErrors('distribution');
        $this->post(route('viva.boards.store'), $this->data($pool, $a, $m, ['vdate' => '2026-11-09']))->assertSessionHasErrors('distribution');
        $m->update(['is_active' => false]);
        $this->post(route('viva.boards.store'), $this->data($pool, $a, $m))->assertSessionHasErrors('distribution');
        $this->assertDatabaseCount('viva_assignments', 0);
    }

    public function test_assigned_candidate_data_cannot_change_or_be_deleted_and_corrections_are_audited(): void
    {
        $chair = $this->login();
        $m = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$m]);
        $board = $this->board();
        $this->open();
        $this->post(route('viva.boards.store'), $this->data($pool, $board, $m))->assertSessionHasNoErrors();
        $this->login(UserRole::Admin);
        $batch = $this->stage([$this->row(['reg' => '008'])]);
        $this->assertNotEmpty($batch->payload['plan']['errors']);
        $this->delete(route('viva.imports.destroy', $board), ['confirmation' => 'REMOVE'])->assertSessionHasErrors('board');
        $this->actingAs($chair)->withSession(['auth_version' => $chair->auth_version]);
        $a = VivaAssignment::firstOrFail();
        $this->delete(route('viva.assignments.destroy', $a), ['confirmation' => 'REMOVE', 'reason' => 'Correcting allocation'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('viva_assignments', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'viva.assignment.removed']);
    }

    public function test_display_credentials_are_independent_hashed_and_revoke_old_sessions(): void
    {
        $member = User::factory()->create(['role' => 'member', 'email' => 'display@example.test', 'password' => 'Display@Pass123']);
        $this->get(route('viva.display.index'))->assertRedirect(route('viva.display.login'));
        $this->post(route('viva.display.sign-in'), ['username' => $member->email, 'password' => 'Display@Pass123'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->displaySettings();
        $this->assertTrue(Hash::check('Display@Pass123', VivaSetting::find(1)->display_password));
        $this->post(route('viva.display.sign-in'), ['username' => 'noticeboard', 'password' => 'Display@Pass123'])->assertRedirect(route('viva.display.index'));
        $this->assertGuest();
        $this->get(route('viva.display.index'))->assertOk();
        VivaSetting::find(1)->increment('auth_version');
        $this->getJson(route('viva.display.feed'))->assertUnauthorized()->assertJsonMissingPath('boards');
    }

    public function test_live_display_protects_feed_preserves_zeroes_hides_private_fields_and_refreshes_member_location(): void
    {
        $chair = $this->login();
        $m = User::factory()->create(['role' => 'member', 'member_number' => 'M-02', 'floor' => '0', 'room_number' => '501', 'lift_button_number' => '5', 'name_of_po' => 'PO Officer']);
        $pool = $this->pool([$m]);
        $board = $this->board();
        $this->open();
        app(Distribution::class)->assign($this->data($pool, $board, $m), $chair);
        $this->getJson(route('viva.display.feed'))->assertUnauthorized();
        $this->displaySettings();
        $this->post(route('viva.display.sign-in'), ['username' => 'noticeboard', 'password' => 'Display@Pass123'])->assertSessionHasNoErrors();
        $feed = $this->getJson(route('viva.display.feed', ['vdate' => '2026-11-10']))->assertOk()->assertJsonPath('boards.0.registrations.0', '00123456')->assertJsonPath('boards.0.member.floor', '0');
        $feed->assertDontSee($m->email)->assertDontSee('Private Candidate Name')->assertJsonMissingPath('boards.0.member.password');
        $etag = $feed->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->getJson(route('viva.display.feed', ['vdate' => '2026-11-10']))->assertStatus(304);
        $m->update(['room_number' => '601']);
        $this->withHeader('If-None-Match', $etag)->getJson(route('viva.display.feed', ['vdate' => '2026-11-10']))->assertOk()->assertJsonPath('boards.0.member.room_number', '601');
    }

    public function test_display_login_works_even_if_application_user_is_inactive_and_logout_does_not_log_out_app(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->displaySettings();
        $this->post(route('viva.display.sign-in'), ['username' => 'noticeboard', 'password' => 'Display@Pass123'])->assertSessionHasNoErrors();
        $this->post(route('viva.display.logout'))->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        $admin->update(['is_active' => false]);
        $this->post(route('viva.display.sign-in'), ['username' => 'noticeboard', 'password' => 'Display@Pass123'])->assertSessionHasNoErrors();
        $this->get(route('viva.display.index'))->assertOk();
    }

    public function test_settings_custom_time_and_password_secret_handling(): void
    {
        $this->login(UserRole::Admin);
        $this->put(route('viva.settings.update'), ['start_time' => '10:15', 'display_username' => 'screen', 'display_password' => 'Display@Pass123', 'display_password_confirmation' => 'Different@Pass123'])->assertSessionHasErrors('display_password');
        $this->assertArrayNotHasKey('display_password', session()->getOldInput());
        $this->put(route('viva.settings.update'), ['start_time' => '10:15', 'display_username' => 'screen', 'display_password' => 'Display@Pass123', 'display_password_confirmation' => 'Display@Pass123'])->assertSessionHasNoErrors();
        $this->assertSame('10:15', VivaSetting::find(1)->start_time);
        $m = User::factory()->create(['role' => 'member']);
        $chair = $this->login();
        $pool = $this->pool([$m]);
        $board = $this->board();
        $this->open();
        $this->post(route('viva.boards.store'), $this->data($pool, $board, $m))->assertSessionHasErrors('distribution');
    }

    public function test_user_optional_details_save_and_distribution_report_preserves_identifiers(): void
    {
        $this->login(UserRole::Admin);
        $this->seed();
        $payload = ['name' => 'Board Member', 'email' => 'board@example.test', 'designation_id' => Designation::first()->id, 'unit' => Unit::first()->code, 'role' => 'member', 'is_active' => 1, 'password' => 'Strong@Pass123', 'password_confirmation' => 'Strong@Pass123', 'member_number' => '02', 'floor' => '0', 'room_number' => '501', 'lift_button_number' => '5', 'name_of_po' => 'PO Name'];
        $this->post(route('users.store'), $payload)->assertSessionHasNoErrors();
        $m = User::where('email', 'board@example.test')->firstOrFail();
        $this->assertSame('0', $m->floor);
        $chair = $this->login();
        $pool = $this->pool([$m]);
        $board = $this->board();
        $this->open();
        app(Distribution::class)->assign($this->data($pool, $board, $m), $chair);
        $response = $this->get(route('viva.report', ['vdate' => '2026-11-10']))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        $this->assertSame('00123456', $book->getSheetByName('Candidates')->getCell('G2')->getValue());
        $this->assertSame('01', $book->getSheetByName('Boards')->getCell('F2')->getValue());
        $this->assertSame('501', $book->getSheetByName('Boards')->getCell('J2')->getValue());
        $book->disconnectWorksheets();
        unlink($path);
    }

    public function test_database_enforces_member_day_uniqueness_even_without_service(): void
    {
        $chair = $this->login();
        $m = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$m]);
        $a = $this->board();
        $b = $this->board(post: '250020', regs: ['009']);
        $this->open();
        app(Distribution::class)->assign($this->data($pool, $a, $m), $chair);
        $this->expectException(QueryException::class);
        VivaAssignment::create(['vdate' => '2026-11-10', 'viva_board_id' => $b->id, 'user_id' => $m->id, 'viva_pool_id' => $pool->id, 'member_snapshot' => ['name' => $m->name], 'mode' => 'manual']);
    }

    public function test_same_member_can_receive_one_board_on_another_day_and_future_day_stays_closed(): void
    {
        $chair = $this->login();
        $m = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$m]);
        $today = $this->board();
        $past = $this->board(date: '2026-11-09', regs: ['009']);
        $future = $this->board(date: '2026-11-11', regs: ['010']);
        $this->open();
        app(Distribution::class)->assign($this->data($pool, $today, $m), $chair);
        app(Distribution::class)->assign($this->data($pool, $past, $m), $chair);
        $this->assertSame(2, VivaAssignment::where('user_id', $m->id)->count());
        $this->post(route('viva.boards.store'), $this->data($pool, $future, $m))->assertSessionHasErrors('distribution');
    }

    public function test_display_session_expires_and_login_attempts_are_throttled(): void
    {
        $this->displaySettings();
        $settings = VivaSetting::findOrFail(1);
        $this->withSession(['viva_display' => ['version' => $settings->auth_version, 'expires' => now()->subSecond()->timestamp]])->getJson(route('viva.display.feed'))->assertUnauthorized();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('viva.display.sign-in'), ['username' => 'bad', 'password' => 'bad'])->assertSessionHasErrors('username');
        }
        $this->post(route('viva.display.sign-in'), ['username' => 'bad', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_candidate_sample_download_is_importable(): void
    {
        $this->login(UserRole::Admin);
        $response = $this->get(route('viva.imports.sample'))->assertOk()->assertDownload('viva-candidates-sample.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();
        $rows = app(CandidateWorkbook::class)->read($path);
        $this->assertCount(3, $rows);
        $plan = app(CandidateWorkbook::class)->plan($rows);
        $this->assertSame([], $plan['errors']);
        $this->assertSame('00123456', $rows[0]['reg']);
        unlink($path);
    }

    public function test_old_user_workbook_preserves_optional_member_details_and_new_exports_include_them(): void
    {
        $this->login(UserRole::Admin);
        $this->seed();
        $m = User::factory()->create(['role' => 'member', 'designation_id' => Designation::first()->id, 'unit' => Unit::first()->code, 'member_number' => 'M-02', 'floor' => '0', 'room_number' => '501']);
        $workbooks = app(Workbook::class);
        $book = $workbooks->export(['Users'], false, false);
        $sheet = $book->getSheetByName('Users');
        $sheet->removeRow(2);
        $this->assertSame('M-02', $sheet->getCell('K2')->getValue());
        $sheet->removeColumn('K', 5);
        $path = tempnam(sys_get_temp_dir(), 'viva-legacy-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $rows = $workbooks->read($path, auth()->user());
        unlink($path);
        $planner = app(ImportPlan::class);
        $plan = $planner->build($rows, auth()->user());
        $this->assertSame([], $plan['errors']);
        DB::transaction(fn () => $planner->apply($plan, auth()->user()));
        $this->assertSame('M-02', $m->fresh()->member_number);
        $this->assertSame('0', $m->fresh()->floor);
    }

    public function test_distribution_rolls_back_if_a_late_assignment_write_fails(): void
    {
        $this->login();
        $aMember = User::factory()->create(['role' => 'member']);
        $bMember = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$aMember, $bMember]);
        $a = $this->board();
        $b = $this->board(group: '02', regs: ['009']);
        $this->open();
        $attempts = 0;
        VivaAssignment::creating(function () use (&$attempts) {
            if (++$attempts === 2) {
                throw ValidationException::withMessages(['distribution' => 'Simulated late write failure']);
            }
        });
        $data = $this->data($pool, $a, $aMember, ['board_ids' => [$a->id, $b->id], 'mapping' => [$a->id => $aMember->id, $b->id => $bMember->id]]);
        $this->post(route('viva.boards.store'), $data)->assertSessionHasErrors('distribution');
        $this->assertDatabaseCount('viva_assignments', 0);
    }

    public function test_candidate_confirm_rolls_back_partial_writes_on_failure(): void
    {
        $this->login(UserRole::Admin);
        $batch = $this->stage([$this->row()]);
        $workbook = \Mockery::mock(CandidateWorkbook::class)->makePartial();
        $workbook->shouldReceive('apply')->once()->andReturnUsing(function () {
            $this->board();
            throw ValidationException::withMessages(['import' => 'Simulated import failure']);
        });
        $this->app->instance(CandidateWorkbook::class, $workbook);
        $this->post(route('viva.imports.confirm', $batch->id), ['confirmation' => 1])->assertSessionHasErrors('import');
        $this->assertDatabaseCount('viva_candidates', 0);
        $this->assertDatabaseCount('viva_boards', 0);
        $this->assertSame('pending', $batch->fresh()->status);
    }

    public function test_pending_boards_can_be_distributed_later_than_opening_time_on_the_same_day(): void
    {
        $this->login();
        $firstMember = User::factory()->create(['role' => 'member']);
        $secondMember = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$firstMember, $secondMember]);
        $firstBoard = $this->board();
        $secondBoard = $this->board(group: '02', regs: ['009']);
        $this->travelTo(Carbon::parse('2026-11-10 10:15:00', 'Asia/Dhaka'));
        $this->get(route('viva.boards.index', ['vdate' => '2026-11-10', 'pool_id' => $pool->id]))
            ->assertOk()->assertSee('Distribution is open')->assertSee('09:30 AM')
            ->assertSee('Pending boards can be assigned at any time')->assertDontSee('The opening time has passed');
        $this->post(route('viva.boards.store'), $this->data($pool, $firstBoard, $firstMember))->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-11-10 16:45:00', 'Asia/Dhaka'));
        $this->post(route('viva.boards.store'), $this->data($pool, $secondBoard, $secondMember))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('viva_assignments', ['viva_board_id' => $firstBoard->id, 'user_id' => $firstMember->id]);
        $this->assertDatabaseHas('viva_assignments', ['viva_board_id' => $secondBoard->id, 'user_id' => $secondMember->id]);
    }

    public function test_pool_forms_and_assignment_lists_put_chairmen_first_then_numeric_member_numbers(): void
    {
        $chair = $this->login();
        $chair->update(['name' => 'Z Chairman']);
        $this->withSession(['auth_version' => $chair->fresh()->auth_version]);
        $otherChair = User::factory()->create(['role' => 'chairman', 'name' => 'A Chairman']);
        $ten = User::factory()->create(['role' => 'member', 'name' => 'Member Ten', 'member_number' => '10']);
        $two = User::factory()->create(['role' => 'member', 'name' => 'Beta Member', 'member_number' => '02']);
        $sameTwo = User::factory()->create(['role' => 'member', 'name' => 'Delta Member', 'member_number' => '2']);
        $three = User::factory()->create(['role' => 'member', 'name' => 'Member Three', 'member_number' => '3']);
        $missing = User::factory()->create(['role' => 'member', 'name' => 'Aaron Missing', 'member_number' => null]);
        $nonNumeric = User::factory()->create(['role' => 'member', 'name' => 'Zeta Prefix', 'member_number' => 'M-01']);
        $inactive = User::factory()->create(['role' => 'member', 'name' => 'Inactive', 'member_number' => '1', 'is_active' => false]);
        $staff = User::factory()->create(['role' => 'staff', 'name' => 'Staff', 'member_number' => '0']);
        $members = [$chair, $otherChair, $ten, $two, $sameTwo, $three, $missing, $nonNumeric, $inactive, $staff];
        $pool = $this->pool($members);
        $this->board();
        $this->open();
        $expected = array_map(fn ($user) => (string) $user->id, [$otherChair, $chair, $two, $sameTwo, $three, $ten, $missing, $nonNumeric]);
        foreach ([route('viva.pools.create'), route('viva.pools.edit', $pool)] as $url) {
            $response = $this->get($url)->assertOk();
            $dom = new \DOMDocument;
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            $ids = [];
            foreach ($xpath->query('//*[@data-pool-member]//input[@type="checkbox"]') as $input) {
                $ids[] = $input->getAttribute('value');
            }
            $this->assertSame($expected, $ids);
        }
        $response = $this->get(route('viva.boards.index', ['vdate' => '2026-11-10', 'pool_id' => $pool->id]))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $ids = [];
        foreach ($xpath->query('//*[@data-random-members]//input[@type="checkbox"]') as $input) {
            $ids[] = $input->getAttribute('value');
        }
        $this->assertSame($expected, $ids);
        $ids = [];
        foreach ($xpath->query('(//select[@data-board-mapping])[1]/option[@value!=""]') as $option) {
            $ids[] = $option->getAttribute('value');
        }
        $this->assertSame($expected, $ids);
        $this->assertSame('02', $two->fresh()->member_number);
    }

    public function test_numeric_member_order_handles_whitespace_zeroes_and_large_integer_text_without_rewriting_values(): void
    {
        $large = User::factory()->create(['role' => 'member', 'member_number' => '100000000000000000000000000000']);
        $smaller = User::factory()->create(['role' => 'member', 'member_number' => '99999999999999999999999999999']);
        $three = User::factory()->create(['role' => 'member', 'member_number' => ' 003 ']);
        $zero = User::factory()->create(['role' => 'member', 'member_number' => '000']);
        $ordered = app(Workspace::class)->orderedMembers(collect([$large, $smaller, $three, $zero]));
        $this->assertSame([$zero->id, $three->id, $smaller->id, $large->id], $ordered->pluck('id')->all());
        $this->assertSame(' 003 ', $three->fresh()->member_number);
        $this->assertSame('000', $zero->fresh()->member_number);
    }

    public function test_random_mode_is_first_and_default_and_opening_advice_is_chairman_only(): void
    {
        $chair = $this->login();
        $member = User::factory()->create(['role' => 'member']);
        $pool = $this->pool([$chair, $member]);
        $this->board();
        $this->open();
        $url = route('viva.boards.index', ['vdate' => '2026-11-10', 'pool_id' => $pool->id]);
        $response = $this->get($url)->assertOk()->assertSee('You do not need to assign all boards at the opening time.');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $modes = $xpath->query('//form[@data-viva-distribution]//input[@name="mode"]');
        $this->assertSame(2, $modes->length);
        $this->assertSame('random', $modes->item(0)->getAttribute('value'));
        $this->assertTrue($modes->item(0)->hasAttribute('checked'));
        $this->assertSame('manual', $modes->item(1)->getAttribute('value'));
        $this->assertFalse($modes->item(1)->hasAttribute('checked'));
        // A validation round trip keeps the user's explicit specific-assignment choice.
        $response = $this->withSession(['_old_input' => ['mode' => 'manual']])->get($url)->assertOk();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame('manual', $xpath->query('//input[@name="mode" and @checked]')->item(0)->getAttribute('value'));
        $this->withSession(['_old_input' => []]);
        $this->login(UserRole::Admin);
        $this->get($url)->assertOk()->assertDontSee('You do not need to assign all boards at the opening time.')->assertDontSee('Distribute Selected Boards');
        $this->post(route('viva.boards.store'))->assertForbidden();
    }

    public function test_optional_metadata_is_staged_validated_and_preserved_in_sample_and_display(): void
    {
        $this->login(UserRole::Admin);
        $batch = $this->stage([$this->row(['unit' => 'Unit 07', 'ministry' => 'Ministry of Education'])]);
        $this->get(route('viva.imports.preview', $batch))->assertOk()->assertSee('Unit 07')->assertSee('Ministry of Education');
        $this->post(route('viva.imports.confirm', $batch), ['confirmation' => 1])->assertSessionHasNoErrors();
        $board = VivaBoard::firstOrFail();
        $this->assertSame('Unit 07', $board->unit);
        $this->assertSame('Ministry of Education', $board->ministry);
        $plan = app(CandidateWorkbook::class)->plan([$this->row(['unit' => 'Different', '_row' => 2])]);
        $this->assertNotEmpty($plan['errors']);
        $blank = app(CandidateWorkbook::class)->plan([$this->row(['post_code' => '999', 'unit' => '', 'ministry' => '', '_row' => 2])]);
        $this->assertEmpty($blank['errors']);
        app(CandidateWorkbook::class)->apply($blank);
        $this->assertNull(VivaBoard::where('post_code', '999')->first()->unit);
        $book = app(CandidateWorkbook::class)->sample();
        $this->assertSame('unit', $book->getActiveSheet()->getCell('C1')->getValue());
        $this->assertSame('ministry', $book->getActiveSheet()->getCell('D1')->getValue());
        $this->assertSame('name', $book->getActiveSheet()->getCell('H1')->getValue());
        $book->disconnectWorksheets();
        $member = User::factory()->create(['role' => 'member']);
        VivaAssignment::create(['vdate' => '2026-11-10', 'viva_board_id' => $board->id, 'user_id' => $member->id, 'assigned_by' => auth()->id(), 'member_snapshot' => ['name' => $member->name], 'mode' => 'manual']);
        $display = app(DisplayData::class);
        $payload = $display->payload($display->assignments('2026-11-10'), '2026-11-10');
        $this->assertSame('Unit 07', $payload['boards'][0]['unit']);
        $this->assertSame('Ministry of Education', $payload['boards'][0]['ministry']);
    }

    public function test_member_can_only_view_download_and_print_their_own_board_even_with_forged_ids(): void
    {
        $member = $this->login(UserRole::Member);
        $other = User::factory()->create(['role' => 'member']);
        $mine = $this->board(regs: ['00000123']);
        $mine->update(['unit' => 'Unit 09', 'ministry' => 'Education']);
        $theirs = $this->board(group: '02', regs: ['99999999']);
        foreach ([[$mine, $member], [$theirs, $other]] as [$board,$user]) {
            VivaAssignment::create(['vdate' => '2026-11-10', 'viva_board_id' => $board->id, 'user_id' => $user->id, 'assigned_by' => $member->id, 'member_snapshot' => ['name' => $user->name], 'mode' => 'manual']);
        }
        $forged = ['vdate' => '2026-11-10', 'user_id' => $other->id, 'board_id' => $theirs->id, 'assignment_id' => VivaAssignment::where('user_id', $other->id)->value('id')];
        $this->get(route('dashboard'))->assertOk()->assertSee('Viva Board Distribution');
        $this->get(route('viva.my-board.index', $forged))->assertOk()->assertSee('00000123')->assertSee('Unit 09')->assertDontSee('99999999')->assertDontSee('Distribution Summary')->assertDontSee('Candidate Import');
        $this->get(route('viva.my-board.download', $forged + ['format' => 'print']))->assertOk()->assertSee('00000123')->assertDontSee('99999999');
        $response = $this->get(route('viva.my-board.download', $forged + ['format' => 'xlsx']))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        $sheet = $book->getActiveSheet();
        $this->assertSame('00000123', $sheet->getCell('G2')->getValue());
        $this->assertSame('Unit 09', $sheet->getCell('D2')->getValue());
        $this->assertSame(2, $sheet->getHighestRow());
        $book->disconnectWorksheets();
        unlink($path);
        $this->get(route('viva.my-board.download', $forged + ['format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('viva.my-board.index', ['vdate' => '2026-11-11', 'user_id' => $other->id]))->assertOk()->assertSee('No board assigned')->assertDontSee('99999999');
        $this->get(route('viva.my-board.download', ['vdate' => '2026-11-11', 'format' => 'xlsx']))->assertNotFound();
        foreach (['viva.boards.index', 'viva.report', 'viva.imports.index', 'viva.pools.index', 'viva.settings.edit'] as $route) {
            $this->get(route($route, $forged))->assertForbidden();
        }
        $this->get(route('viva.my-board.download', ['vdate' => 'invalid', 'format' => 'xlsx']))->assertSessionHasErrors('vdate');
        $this->login(UserRole::Staff);
        $this->get(route('viva.my-board.index'))->assertForbidden();
        $this->get(route('viva.my-board.download', ['vdate' => '2026-11-10', 'format' => 'pdf']))->assertForbidden();
    }

    public function test_my_board_print_includes_all_candidates_beyond_screen_pagination_and_pdf_has_multiple_pages(): void
    {
        $member = $this->login(UserRole::Member);
        $board = $this->board(regs: array_map(fn ($i) => str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 110)));
        VivaAssignment::create(['vdate' => '2026-11-10', 'viva_board_id' => $board->id, 'user_id' => $member->id, 'assigned_by' => $member->id, 'member_snapshot' => ['name' => $member->name], 'mode' => 'manual']);
        $this->get(route('viva.my-board.index', ['vdate' => '2026-11-10']))->assertOk()->assertSee('00000050')->assertDontSee('00000110');
        $this->get(route('viva.my-board.index', ['vdate' => '2026-11-10', 'page' => 3]))->assertOk()->assertSee('00000110');
        $this->get(route('viva.my-board.download', ['vdate' => '2026-11-10', 'format' => 'print']))->assertOk()->assertSee('00000110');
        $response = $this->get(route('viva.my-board.download', ['vdate' => '2026-11-10', 'format' => 'pdf']))->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $pages = preg_match_all('~/Type\s*/Page\b~', $response->getContent());
        $this->assertGreaterThan(1,$pages);
        $this->assertLessThanOrEqual(8,$pages);
    }
}
