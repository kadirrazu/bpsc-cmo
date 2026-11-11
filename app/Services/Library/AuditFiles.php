<?php

namespace App\Services\Library;

use App\Models\AuditLog;
use RuntimeException;

class AuditFiles
{
    /** Publishes a complete file without replacing an existing event. */
    public function store(AuditLog $event): bool
    {
        $directory = rtrim(config('audit.path'), '/\\').DIRECTORY_SEPARATOR.$event->created_at->format('Y-m-d');
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the audit directory.');
        }
        $identity = $event->event_uuid ?: 'legacy-'.$event->id;
        if (! preg_match('/\A(?:[0-9a-f-]{36}|legacy-[0-9]+)\z/i', $identity)) {
            throw new RuntimeException('Invalid audit event identifier.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.$identity.'.json';
        $payload = json_encode($this->ordered([
            'id' => $event->id,
            'event_uuid' => $event->event_uuid,
            'request_uuid' => $event->request_uuid,
            'created_at' => $event->created_at->toIso8601String(),
            'actor_id' => $event->actor_id,
            'actor_name' => $event->actor_name,
            'actor_role' => $event->actor_role,
            'ip_address' => $event->ip_address,
            'user_agent' => $event->user_agent,
            'action' => $event->action,
            'channel' => $event->channel,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'details' => $event->details,
        ]), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        if (file_exists($path) || is_link($path)) {
            return $this->verifyExisting($path, $payload);
        }

        $temporary = tempnam($directory, '.audit-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot stage the audit file.');
        }
        try {
            if (file_put_contents($temporary, $payload) !== strlen($payload)) {
                throw new RuntimeException('Cannot write the complete audit file.');
            }
            // A hard link is an atomic create-if-absent on local NTFS and Unix filesystems.
            if (@link($temporary, $path)) {
                return true;
            }

            return $this->verifyExisting($path, $payload);
        } finally {
            @unlink($temporary);
        }
    }

    private function verifyExisting(string $path, string $payload): bool
    {
        if (! is_link($path) && is_file($path) && file_get_contents($path) === $payload) {
            return false;
        }

        throw new RuntimeException('Cannot publish audit file or its existing contents differ.');
    }

    private function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->ordered(...), $value);
    }
}
