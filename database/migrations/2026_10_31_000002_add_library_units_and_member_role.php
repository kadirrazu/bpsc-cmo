<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('units', function (Blueprint $t) {
   $t->id(); $t->string('code',100)->unique(); $t->string('name',100); $t->unsignedInteger('sort_order')->default(0); $t->boolean('is_active')->default(true); $t->timestamps();
  });
  $defaults = [...array_map(fn($n)=>sprintf('Unit %02d',$n),range(1,20)), 'Non Cadre (Exam)','Cadre (Exam)','Non Cadre (Confidential)','Cadre (Confidential)','IT Section','Administration Wing','Law Wing'];
  $existing = DB::table('users')->whereNotNull('unit')->where('unit','<>','')->distinct()->pluck('unit')->all();
  foreach(array_values(array_unique([...$defaults,...$existing])) as $i=>$name) DB::table('units')->insert(['code'=>$name,'name'=>$name,'sort_order'=>$i+1,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
 }
 public function down(): void {
  Schema::dropIfExists('units');
 }
};
