<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VivaBoard;
use App\Models\VivaCandidate;
use App\Models\VivaSetting;
use App\Services\Viva\CandidateReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VivaCandidateReportTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role = 'admin'): void
    {
        VivaSetting::findOrFail(1)->update(['candidate_downloads_enabled' => true]);
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
    }

    private function board(string $post, string $group, array $regs): VivaBoard
    {
        $board = VivaBoard::create(['vdate' => '2026-11-10', 'post_code' => $post, 'post_name' => 'Post '.$post, 'unit' => 'Unit 07', 'ministry' => 'Ministry of Education', 'board' => $group]);
        foreach ($regs as $reg) {
            VivaCandidate::create(['viva_board_id' => $board->id, 'vdate' => '2026-11-10', 'post_code' => $post, 'reg' => $reg, 'name' => 'Candidate '.$reg]);
        }

        return $board;
    }

    public function test_administrator_gets_unassigned_board_reports_with_exact_numeric_identifier_order(): void
    {
        $this->login();
        $long = '9999999999999999999999999999999999999999';
        $this->board('001', '01', ['0100', $long, '0002', '0010']);
        $this->board('002', '02', ['777777']);
        $this->get(route('viva.imports.index', ['vdate' => '2026-11-10']))->assertOk()->assertSee('Download Board Lists (PDF)')->assertDontSee('Print Board Lists');
        $this->mock(CandidateReport::class)->shouldReceive('pdf')->once()->withArgs(function ($boards) use ($long) {
            $this->assertCount(1, $boards);
            $this->assertSame('Unit 07', $boards->first()->unit);
            $this->assertSame('Ministry of Education', $boards->first()->ministry);
            $this->assertSame(['0002', '0010', '0100', $long], $boards->first()->candidates->pluck('reg')->all());

            return true;
        })->andReturn('%PDF-sorted-test');
        $this->get(route('viva.imports.report', ['vdate' => '2026-11-10', 'post_code' => '001', 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseCount('viva_assignments', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'viva.candidates.report.exported']);
        $this->get(route('viva.imports.index', ['vdate' => '2026-11-10', 'post_code' => '001']))->assertOk()->assertSee('Post 001')->assertDontSee('<strong>002</strong>', false);
    }

    public function test_pdf_starts_each_board_on_a_separate_page_and_filters_one_board(): void
    {
        $this->login();
        $a = $this->board('001', '01', ['0002']);
        $this->board('001', '02', ['0010']);
        $response = $this->get(route('viva.imports.report', ['vdate' => '2026-11-10', 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame(2, preg_match_all('~/Type\s*/Page\b~', $response->getContent()));
        $this->mock(CandidateReport::class)->shouldReceive('pdf')->once()->withArgs(function ($boards) use ($a) {
            $this->assertCount(1, $boards);
            $this->assertSame($a->id, $boards->first()->id);
            $this->assertSame(['0002'], $boards->first()->candidates->pluck('reg')->all());

            return true;
        })->andReturn('%PDF-filter-test');
        $this->get(route('viva.imports.report', ['vdate' => '2026-11-10', 'board_id' => $a->id, 'format' => 'pdf']))->assertOk();
    }

    public function test_chairman_member_staff_and_guests_cannot_download_or_print_uploaded_candidate_reports(): void
    {
        foreach (['chairman', 'member', 'staff'] as $role) {
            $this->login($role);
            foreach (['pdf', 'print'] as $format) {
                $this->get(route('viva.imports.report', ['vdate' => '2026-11-10', 'format' => $format]))->assertForbidden();
            }
        }
        auth()->forgetGuards();
        $this->get(route('viva.imports.report', ['vdate' => '2026-11-10', 'format' => 'pdf']))->assertRedirect(route('login'));
    }

    public function test_invalid_or_empty_filters_do_not_generate_an_unscoped_report(): void
    {
        $this->login();
        $a = $this->board('001', '01', ['0002']);
        $this->get(route('viva.imports.report', ['vdate' => 'invalid', 'format' => 'pdf']))->assertSessionHasErrors('vdate');
        $this->get(route('viva.imports.report', ['vdate' => '2026-11-10', 'format' => 'csv']))->assertSessionHasErrors('format');
        $this->get(route('viva.imports.report', ['vdate' => '2026-11-11', 'board_id' => $a->id, 'format' => 'pdf']))->assertNotFound();
        $this->get(route('viva.imports.report', ['vdate' => '2026-11-10', 'post_code' => '999', 'format' => 'pdf']))->assertNotFound();
    }
}
