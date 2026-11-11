<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Unit;
use App\Models\User;
use App\Services\Library\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuditHistoryTest extends TestCase
{
    use RefreshDatabase;

    private ?string $auditDirectory = null;

    protected function tearDown(): void
    {
        if ($this->auditDirectory !== null) {
            File::deleteDirectory($this->auditDirectory);
        }
        parent::tearDown();
    }

    public function test_audit_history_rejects_direct_database_update_delete_and_replace(): void
    {
        app(Audit::class)->record('test.retained', ['value' => 'original']);
        $event = AuditLog::latest('id')->firstOrFail();
        foreach ([
            fn () => DB::table('audit_logs')->where('id', $event->id)->update(['action' => 'tampered']),
            fn () => DB::table('audit_logs')->where('id', $event->id)->delete(),
            fn () => DB::insert('INSERT OR REPLACE INTO audit_logs (id, action, channel, created_at) VALUES (?, ?, ?, ?)', [$event->id, 'tampered', 'console', now()]),
        ] as $write) {
            try {
                $write();
                $this->fail('A direct database mutation bypassed audit protection.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Audit logs are append-only', $exception->getMessage());
            }
        }
        $this->assertSame('test.retained', $event->fresh()->action);
        $this->assertSame(['value' => 'original'], $event->fresh()->details);
    }

    public function test_committed_records_have_matching_files_and_rolled_back_records_have_none(): void
    {
        $this->enableFiles();
        DB::beginTransaction();
        app(Audit::class)->record('test.rolled-back');
        $discarded = AuditLog::latest('id')->firstOrFail();
        $this->assertFileDoesNotExist($this->path($discarded));
        DB::rollBack();
        $this->assertDatabaseMissing('audit_logs', ['event_uuid' => $discarded->event_uuid]);
        $this->assertFileDoesNotExist($this->path($discarded));

        $event = DB::transaction(function () {
            app(Audit::class)->record('test.committed', ['nested' => ['password' => 'secret-value', 'value' => 'allowed']]);
            $event = AuditLog::latest('id')->firstOrFail();
            $this->assertFileDoesNotExist($this->path($event));

            return $event;
        });
        $this->assertFileExists($this->path($event));
        $payload = json_decode(file_get_contents($this->path($event)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($event->event_uuid, $payload['event_uuid']);
        $this->assertSame(['nested' => ['value' => 'allowed']], $payload['details']);
        $this->assertStringNotContainsString('secret-value', $event->getRawOriginal('details'));
    }

    public function test_sync_recovers_missing_copy_and_detects_a_conflict_without_overwriting_it(): void
    {
        $this->enableFiles();
        config(['audit.files_enabled' => false]);
        app(Audit::class)->record('test.backfill');
        $event = AuditLog::latest('id')->firstOrFail();
        $this->artisan('cmo:audit-sync', ['--after' => $event->id - 1])->assertSuccessful();
        $path = $this->path($event);
        $this->assertFileExists($path);
        $this->artisan('cmo:audit-sync', ['--after' => $event->id - 1])->assertSuccessful();
        file_put_contents($path, 'outside-app modification');
        $this->artisan('cmo:audit-sync', ['--after' => $event->id - 1])->assertFailed();
        $this->assertSame('outside-app modification', file_get_contents($path));
        $this->assertSame('test.backfill', $event->fresh()->action);
    }

    public function test_web_audit_correlates_actor_role_ip_business_change_and_file_metadata(): void
    {
        $this->enableFiles();
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Audit Administrator']);
        $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.24'])
            ->withHeaders(['X-Forwarded-For' => '192.0.2.77']);
        $response = $this->post(route('units.store'), ['name' => 'Audit Unit', 'sort_order' => 1, 'is_active' => 1])->assertRedirect();
        $id = $response->headers->get('X-Audit-Request-ID');
        $this->assertNotEmpty($id);
        $change = AuditLog::where('request_uuid', $id)->where('action', 'unit.created')->firstOrFail();
        $request = AuditLog::where('request_uuid', $id)->where('action', 'request.action')->firstOrFail();
        $this->assertSame('Audit Unit', $change->details['after']['name']);
        foreach ([$change, $request] as $event) {
            $this->assertSame($admin->id, $event->actor_id);
            $this->assertSame('Audit Administrator', $event->actor_name);
            $this->assertSame('admin', $event->actor_role);
            $this->assertSame('203.0.113.24', $event->ip_address);
            $this->assertFileExists($this->path($event));
        }

        $upload = $this->post(route('transfers.stage'), ['file' => UploadedFile::fake()->createWithContent('bad.xlsx', 'private-fixture-content')]);
        $upload->assertSessionHasErrors('file');
        $entry = AuditLog::where('action', 'request.denied')->latest('id')->firstOrFail();
        $this->assertSame('bad.xlsx', $entry->details['uploads'][0]['name']);
        $this->assertStringNotContainsString('private-fixture-content', $entry->getRawOriginal('details'));

        $download = $this->get(route('viva.imports.sample'))->assertOk();
        $entry = AuditLog::where('request_uuid', $download->headers->get('X-Audit-Request-ID'))->firstOrFail();
        $this->assertSame('prepared', $entry->details['download']['outcome']);
        $this->assertGreaterThan(0, $entry->details['download']['bytes']);
        $this->assertFileExists($this->path($entry));
    }

    public function test_audit_list_shows_actor_and_subject_without_changing_historical_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Audit Administrator']);
        $unit = Unit::create(['code' => 'audit-example', 'name' => 'Audit Example']);
        $legacy = AuditLog::create([
            'action' => 'request.view', 'channel' => 'web', 'created_at' => now(),
            'actor_id' => $admin->id, 'actor_name' => 'Recorded Name',
            'details' => ['route' => 'dashboard'],
        ]);

        $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version]);
        $response = $this->get(route('units.edit', $unit))->assertOk();
        $requestEvent = AuditLog::where('request_uuid', $response->headers->get('X-Audit-Request-ID'))->firstOrFail();
        $this->assertSame('Unit', $requestEvent->subject_type);
        $this->assertSame($unit->id, $requestEvent->subject_id);

        $page = $this->get(route('audit-logs.index'))->assertOk();
        $page->assertSeeText('(User ID: '.$admin->id.', Role: Administrator)')
            ->assertSeeText('Unit #'.$unit->id)
            ->assertSeeText('(User ID: '.$admin->id.', Role: Not recorded)')
            ->assertSeeText('dashboard')
            ->assertSeeText('BPSC CMO — Chairman’s Office');
        $this->assertNull($legacy->fresh()->subject_type);
        $this->assertNull($legacy->fresh()->actor_role);
    }

    public function test_no_application_role_has_an_audit_delete_route(): void
    {
        app(Audit::class)->record('test.keep');
        $event = AuditLog::latest('id')->firstOrFail();
        foreach (['admin', 'chairman', 'member', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
            $this->delete(route('audit-logs.index'))->assertStatus(405);
            $this->assertModelExists($event);
        }
    }

    private function enableFiles(): void
    {
        $this->auditDirectory = sys_get_temp_dir().'/cmo-audit-test-'.bin2hex(random_bytes(8));
        config(['audit.path' => $this->auditDirectory, 'audit.files_enabled' => true]);
    }

    private function path(AuditLog $event): string
    {
        return $this->auditDirectory.'/'.$event->created_at->format('Y-m-d').'/'.$event->event_uuid.'.json';
    }
}
