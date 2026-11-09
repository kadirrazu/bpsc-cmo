<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('designation_id')
                ->nullable()
                ->after('email')
                ->constrained('designations')
                ->nullOnDelete();

            $table->string('role', 50)
                ->default('staff')
                ->after('designation_id');

            $table->boolean('is_active')
                ->default(true)
                ->after('role');

            $table->timestamp('last_login_at')
                ->nullable()
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['designation_id']);

            $table->dropColumn([
                'designation_id',
                'role',
                'is_active',
                'last_login_at',
            ]);
        });
    }
};
