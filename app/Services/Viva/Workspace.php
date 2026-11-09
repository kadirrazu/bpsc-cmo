<?php

namespace App\Services\Viva;

use App\Models\User;
use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaCandidate;
use App\Models\VivaSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Workspace
{
    public const DETAILS = ['member_number' => 'Member Number', 'floor' => 'Floor', 'room_number' => 'Room Number', 'lift_button_number' => 'Lift Button Number', 'name_of_po' => 'Name of PO'];

    public function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    public function date(?string $value): string
    {
        return $value ?: $this->today();
    }

    public function eligible()
    {
        return User::with('designation')->where('is_active', true)->whereIn('role', ['member', 'chairman'])->orderBy('name')->orderBy('id');
    }

    public function member(User $user): array
    {
        return $user->only(array_merge(['name'], array_keys(self::DETAILS)));
    }

    public function lockDay(string $date): void
    {
        DB::table('viva_days')->insertOrIgnore(['vdate' => $date]);
        DB::table('viva_days')->where('vdate', $date)->lockForUpdate()->first();
    }

    public function opens(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.VivaSetting::findOrFail(1)->start_time, 'Asia/Dhaka');
    }

    public function ensureOpen(string $date): void
    {
        $settings = VivaSetting::whereKey(1)->lockForUpdate()->firstOrFail();
        $opens = CarbonImmutable::parse($date.' '.$settings->start_time, 'Asia/Dhaka');
        if (CarbonImmutable::now('Asia/Dhaka')->lessThan($opens)) {
            throw ValidationException::withMessages(['distribution' => 'Distribution opens on '.$opens->format('d M Y, h:i A').' Bangladesh time.']);
        }
    }

    public function version(string $date): string
    {
        return hash('sha256', json_encode([
            VivaBoard::where('vdate', $date)->orderBy('id')->get()->toArray(),
            VivaCandidate::where('vdate', $date)->orderBy('id')->get()->toArray(),
            VivaAssignment::where('vdate', $date)->orderBy('id')->get()->toArray(),
        ]));
    }
}
