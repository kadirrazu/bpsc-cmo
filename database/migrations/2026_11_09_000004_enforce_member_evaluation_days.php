<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_evaluation_days', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('expert_id')->constrained()->cascadeOnDelete();
            $t->date('viva_date');
            $t->primary(['user_id', 'expert_id', 'viva_date'], 'member_evaluation_days_primary');
        });
        // Preserve every historical response, including duplicates allowed by earlier releases.
        // Existing evaluator/day combinations are reserved so a future Member cannot submit again.
        DB::table('evaluations')->orderBy('id')->chunkById(200, function ($rows) {
            $records = [];
            foreach ($rows as $row) {
                $records[] = ['user_id' => $row->user_id, 'expert_id' => $row->expert_id, 'viva_date' => $row->viva_date];
            }if ($records) {
                DB::table('member_evaluation_days')->insertOrIgnore($records);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_evaluation_days');
    }
};
