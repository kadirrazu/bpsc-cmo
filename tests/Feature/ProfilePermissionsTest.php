<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Designation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(UserRole $role): User
    {
        $designation = Designation::where('slug', 'librarian')->firstOrFail();
        $user = User::factory()->create(['role' => $role, 'designation_id' => $designation->id, 'unit' => 'Unit 01']);
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    public function test_every_role_can_edit_only_own_name_and_email(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = $this->signIn($role);
            $before = $user->getAttributes();
            $unchanged = [];
            foreach (['designation_id', 'unit', 'role', 'is_active', 'password'] as $field) {
                $unchanged[$field] = $user->getAttribute($field);
            }
            $email = $role->value.'@updated.example.test';
            $this->put('/profile', ['name' => 'Updated Name', 'email' => $email])->assertRedirect('/profile');
            $fresh = $user->fresh();
            $this->assertSame('Updated Name', $fresh->name);
            $this->assertSame($email, $fresh->email);
            foreach (['designation_id', 'unit', 'role', 'is_active', 'password'] as $field) {
                $this->assertSame($unchanged[$field], $fresh->getAttribute($field), $role->value.': '.$field);
            }
            $this->assertNull($fresh->email_verified_at);
            $this->assertSame($before['auth_version'] + 1, $fresh->auth_version);
            $this->get('/profile')->assertOk();
            $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'subject_id' => $user->id]);
        }
    }

    public function test_profile_rejects_forged_management_fields_for_every_role(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = $this->signIn($role);
            $name = $user->name;
            $this->put('/profile', [
                'name' => 'Forged', 'email' => $user->email,
                'designation_id' => 999, 'unit' => 'Unit 02', 'role' => 'admin',
                'is_active' => 0, 'user_id' => 999, 'preferred_locale' => 'en',
                'password' => 'Changed@Password123',
            ])->assertSessionHasErrors(['designation_id', 'unit', 'role', 'is_active', 'user_id', 'preferred_locale', 'password']);
            $this->put('/profile', [
                'name' => 'Forged Empty Fields', 'email' => $user->email,
                'designation_id' => null, 'unit' => null, 'role' => null, 'is_active' => null,
            ])->assertSessionHasErrors(['designation_id', 'unit', 'role', 'is_active']);
            $this->assertSame($name, $user->fresh()->name);
            $this->assertSame($role, $user->fresh()->role);
            $this->assertSame('Unit 01', $user->fresh()->unit);
        }
    }

    public function test_only_administrator_can_access_any_user_management_endpoint(): void
    {
        $target = User::factory()->create(['role' => UserRole::Member]);
        foreach ([UserRole::Staff, UserRole::Member, UserRole::Chairman] as $role) {
            $this->signIn($role);
            foreach (['/users', '/users/create', '/users/'.$target->id, '/users/'.$target->id.'/edit', '/users/'.$target->id.'/delete-confirm'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/users', [])->assertForbidden();
            $this->put('/users/'.$target->id, [])->assertForbidden();
            $this->delete('/users/'.$target->id, ['confirmation' => 'DELETE'])->assertForbidden();
        }
        foreach ([UserRole::Admin] as $role) {
            $this->signIn($role);
            $this->get('/users')->assertOk();
            $this->get('/users/create')->assertOk();
            $this->get('/users/'.$target->id.'/edit')->assertOk();
        }
    }

    public function test_profile_renders_editable_name_email_and_readonly_organization_fields_for_all_roles(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->signIn($role);
            $this->get('/profile')->assertOk()
                ->assertSee('name="name"', false)->assertSee('name="email"', false)
                ->assertSee('id="profile-designation"', false)->assertSee('id="profile-unit"', false)->assertSee('id="profile-role"', false)
                ->assertDontSee('name="designation_id"', false)->assertDontSee('name="unit"', false)->assertDontSee('name="role"', false);
        }
    }

    public function test_duplicate_email_is_rejected_without_modifying_the_profile(): void
    {
        $user = $this->signIn(UserRole::Member);
        $other = User::factory()->create();
        $this->put('/profile', ['name' => 'Changed', 'email' => $other->email])->assertSessionHasErrors('email');
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_staff_cannot_see_user_management_links_or_manage_designations(): void
    {
        $this->signIn(UserRole::Staff);
        $this->get('/dashboard')->assertOk()->assertDontSee('href="'.route('users.index').'"', false)->assertDontSee('User Management');
        $this->get('/designations')->assertForbidden();
    }
}
