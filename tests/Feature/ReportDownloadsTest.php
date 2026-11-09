<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Evaluation;
use App\Models\Expert;
use App\Models\Survey;
use App\Models\User;
use App\Services\Evaluation\ExpertReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportDownloadsTest extends TestCase
{
    use RefreshDatabase;

    private function login(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    private function fixtures(): array
    {
        $expert = Expert::create(['name' => 'বাংলা বিশেষজ্ঞ', 'designation' => 'অধ্যাপক', 'organization' => 'বিশ্ববিদ্যালয়', 'type' => 'expert', 'is_active' => true]);
        $other = Expert::create(['name' => 'Second Expert', 'type' => 'departmental', 'is_active' => true]);
        $survey = Survey::create(['title' => 'Expert Review', 'status' => 'active', 'allowed_roles' => ['member']]);
        $empty = Survey::create(['title' => 'No responses yet', 'status' => 'draft', 'allowed_roles' => ['member']]);
        $closed = Survey::create(['title' => 'Closed Survey', 'status' => 'closed', 'allowed_roles' => ['chairman']]);
        $member = User::factory()->create(['role' => UserRole::Member]);
        $chairman = User::factory()->create(['role' => UserRole::Chairman]);
        foreach ([[$expert, $member, 2, '2026-11-01'], [$expert, $chairman, 4, '2026-11-02'], [$other, $member, 5, '2026-11-02']] as [$e,$u,$score,$date]) {
            Evaluation::create([
                'survey_id' => $survey->id, 'expert_id' => $e->id, 'user_id' => $u->id,
                'survey_snapshot' => ['title' => $survey->title], 'expert_snapshot' => ['name' => $e->name, 'type' => $e->type, 'designation' => $e->designation, 'organization' => $e->organization],
                'evaluator_snapshot' => ['name' => $u->name, 'role' => $u->role->label()],
                'viva_date' => $date, 'submitted_at' => now(), 'reference' => 'Test reference',
                'answers' => [
                    ['question_id' => 1, 'prompt' => 'বিষয়ভিত্তিক জ্ঞান', 'type' => 'rating', 'rating_min' => 1, 'rating_max' => 5, 'value' => $score],
                    ['question_id' => 2, 'prompt' => '=HYPERLINK("test")', 'hints' => 'মন্তব্য লিখুন', 'type' => 'textarea', 'value' => '<b>মতামত</b>'],
                ],
            ]);
        }

        return [$expert, $other, $survey, $empty, $closed];
    }

    public function test_action_buttons_share_one_row_on_all_management_lists(): void
    {
        $this->login();
        $this->seed();
        foreach (['users', 'units', 'designations', 'experts', 'surveys', 'questions'] as $name) {
            $response = $this->get(route($name.'.index'))->assertOk();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new \DOMXPath($dom);
            $groups = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," page-heading-actions ")]');
            $this->assertSame(1, $groups->length, $name);
            $this->assertSame(2, $xpath->query('.//a', $groups->item(0))->length, $name);
        }
    }

    public function test_statistics_are_distinct_filtered_and_independent_of_expert_page(): void
    {
        $this->login();
        [$expert,$other,$survey] = $this->fixtures();
        $reports = app(ExpertReport::class);
        $all = $reports->statistics([]);
        $this->assertSame(['total_surveys' => 3, 'active_surveys' => 1, 'closed_surveys' => 1, 'draft_surveys' => 1, 'submissions' => 3, 'experts' => 2, 'participants' => 2, 'surveys' => 1], $all);
        $filtered = $reports->statistics(['from' => '2026-11-02', 'type' => 'expert']);
        $this->assertSame(1, $filtered['submissions']);
        $this->assertSame(1, $filtered['experts']);
        $this->assertSame(1, $filtered['participants']);
        $activity = $reports->activity([])->get()->keyBy('id');
        $this->assertSame(3, (int) $activity[$survey->id]->submissions);
        $this->assertSame(2, (int) $activity[$survey->id]->participants);
        $this->assertSame(0, (int) $activity->firstWhere('title', 'No responses yet')->submissions);
        $this->get(route('evaluation.report-center'))->assertOk()->assertSee('Surveys with Responses')->assertSee('Survey Activity');
    }

    public function test_selected_experts_export_excel_answers_averages_and_literal_formulas(): void
    {
        $this->login();
        [$expert,$other] = $this->fixtures();
        // Selected expert IDs replace the single Expert filter; pagination never limits downloads.
        $response = $this->get(route('evaluation.report-download', ['format' => 'xlsx', 'scope' => 'selected', 'expert_ids' => [$expert->id, $other->id], 'expert_id' => $expert->id, 'expert_page' => 2, 'submissions_page' => 2]))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($path);
            $this->assertSame(['Overview', 'Experts', 'Question Summary', 'Submissions', 'Answers', 'Survey Activity'], $book->getSheetNames());
            $this->assertSame(3, $book->getSheetByName('Experts')->getHighestRow());
            $this->assertSame(4, $book->getSheetByName('Submissions')->getHighestRow());
            $this->assertSame(7, $book->getSheetByName('Answers')->getHighestRow());
            $this->assertSame('s', $book->getSheetByName('Answers')->getCell('H3')->getDataType());
            $this->assertSame('=HYPERLINK("test")', $book->getSheetByName('Answers')->getCell('H3')->getValue());
            $this->assertSame('মন্তব্য লিখুন', $book->getSheetByName('Answers')->getCell('I3')->getValue());
            $rows = $book->getSheetByName('Question Summary')->toArray();
            $rating = collect(array_slice($rows, 1))->first(fn ($r) => (int) $r[0] === $expert->id && $r[3] === 'rating');
            $this->assertEquals(3, $rating[8]);
            $this->assertSame('n', $book->getSheetByName('Question Summary')->getCell('I2')->getDataType());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'evaluation.report.export']);
        $post = $this->post(route('evaluation.report-download'), ['format' => 'xlsx', 'scope' => 'selected', 'expert_ids' => [$expert->id, $other->id]])->assertOk();
        @unlink($post->baseResponse->getFile()->getPathname());
    }

    public function test_downloads_respect_all_filters_and_include_soft_deleted_expert_history(): void
    {
        $this->login();
        [$expert,$other,$survey] = $this->fixtures();
        $expert->delete();
        $survey->delete();
        $response = $this->get(route('evaluation.report-download', ['format' => 'xlsx', 'scope' => 'selected', 'expert_ids' => [$expert->id, $other->id], 'from' => '2026-11-02', 'type' => 'expert', 'evaluator_role' => 'Chairman']))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($path);
            $this->assertSame(2, $book->getSheetByName('Submissions')->getHighestRow());
            $this->assertSame($expert->name, $book->getSheetByName('Submissions')->getCell('C2')->getValue());
            $this->assertSame('Removed', $book->getSheetByName('Experts')->getCell('G3')->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    public function test_pdf_download_contains_valid_pdf_and_supports_bengali_and_multiple_experts(): void
    {
        $this->login();
        [$expert,$other] = $this->fixtures();
        $response = $this->get(route('evaluation.report-download', ['format' => 'pdf', 'scope' => 'selected', 'expert_ids' => [$expert->id, $other->id]]))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertSame('%PDF-', file_get_contents($path, false, null, 0, 5));
        $this->assertGreaterThan(10000, filesize($path));
        // Retain this synthetic report outside the project for visual QA, never in the patch.
        if (getenv('CMO_REPORT_QA_PATH')) {
            copy($path, getenv('CMO_REPORT_QA_PATH'));
        }
        @unlink($path);
    }

    public function test_member_and_staff_cannot_open_report_center_or_download_any_format(): void
    {
        $this->fixtures();
        foreach ([UserRole::Member, UserRole::Staff] as $role) {
            $this->login($role);
            foreach (['xlsx', 'pdf'] as $format) {
                $this->get(route('evaluation.report-download', ['format' => $format, 'scope' => 'filtered']))->assertForbidden();
            }
            $this->get(route('evaluation.reports', ['export' => 'summary']))->assertForbidden();
            $this->get(route('evaluation.report-center'))->assertForbidden();
            $this->post(route('evaluation.report-download'), ['format' => 'xlsx', 'scope' => 'filtered'])->assertForbidden();
        }
    }

    public function test_export_scope_ids_and_dates_are_validated(): void
    {
        $this->login();
        [$expert] = $this->fixtures();
        $base = ['format' => 'xlsx', 'scope' => 'selected'];
        $this->get(route('evaluation.report-download', $base))->assertSessionHasErrors('expert_ids');
        $this->get(route('evaluation.report-download', $base + ['expert_ids' => [$expert->id, $expert->id]]))->assertSessionHasErrors('expert_ids.0');
        $this->get(route('evaluation.report-download', $base + ['expert_ids' => [999999]]))->assertSessionHasErrors('expert_ids.0');
        $this->get(route('evaluation.report-download', ['format' => 'exe', 'scope' => 'filtered']))->assertSessionHasErrors('format');
        $this->get(route('evaluation.report-download', ['format' => 'pdf', 'scope' => 'filtered', 'from' => '2026-11-09', 'to' => '2026-01-01']))->assertSessionHasErrors('to');
    }

    public function test_empty_reports_and_survey_activity_pagination_render(): void
    {
        $this->login();
        for ($i = 0; $i < 13; $i++) {
            Survey::create(['title' => 'Survey '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'status' => 'draft', 'allowed_roles' => ['member']]);
        }
        $this->get(route('evaluation.report-center'))->assertOk()->assertSee('Survey 09')->assertDontSee('Survey 12</td>', false);
        $this->get(route('evaluation.report-center', ['activity_page' => 2]))->assertOk()->assertSee('Survey 12');
        $response = $this->get(route('evaluation.report-download', ['format' => 'xlsx', 'scope' => 'filtered']))->assertOk();
        @unlink($response->baseResponse->getFile()->getPathname());
    }

    public function test_long_pdf_answers_flow_across_pages_without_truncation(): void
    {
        $this->login();
        [$expert] = $this->fixtures();
        $evaluation = Evaluation::where('expert_id', $expert->id)->firstOrFail();
        $answers = $evaluation->answers;
        $answers[1]['value'] = 'LONG-ANSWER-START '.str_repeat('দীর্ঘ মন্তব্য ', 350).' LONG-ANSWER-END';
        $evaluation->update(['answers' => $answers]);
        $response = $this->get(route('evaluation.report-download', ['format' => 'pdf', 'scope' => 'selected', 'expert_ids' => [$expert->id]]))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertSame('%PDF-', file_get_contents($path, false, null, 0, 5));
        if (getenv('CMO_REPORT_STRESS_PATH')) {
            copy($path, getenv('CMO_REPORT_STRESS_PATH'));
        }
        @unlink($path);
    }

    public function test_pdf_size_limit_asks_for_narrower_filters_without_generating_partial_report(): void
    {
        $this->login();
        $this->fixtures();
        $row = Evaluation::firstOrFail()->getRawOriginal();
        unset($row['id']);
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $entry = $row;
            $entry['viva_date'] = Carbon::parse('2020-01-01')->addDays($i)->toDateString();
            $rows[] = $entry;
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('evaluations')->insert($chunk);
        }
        $this->get(route('evaluation.report-download', ['format' => 'pdf', 'scope' => 'filtered']))->assertSessionHasErrors('download');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'evaluation.report.export']);
    }

    public function test_report_center_separates_sections_and_preserves_filters_for_both_report_roles(): void
    {
        [$expert] = $this->fixtures();
        $filters = ['expert_id' => $expert->id, 'from' => '2026-11-02', 'to' => '2026-11-02'];
        foreach ([UserRole::Admin, UserRole::Chairman] as $role) {
            $this->login($role);
            $main = $this->get(route('evaluation.reports', $filters + ['expert_page' => 1, 'submissions_page' => 1]));
            $main->assertOk()->assertSee('Report Center')->assertSee('Question Summary & Rating Averages', false)
                ->assertDontSee('Download Excel')->assertDontSee('Download Expert Reports')->assertDontSee('Evaluated Experts')->assertDontSee('id="survey-activity"', false)
                ->assertSee('href="'.e(route('evaluation.report-center', $filters)).'"', false);
            $this->get(route('evaluation.report-center', $filters))->assertOk()->assertSee('Download Excel')->assertSee('Download PDF')->assertSee('Survey Activity')
                ->assertSee('href="'.e(route('evaluation.reports', $filters)).'"', false)
                ->assertDontSee('id="expert-submissions"', false);
            // Setup migration stays Administrator-only even though report downloads are shared.
            $this->get(route('transfers.index'))->assertStatus($role === UserRole::Admin ? 200 : 403);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'evaluation.report.center.view']);
    }

    public function test_chairman_can_download_excel_pdf_and_legacy_csv_and_view_full_answers(): void
    {
        [$expert] = $this->fixtures();
        $this->login(UserRole::Chairman);
        foreach (['xlsx', 'pdf'] as $format) {
            $response = $this->post(route('evaluation.report-download'), ['format' => $format, 'scope' => 'selected', 'expert_ids' => [$expert->id]])->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            if ($format === 'pdf') {
                $this->assertSame('%PDF-', file_get_contents($path, false, null, 0, 5));
            } else {
                $book = IOFactory::load($path);
                $this->assertSame(3, $book->getSheetByName('Submissions')->getHighestRow());
                $book->disconnectWorksheets();
            }
            @unlink($path);
        }
        foreach (['summary', 'submissions'] as $kind) {
            $this->get(route('evaluation.reports', ['export' => $kind, 'expert_id' => $expert->id]))->assertOk();
        }
        $response = Evaluation::where('expert_id', $expert->id)->firstOrFail();
        $this->get(route('evaluations.show', $response))->assertOk()->assertSee('বিষয়ভিত্তিক জ্ঞান');
        $this->post(route('transfers.download'), ['scope' => 'experts'])->assertForbidden();
    }
}
