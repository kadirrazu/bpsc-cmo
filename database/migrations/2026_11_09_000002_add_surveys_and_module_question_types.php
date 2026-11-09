<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('status', 20)->default('draft')->index();
            $t->json('allowed_roles');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::table('questions', function (Blueprint $t) {
            $t->foreignId('survey_id')->nullable()->constrained()->restrictOnDelete();
        });
        // Keep a dedicated expert FK index before replacing the old composite unique index (MySQL).
        Schema::table('evaluations', fn (Blueprint $t) => $t->index('expert_id', 'evaluations_expert_lookup'));
        Schema::table('evaluations', function (Blueprint $t) {
            $t->foreignId('survey_id')->nullable()->constrained()->restrictOnDelete();
            $t->json('survey_snapshot')->nullable();
            $t->dropUnique('evaluations_once_per_day');
            $t->unique(['survey_id', 'expert_id', 'user_id', 'viva_date'], 'evaluations_once_per_survey_day');
        });
        if (DB::table('questions')->exists() || DB::table('evaluations')->exists()) {
            $id = DB::table('surveys')->insertGetId(['title' => 'Initial Expert Evaluation', 'description' => 'Questionnaire retained from the v0.1.0 starter.', 'status' => 'active', 'allowed_roles' => json_encode(['chairman', 'member']), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('questions')->update(['survey_id' => $id]);
            DB::table('evaluations')->update(['survey_id' => $id, 'survey_snapshot' => json_encode(['title' => 'Initial Expert Evaluation', 'description' => 'Questionnaire retained from the v0.1.0 starter.'])]);
        }
        DB::table('questions')->where('type', 'choice')->update(['type' => 'radio']);
        DB::table('questions')->where('type', 'text')->update(['type' => 'textarea']);
    }

    public function down(): void
    {
        // Never silently collapse separate survey histories into the old uniqueness rule.
        if (DB::table('evaluations')->select('expert_id', 'user_id', 'viva_date')->groupBy('expert_id', 'user_id', 'viva_date')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Multiple survey histories exist for the same expert/day. Restore a backup instead of rolling back this migration.');
        }
        DB::table('questions')->whereIn('type', ['select', 'radio', 'multiple'])->update(['type' => 'choice']);
        DB::table('questions')->whereIn('type', ['date', 'time', 'textarea'])->update(['type' => 'text']);
        Schema::table('evaluations', function (Blueprint $t) {
            $t->dropUnique('evaluations_once_per_survey_day');
            $t->dropForeign(['survey_id']);
            $t->dropColumn(['survey_id', 'survey_snapshot']);
            $t->unique(['expert_id', 'user_id', 'viva_date'], 'evaluations_once_per_day');
        });
        Schema::table('questions', function (Blueprint $t) {
            $t->dropForeign(['survey_id']);
            $t->dropColumn('survey_id');
        });
        Schema::dropIfExists('surveys');
    }
};
