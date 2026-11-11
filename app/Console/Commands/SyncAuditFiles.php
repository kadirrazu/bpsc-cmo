<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Library\AuditFiles;
use Illuminate\Console\Command;

class SyncAuditFiles extends Command
{
    protected $signature = 'cmo:audit-sync {--after=0 : Start after this audit ID}';

    protected $description = 'Create missing audit files and check existing copies against the database';

    public function handle(AuditFiles $files): int
    {
        $after = (string) $this->option('after');
        if (! ctype_digit($after)) {
            $this->error('Provide a nonnegative audit ID.');

            return self::INVALID;
        }

        $written = 0;
        $existing = 0;
        $failed = 0;
        foreach (AuditLog::where('id', '>', $after)->lazyById(200) as $event) {
            try {
                if ($files->store($event)) {
                    $written++;
                } else {
                    $existing++;
                }
            } catch (\Throwable) {
                $failed++;
                $this->error('Could not verify or publish audit ID '.$event->id);
            }
        }

        $this->info("Audit files written {$written}, verified {$existing}, failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
