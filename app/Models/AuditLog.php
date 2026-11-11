<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Services\Library\AuditFiles;
use App\Support\Ui;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'actor_name', 'actor_role', 'request_uuid', 'action', 'subject_type',
        'subject_id', 'channel', 'ip_address', 'user_agent', 'details', 'created_at',
    ];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime', 'actor_id' => 'integer', 'subject_id' => 'integer'];
    }

    public function actorRoleLabel(): string
    {
        return UserRole::tryFrom($this->actor_role ?? '')?->label() ?? $this->actor_role ?? __('Not recorded');
    }

    public function subjectLabel(): string
    {
        if ($this->subject_type) {
            $type = __(Str::headline($this->subject_type));

            return $this->subject_id ? $type.' #'.Ui::digits($this->subject_id) : $type;
        }

        $details = $this->details ?? [];
        $reference = match (true) {
            str_starts_with($this->action, 'viva.settings.'), str_starts_with($this->action, 'viva.timing.'),
            str_starts_with($this->action, 'viva.candidate-downloads.'), str_starts_with($this->action, 'viva.distribution-downloads.') => __('Viva Settings'),
            str_starts_with($this->action, 'viva.display.') => __('Viva Board Display'),
            isset($details['assignment_id']) => __('Viva Assignment').' #'.Ui::digits($details['assignment_id']),
            isset($details['board_id']) => __('Viva Board').' #'.Ui::digits($details['board_id']),
            isset($details['pool_id']) => __('Viva Pool').' #'.Ui::digits($details['pool_id']),
            isset($details['batch']) => __('Import Batch').' #'.$details['batch'],
            default => $details['route'] ?? $details['route_template'] ?? $details['command'] ?? $details['task'] ?? null,
        };

        if ($reference) {
            return $reference;
        }

        if (str_starts_with($this->action, 'file.update-note.')) {
            return $details['file'] ?? $details['from'] ?? __('Update Note');
        }

        if (in_array($this->action, ['auth.login', 'auth.logout'], true) && $this->actor_id) {
            return __('User').' #'.Ui::digits($this->actor_id);
        }

        return __('No specific subject');
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $event) {
            // Historical migrations seed designations before the audit upgrade runs.
            if ($event->channel === 'console' &&
                ! $event->getConnection()->getSchemaBuilder()->hasColumn('audit_logs', 'event_uuid')) {
                unset($event->actor_role, $event->request_uuid);

                return;
            }
            $event->event_uuid = (string) Str::uuid();
        });
        static::created(function (AuditLog $event) {
            if (! $event->event_uuid || ! config('audit.files_enabled')) {
                return;
            }

            $event = clone $event;
            $event->getConnection()->afterCommit(function () use ($event) {
                try {
                    app(AuditFiles::class)->store($event);
                } catch (\Throwable $exception) {
                    try {
                        Log::error('Audit file mirror failed. Run cmo:audit-sync to retry.', [
                            'audit_id' => $event->id,
                            'exception' => $exception::class,
                        ]);
                    } catch (\Throwable) {
                        error_log('Audit file mirror failed for audit ID '.$event->id);
                    }
                }
            });
        });
        static::updating(fn () => throw new \LogicException('Audit records cannot be changed.'));
        static::deleting(fn () => throw new \LogicException('Audit records cannot be deleted.'));
    }
}
