<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['designations', 'units', 'users', 'experts', 'questions', 'surveys'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->uuid('transfer_uuid')->nullable()->unique());
            DB::table($name)->select('id')->orderBy('id')->chunkById(200, function ($rows) use ($name) {
                foreach ($rows as $row) {
                    DB::table($name)->where('id', $row->id)->update(['transfer_uuid' => (string) Str::uuid()]);
                }
            });
        }
        Schema::create('data_imports', function (Blueprint $t) {
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
        Schema::dropIfExists('data_imports');
        foreach (['designations', 'units', 'users', 'experts', 'questions', 'surveys'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->dropUnique(['transfer_uuid']);
                $t->dropColumn('transfer_uuid');
            });
        }
    }
};
