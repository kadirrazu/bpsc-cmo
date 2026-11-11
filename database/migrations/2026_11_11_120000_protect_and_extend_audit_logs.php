<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException('Audit protection requires MySQL, MariaDB or SQLite.');
        }

        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'audit_logs_block_'.strtolower($operation);
            if ($driver !== 'sqlite' && DB::table('information_schema.TRIGGERS')
                ->whereRaw('TRIGGER_SCHEMA = DATABASE()')->where('TRIGGER_NAME', $name)->exists()) {
                continue;
            }
            $body = $driver === 'sqlite'
                ? "BEGIN SELECT RAISE(ABORT, 'Audit logs are append-only'); END"
                : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only'";
            $ifMissing = $driver === 'sqlite' ? 'IF NOT EXISTS ' : '';
            DB::unprepared("CREATE TRIGGER {$ifMissing}{$name} BEFORE {$operation} ON audit_logs FOR EACH ROW {$body}");
        }

        $columns = Schema::getColumnListing('audit_logs');
        Schema::table('audit_logs', function (Blueprint $table) use ($columns) {
            if (! in_array('event_uuid', $columns, true)) {
                $table->uuid('event_uuid')->nullable();
            }
            if (! in_array('request_uuid', $columns, true)) {
                $table->uuid('request_uuid')->nullable();
            }
            if (! in_array('actor_role', $columns, true)) {
                $table->string('actor_role', 32)->nullable();
            }
        });

        $eventIndex = Schema::hasIndex('audit_logs', 'audit_logs_event_uuid_unique');
        $requestIndex = Schema::hasIndex('audit_logs', 'audit_logs_request_uuid_index');
        Schema::table('audit_logs', function (Blueprint $table) use ($eventIndex, $requestIndex) {
            if (! $eventIndex) {
                $table->unique('event_uuid');
            }
            if (! $requestIndex) {
                $table->index('request_uuid');
            }
        });

        // SQLite REPLACE can remove the conflicting row without firing a DELETE trigger.
        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS audit_logs_block_replace BEFORE INSERT ON audit_logs
                WHEN EXISTS (SELECT 1 FROM audit_logs WHERE id = NEW.id OR event_uuid = NEW.event_uuid)
                BEGIN SELECT RAISE(ABORT, 'Audit logs are append-only'); END
                SQL);
        }
    }

    public function down(): void
    {
        throw new LogicException('Rolling back this migration would remove audit history protection.');
    }
};
