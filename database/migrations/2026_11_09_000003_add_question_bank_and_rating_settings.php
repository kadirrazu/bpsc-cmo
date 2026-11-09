<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $t) {
            $t->text('hints')->nullable();
            $t->integer('rating_min')->nullable()->default(1);
            $t->integer('rating_max')->nullable()->default(5);
        });
        Schema::create('question_survey', function (Blueprint $t) {
            $t->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $t->foreignId('question_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('position')->default(0);
            $t->primary(['survey_id', 'question_id']);
        });
        DB::table('questions')->whereNotNull('survey_id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $q) {
                DB::table('question_survey')->insert(['survey_id' => $q->survey_id, 'question_id' => $q->id, 'position' => $q->sort_order]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('question_survey')->select('question_id')->groupBy('question_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Questions are reused across surveys. Restore a backup instead of rolling back this migration.');
        }
        foreach (DB::table('question_survey')->get() as $link) {
            DB::table('questions')->where('id', $link->question_id)->update(['survey_id' => $link->survey_id]);
        }
        Schema::dropIfExists('question_survey');
        Schema::table('questions', fn (Blueprint $t) => $t->dropColumn(['hints', 'rating_min', 'rating_max']));
    }
};
