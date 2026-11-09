<?php

namespace App\Services\Viva;

use App\Models\VivaAssignment;

class DisplayData
{
    public function assignments(string $date)
    {
        return VivaAssignment::with(['board' => fn ($q) => $q->withCount('candidates'), 'member'])->where('vdate', $date)->orderBy('viva_board_id')->get();
    }

    public function version($assignments, string $date): string
    {
        return '"'.hash('sha256', $date.'|'.$assignments->toJson()).'"';
    }

    public function payload($assignments, string $date): array
    {
        $boards = [];
        foreach ($assignments as $a) {
            $member = $a->member ? app(Workspace::class)->member($a->member) : $a->member_snapshot;
            $boards[] = ['post_code' => $a->board->post_code, 'post_name' => $a->board->post_name, 'board' => $a->board->board, 'member' => $member, 'candidate_count' => $a->board->candidates_count, 'registrations' => $a->board->candidates()->orderBy('reg')->pluck('reg')->all()];
        }
        usort($boards, fn ($a, $b) => strnatcmp($a['post_code'].'-'.$a['board'], $b['post_code'].'-'.$b['board']));

        return ['date' => $date, 'generated_at' => now('Asia/Dhaka')->format('d M Y, h:i:s A').' BST', 'boards' => $boards];
    }
}
