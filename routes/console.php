<?php

use App\Console\Questions\AdminChoiceQuestion;
use App\Enums\UserRole;
use App\Models\DataImport;
use App\Models\Designation;
use App\Models\User;
use App\Models\VivaImport;
use App\Services\Library\Catalog;
use App\Services\UpdateNoteOrganizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

Artisan::command('cmo:create-admin', function () {
    if (! app()->environment(['local', 'development'])) {
        $this->error('This command is available only in development (APP_ENV=local or development).');

        return 1;
    }
    $designations = Designation::where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    if (! $designations) {
        $this->error('No active designations found. Run php artisan db:seed --class=DesignationSeeder first.');

        return 1;
    }
    $selectedDesignation = $this->output->askQuestion(new AdminChoiceQuestion('Designation', array_values($designations), 0));
    $designationId = array_search($selectedDesignation, $designations, true);
    $name = $this->ask('Administrator name');
    $email = strtolower((string) $this->ask('Email address'));
    $password = $this->secret('Password (8+ characters, mixed case, number and symbol)');
    $confirm = $this->secret('Confirm password');
    $unitLabels = app(Catalog::class)->unitLabels('users');
    if (! $unitLabels) {
        $this->error('No active units found. Run php artisan db:seed --class=UnitSeeder first.');

        return 1;
    }
    $selectedUnit = $this->output->askQuestion(new AdminChoiceQuestion('Department', array_values($unitLabels), $unitLabels['IT Section'] ?? reset($unitLabels)));
    $unit = array_search($selectedUnit, $unitLabels, true);
    $validator = Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm], [
        'name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email',
        'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    DB::transaction(function () use ($name, $email, $password, $designationId, $unit) {
        $designation = Designation::whereKey($designationId)->where('is_active', true)->lockForUpdate()->first();
        if (! $designation) {
            throw ValidationException::withMessages(['designation' => 'Selected designation is no longer active. Try again.']);
        }
        app(Catalog::class)->lockUnit($unit, 'users');
        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'designation_id' => $designation->id, 'unit' => $unit, 'role' => UserRole::Admin, 'is_active' => true]);
    });
    $this->info('Administrator created. No default password was saved.');

    return 0;
})->purpose('Development only: create an administrator using interactive credentials');
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Artisan::command('cmo:organize-update-notes', function () {
    $result = app(UpdateNoteOrganizer::class)->organize(base_path());
    $this->info('Update notes organized in docs/updates: '.$result['moved'].' moved, '.$result['deduplicated'].' duplicate root copies removed, '.$result['skipped'].' skipped.');

    return 0;
})->purpose('Move legacy root UPDATE-v*.txt files into docs/updates without overwriting different content');

Schedule::call(fn () => DataImport::where('expires_at', '<', now())->delete())->daily()->name('cmo:prune-imports');

Schedule::call(fn () => VivaImport::where('expires_at', '<', now())->delete())->daily()->name('cmo:prune-viva-imports');
