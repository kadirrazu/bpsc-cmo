<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Designation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CmoFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(UserRole $role = UserRole::Admin): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u)->withSession(['auth_version' => $u->auth_version]);

        return $u;
    }

    public function test_four_roles_render_dashboard_profile_and_password(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->signIn($role);
            foreach (['/dashboard', '/profile', '/profile/password'] as $url) {
                $this->get($url)->assertOk();
            }
        }
    }

    public function test_admin_shared_management_pages_render(): void
    {
        $this->signIn();
        foreach (['/users', '/users/create', '/units', '/units/create', '/designations', '/designations/create', '/roles', '/audit-logs'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_administration_and_audit_are_administrator_only(): void
    {
        foreach ([UserRole::Chairman, UserRole::Member, UserRole::Staff] as $role) {
            $this->signIn($role);
            foreach (['/users', '/units', '/designations', '/roles', '/audit-logs'] as $url) {
                $this->get($url)->assertForbidden();
            }
        }
    }

    public function test_active_login_and_inactive_login_rejection(): void
    {
        $u = User::factory()->create(['password' => 'Strong@Pass123']);
        $this->post('/login', ['email' => $u->email, 'password' => 'Strong@Pass123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($u);
        $this->post('/logout');
        $u->update(['is_active' => false]);
        $this->post('/login', ['email' => $u->email, 'password' => 'Strong@Pass123'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $u->id]);
    }

    public function test_account_changes_invalidate_existing_session(): void
    {
        $u = $this->signIn(UserRole::Member);
        $u->update(['is_active' => false]);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_cannot_delete_self(): void
    {
        $u = $this->signIn();
        $this->delete('/users/'.$u->id, ['confirmation' => 'DELETE'])->assertSessionHasErrors('confirmation');
        $this->assertNotSoftDeleted($u);
    }

    public function test_admin_can_create_each_new_role(): void
    {
        $this->signIn();
        $d = Designation::first();
        foreach (UserRole::cases() as $role) {
            $this->post('/users', ['name' => $role->label(), 'email' => $role->value.'@example.test', 'designation_id' => $d->id, 'unit' => 'Unit 01', 'role' => $role->value, 'password' => 'Strong@Pass123', 'password_confirmation' => 'Strong@Pass123', 'is_active' => 1])->assertRedirect('/users');
            $this->assertDatabaseHas('users', ['email' => $role->value.'@example.test', 'role' => $role->value]);
        }
    }

    public function test_designation_and_unit_crud_and_audit(): void
    {
        $this->signIn();
        $this->post('/designations', ['name' => 'CMO Officer', 'sort_order' => 5, 'is_active' => 1])->assertRedirect('/designations');
        $d = Designation::where('name', 'CMO Officer')->firstOrFail();
        $this->put('/designations/'.$d->id, ['name' => 'CMO Officer Updated', 'sort_order' => 6, 'is_active' => 1])->assertRedirect();
        $this->delete('/designations/'.$d->id, ['confirmation' => 'DELETE'])->assertRedirect();
        $this->post('/units', ['name' => 'CMO Unit', 'sort_order' => 90, 'is_active' => 1])->assertRedirect();
        $unit = Unit::where('name', 'CMO Unit')->firstOrFail();
        $this->put('/units/'.$unit->id, ['name' => 'CMO Unit Updated', 'sort_order' => 91, 'is_active' => 1])->assertRedirect();
        $this->delete('/units/'.$unit->id, ['confirmation' => 'DELETE'])->assertRedirect();
        foreach (['designation.created', 'designation.updated', 'designation.deleted', 'unit.created', 'unit.updated', 'unit.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    public function test_assigned_unit_and_designation_cannot_be_deleted(): void
    {
        $u = $this->signIn();
        $d = Designation::first();
        $u->forceFill(['designation_id' => $d->id, 'unit' => 'Unit 01'])->save();
        $this->delete('/designations/'.$d->id, ['confirmation' => 'DELETE'])->assertSessionHasErrors();
        $this->delete('/units/'.Unit::where('code', 'Unit 01')->firstOrFail()->id, ['confirmation' => 'DELETE'])->assertSessionHasErrors();
    }

    public function test_old_library_routes_and_self_registration_are_absent(): void
    {
        $this->signIn();
        foreach (['/books', '/loans/create', '/member', '/register', '/issue-register', '/borrowing-settings'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_all_blade_templates_compile_to_valid_php(): void
    {
        foreach (File::allFiles(resource_path('views')) as $view) {
            if (! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }$compiled = Blade::compileString($view->getContents());
            try {
                $tokens = token_get_all($compiled, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $this->fail($view->getRelativePathname().': '.$e->getMessage());
            }$this->assertNotEmpty($tokens);
        }
    }

    public function test_password_change_requires_current_password_and_invalidates_other_sessions(): void
    {
        $u = $this->signIn(UserRole::Member);
        $oldVersion = $u->auth_version;
        $this->put('/profile/password', ['current_password' => 'wrong', 'password' => 'Updated@Pass123', 'password_confirmation' => 'Updated@Pass123'])->assertSessionHasErrors('current_password');
        $this->put('/profile/password', ['current_password' => 'password', 'password' => 'Updated@Pass123', 'password_confirmation' => 'Updated@Pass123'])->assertRedirect('/profile/password');
        $this->assertTrue(Hash::check('Updated@Pass123', $u->fresh()->password));
        $this->assertSame($oldVersion + 1, $u->fresh()->auth_version);
        $this->get('/dashboard')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'subject_id' => $u->id]);
    }

    public function test_admin_cannot_demote_or_deactivate_self(): void
    {
        $u = $this->signIn();
        $u->forceFill(['designation_id' => Designation::first()->id, 'unit' => 'IT Section'])->save();
        $d = ['name' => $u->name, 'email' => $u->email, 'designation_id' => $u->designation_id, 'unit' => $u->unit, 'role' => 'member', 'is_active' => 1];
        $this->put('/users/'.$u->id, $d)->assertSessionHasErrors('role');
        $d['role'] = 'admin';
        $d['is_active'] = 0;
        $this->put('/users/'.$u->id, $d)->assertSessionHasErrors('role');
        $this->assertSame(UserRole::Admin, $u->fresh()->role);
        $this->assertTrue($u->fresh()->is_active);
    }
}
