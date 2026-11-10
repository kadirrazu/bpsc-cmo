<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viva_settings', fn (Blueprint $table) => $table->boolean('distribution_downloads_enabled')->default(false));
    }

    public function down(): void
    {
        Schema::table('viva_settings', fn (Blueprint $table) => $table->dropColumn('distribution_downloads_enabled'));
    }
};
