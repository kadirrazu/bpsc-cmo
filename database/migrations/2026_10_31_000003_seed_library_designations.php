<?php
use Illuminate\Database\Migrations\Migration;
use Database\Seeders\DesignationSeeder;
return new class extends Migration {
 public function up(): void { (new DesignationSeeder)->run(); }
 public function down(): void { /* Existing and seeded designations are retained to protect user assignments. */ }
};
