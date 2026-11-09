<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            foreach (['member_number', 'floor', 'room_number', 'lift_button_number', 'name_of_po'] as $field) {
                $t->string($field, 150)->nullable();
            }
        });
        Schema::create('viva_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('start_time', 5)->default('09:30');
            $t->string('display_username', 100)->nullable();
            $t->string('display_password')->nullable();
            $t->unsignedInteger('auth_version')->default(1);
            $t->timestamps();
        });
        DB::table('viva_settings')->insert(['id' => 1, 'start_time' => '09:30', 'auth_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('viva_pools', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('viva_pool_user', function (Blueprint $t) {
            $t->foreignId('viva_pool_id')->constrained('viva_pools')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['viva_pool_id', 'user_id']);
        });
        Schema::create('viva_days', function (Blueprint $t) {
            $t->date('vdate')->primary();
        });
        Schema::create('viva_boards', function (Blueprint $t) {
            $t->id();
            $t->date('vdate')->index();
            $t->string('post_code', 20);
            $t->string('post_name');
            $t->string('board', 3);
            $t->timestamps();
            $t->unique(['vdate', 'post_code', 'board']);
        });
        Schema::create('viva_candidates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('viva_board_id')->constrained('viva_boards')->cascadeOnDelete();
            $t->date('vdate');
            $t->string('post_code', 20);
            $t->string('reg', 40);
            $t->string('name')->nullable();
            $t->timestamps();
            $t->unique(['vdate', 'post_code', 'reg'], 'viva_candidate_unique');
        });
        Schema::create('viva_assignments', function (Blueprint $t) {
            $t->id();
            $t->date('vdate');
            $t->foreignId('viva_board_id')->unique()->constrained('viva_boards')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('viva_pool_id')->nullable()->constrained('viva_pools')->nullOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('member_snapshot');
            $t->string('mode', 10);
            $t->timestamps();
            $t->unique(['vdate', 'user_id'], 'viva_one_board_per_member_day');
        });
        Schema::create('viva_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->longText('payload');
            $t->string('status')->default('pending');
            $t->timestamp('expires_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['viva_imports', 'viva_assignments', 'viva_candidates', 'viva_boards', 'viva_days', 'viva_pool_user', 'viva_pools', 'viva_settings'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['member_number', 'floor', 'room_number', 'lift_button_number', 'name_of_po']));
    }
};
