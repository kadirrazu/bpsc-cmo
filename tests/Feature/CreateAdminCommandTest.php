<?php

namespace Tests\Feature;

use App\Console\Questions\AdminChoiceQuestion;
use App\Models\Designation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_choices_keep_numeric_validation_and_defaults_without_terminal_autocomplete(): void
    {
        $question = new AdminChoiceQuestion('Department', ['Unit 01', 'IT Section'], 'IT Section');
        $this->assertNull($question->getAutocompleterCallback());
        $this->assertNull($question->getAutocompleterValues());
        $this->assertSame('IT Section', $question->getDefault());
        $validate = $question->getValidator();
        $this->assertSame('Unit 01', $validate('0'));
        $this->assertSame('IT Section', $validate('1'));
        $this->assertSame('IT Section', $validate('IT Section'));
        $this->expectException(InvalidArgumentException::class);
        $validate('99');
    }

    public function test_interactive_command_creates_login_ready_admin_with_console_audit(): void
    {
        $this->seed();
        $this->app->detectEnvironment(fn () => 'local');
        $designation = Designation::where('is_active', true)->orderBy('sort_order')->orderBy('name')->firstOrFail();
        $this->artisan('cmo:create-admin')
            ->expectsQuestion('Designation', $designation->name)
            ->expectsQuestion('Administrator name', 'Diagnostic Administrator')
            ->expectsQuestion('Email address', 'diagnostic@example.test')
            ->expectsQuestion('Password (8+ characters, mixed case, number and symbol)', 'Diagnostic@Pass123')
            ->expectsQuestion('Confirm password', 'Diagnostic@Pass123')
            ->expectsQuestion('Department', 'IT Section')
            ->expectsOutput('Administrator created. No default password was saved.')
            ->assertExitCode(0);
        $user = User::where('email', 'diagnostic@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Diagnostic@Pass123', $user->password));
        $this->assertSame('admin', $user->role->value);
        $this->assertTrue($user->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'subject_id' => $user->id, 'channel' => 'console']);
    }
}
