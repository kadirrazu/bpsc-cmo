<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VivaAssignment;
use App\Models\VivaBoard;
use App\Models\VivaCandidate;
use App\Models\VivaSetting;
use App\Services\Viva\CandidateReport;
use App\Services\Viva\MyBoardDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VivaMemberPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_pdf_works_with_both_release_switches_off_and_preserves_registration_order(): void
    {
        $member = User::factory()->create(['role' => 'member', 'room_number' => '501']);
        $this->actingAs($member)->withSession(['auth_version' => $member->auth_version]);
        $this->assertFalse(VivaSetting::first()->candidate_downloads_enabled);
        $this->assertFalse(VivaSetting::first()->distribution_downloads_enabled);
        $board = VivaBoard::create(['vdate' => '2026-11-10', 'post_code' => '001', 'post_name' => 'Post', 'board' => '01']);
        foreach (['0001000', '0010', '0002'] as $reg) {
            VivaCandidate::create(['vdate' => '2026-11-10', 'post_code' => '001', 'viva_board_id' => $board->id, 'reg' => $reg]);
        }
        VivaAssignment::create(['vdate' => '2026-11-10', 'viva_board_id' => $board->id, 'user_id' => $member->id, 'member_snapshot' => ['name' => $member->name], 'mode' => 'manual']);
        $this->get(route('viva.my-board.index', ['vdate' => '2026-11-10']))->assertOk()->assertSee('Download Candidate List (PDF)')->assertDontSee('Excel (.xlsx)')->assertDontSee('Print Candidate List');
        $this->mock(CandidateReport::class)->shouldReceive('pdf')->once()->withArgs(function ($boards, $metadata) use ($board, $member) {
            $this->assertCount(1, $boards);
            $this->assertSame($board->id, $boards->first()->id);
            $this->assertSame(['0002', '0010', '0001000'], $boards->first()->candidates->pluck('reg')->all());
            $this->assertSame($member->name, $metadata['name']);
            $this->assertSame('501', $metadata['room_number']);

            return true;
        })->andReturn('%PDF-member-test');
        $this->get(route('viva.my-board.download', ['vdate' => '2026-11-10', 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('viva.my-board.index', ['vdate' => '2026-11-11']))->assertOk()->assertDontSee('Download Candidate List (PDF)');
        $this->get(route('viva.my-board.download', ['vdate' => '2026-11-11', 'format' => 'pdf']))->assertNotFound();
    }

    public function test_other_roles_cannot_use_member_pdf_endpoint(): void
    {
        foreach (['admin', 'chairman', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
            $this->get(route('viva.my-board.download', ['vdate' => '2026-11-10', 'format' => 'pdf']))->assertForbidden();
        }
    }

    public function test_pdf_service_defensively_rejects_another_members_assignment(): void
    {
        $member = User::factory()->create(['role' => 'member']);
        $other = User::factory()->create(['role' => 'member']);
        $this->actingAs($member);
        $board = VivaBoard::create(['vdate' => '2026-11-10', 'post_code' => '001', 'post_name' => 'Post', 'board' => '01']);
        $assignment = VivaAssignment::create(['vdate' => '2026-11-10', 'viva_board_id' => $board->id, 'user_id' => $other->id, 'member_snapshot' => ['name' => $other->name], 'mode' => 'manual']);
        $this->expectException(HttpException::class);
        app(MyBoardDownload::class)->response($assignment,'2026-11-10','pdf');
    }
}
