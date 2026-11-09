<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('mobile_number', 20)->nullable());
        Schema::create('experts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type', 30)->index();
            $t->string('designation')->nullable();
            $t->string('organization')->nullable();
            $t->string('specialization')->nullable();
            $t->string('email')->nullable();
            $t->string('mobile_number', 30)->nullable();
            $t->text('biography')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->boolean('is_public')->default(false)->index();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('questions', function (Blueprint $t) {
            $t->id();
            $t->text('prompt');
            $t->string('type', 20);
            $t->json('options')->nullable();
            $t->boolean('is_required')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('evaluations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expert_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('viva_date');
            $t->string('reference')->nullable();
            $t->json('expert_snapshot');
            $t->json('evaluator_snapshot');
            $t->json('answers');
            $t->timestamp('submitted_at');
            $t->timestamps();
            $t->unique(['expert_id', 'user_id', 'viva_date'], 'evaluations_once_per_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('experts');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('mobile_number'));
    }
};
