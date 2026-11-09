<?php

namespace Database\Seeders;

use App\Models\Designation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DesignationSeeder extends Seeder
{
    public function run(): void
    {
        $designations = [
            [
                'name' => 'System Manager',
                'slug' => 'system-manager',
                'sort_order' => 1,
            ],
            [
                'name' => 'Senior System Analyst',
                'slug' => 'senior-system-analyst',
                'sort_order' => 2,
            ],
            [
                'name' => 'System Analyst',
                'slug' => 'system-analyst',
                'sort_order' => 3,
            ],
            [
                'name' => 'Senior Programmer',
                'slug' => 'senior-programmer',
                'sort_order' => 4,
            ],
            [
                'name' => 'Programmer',
                'slug' => 'programmer',
                'sort_order' => 5,
            ],
            [
                'name' => 'Assistant Programmer',
                'slug' => 'assistant-programmer',
                'sort_order' => 6,
            ],
            [
                'name' => 'Computer Operator',
                'slug' => 'computer-operator',
                'sort_order' => 7,
            ],
        ];

        $designations[] = ['name' => 'Data Entry Operator', 'slug' => 'data-entry-operator', 'sort_order' => 8];

        foreach (['Exam Controller', 'Director', 'Deputy Director', 'Assistant Director', 'Secretary', 'Joint Secretary', 'Deputy Secretary', 'Senior Assistant Secretary', 'Administrative Officer', 'Personal Officer', 'Office Assistant cum Computer Operator', 'Steno Typist'] as $i => $name) {
            $designations[] = ['name' => $name, 'slug' => Str::slug($name), 'sort_order' => 9 + $i];
        }
        foreach (['Librarian', 'Assistant Librarian', 'Law Adviser', 'Law Officer', 'Member', 'Chairman', 'Personal Secretary'] as $i => $name) {
            $designations[] = ['name' => $name, 'slug' => Str::slug($name), 'sort_order' => 30 + $i];
        }
        foreach ($designations as $designation) {
            Designation::firstOrCreate(['slug' => $designation['slug']], $designation + ['is_active' => true]);
        }
    }
}
