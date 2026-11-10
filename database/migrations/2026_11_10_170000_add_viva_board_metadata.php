<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viva_boards', function (Blueprint $table) {
            $table->string('unit')->nullable();
            $table->string('ministry')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('viva_boards', fn (Blueprint $table) => $table->dropColumn(['unit', 'ministry']));
    }
};
