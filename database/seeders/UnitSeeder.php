<?php
namespace Database\Seeders;
use App\Models\Unit;
use Illuminate\Database\Seeder;
class UnitSeeder extends Seeder {
 public function run(): void {
  foreach (config('library.seed_units') as $i=>$name) Unit::firstOrCreate(['code'=>$name], ['name'=>$name,'sort_order'=>$i+1,'is_active'=>true]);
 }
}
