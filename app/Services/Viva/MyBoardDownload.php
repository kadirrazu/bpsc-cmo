<?php

namespace App\Services\Viva;

use App\Models\VivaAssignment;
use App\Services\Library\Audit;

class MyBoardDownload
{
    public function response(VivaAssignment $assignment, string $date, string $format)
    {
        abort_unless($format === 'pdf' && auth()->user()?->can('view-own-viva-board') && (string) $assignment->user_id === (string) auth()->id() && $assignment->vdate->toDateString() === $date, 403, 'Only your own assigned board can be downloaded.');
        $board = $assignment->board;
        $board->setRelation('candidates', $board->candidates()->get()->sort(function ($a, $b) {
            $left = ltrim($a->reg, '0');
            $right = ltrim($b->reg, '0');

            return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right) ?: strcmp($a->reg, $b->reg) ?: ($a->id <=> $b->id);
        })->values());
        $member = app(Workspace::class)->member(auth()->user());
        app(Audit::class)->record('viva.my-board.downloaded', ['assignment_id' => $assignment->id, 'format' => 'pdf']);
        $content = app(CandidateReport::class)->pdf(collect([$board]), $member);
        $filename = 'my-viva-board-'.$date.'-'.$board->post_code.'-'.$board->board.'.pdf';

        return response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }
}
