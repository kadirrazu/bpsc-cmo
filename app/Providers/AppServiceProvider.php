<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Designation;
use App\Models\Expert;
use App\Models\Question;
use App\Models\Survey;
use App\Models\Unit;
use App\Models\User;
use App\Models\VivaSetting;
use App\Observers\LibraryObserver;
use App\Services\Library\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::define('download-viva-candidates', fn (User $u) => $u->is_active && $u->role === UserRole::Admin && (bool) VivaSetting::whereKey(1)->value('candidate_downloads_enabled'));
        Gate::define('download-viva-distributions', fn (User $u) => $u->is_active && $u->role === UserRole::Chairman && (bool) VivaSetting::whereKey(1)->value('distribution_downloads_enabled'));
        Gate::define('download-viva-summary', fn (User $u) => $u->is_active && ($u->role === UserRole::Admin || ($u->role === UserRole::Chairman && (bool) VivaSetting::whereKey(1)->value('distribution_downloads_enabled'))));
        Gate::define('access-viva', fn (User $u) => $u->is_active && in_array($u->role, [UserRole::Admin, UserRole::Chairman, UserRole::Member], true));
        Gate::define('view-viva-distributions', fn (User $u) => $u->is_active && in_array($u->role, [UserRole::Admin, UserRole::Chairman], true));
        Gate::define('view-own-viva-board', fn (User $u) => $u->is_active && $u->role === UserRole::Member);
        Gate::define('manage-viva-pools', fn (User $u) => $u->is_active && $u->role === UserRole::Chairman);
        Gate::define('distribute-viva', fn (User $u) => $u->is_active && $u->role === UserRole::Chairman);
        Paginator::defaultView('shared.pagination');
        foreach (['manage-system', 'manage-users', 'view-logs', 'view-evaluation-data'] as $ability) {
            Gate::define($ability, fn (User $u) => $u->is_active && $u->role === UserRole::Admin);
        }
        Gate::define('manage-surveys', fn (User $u) => $u->is_active && in_array($u->role, [UserRole::Admin, UserRole::Staff], true));
        Gate::define('manage-user', fn (User $actor, User $target) => $actor->can('manage-users'));
        Gate::define('view-experts', fn (User $u) => $u->is_active && in_array($u->role, [UserRole::Admin, UserRole::Chairman, UserRole::Staff], true));
        Gate::define('view-expert-reports', fn (User $u) => $u->is_active && in_array($u->role, [UserRole::Admin, UserRole::Chairman], true));
        Gate::define('delete-experts', fn (User $u) => $u->is_active && $u->role === UserRole::Admin);
        Gate::define('manage-experts', fn (User $u) => $u->is_active && in_array($u->role, [UserRole::Admin, UserRole::Staff], true));
        Gate::define('evaluate-experts', fn (User $u) => $u->is_active && in_array($u->role, [UserRole::Chairman, UserRole::Member], true));
        Gate::define('access-evaluation', fn (User $u) => $u->is_active && in_array($u->role, UserRole::cases(), true));
        foreach ([User::class, Designation::class, Unit::class, Expert::class, Question::class, Survey::class] as $model) {
            $model::observe(LibraryObserver::class);
            $model::creating(function ($record) {
                if (! $record->transfer_uuid && Schema::hasColumn($record->getTable(), 'transfer_uuid')) {
                    $record->transfer_uuid = (string) Str::uuid();
                }
            });
        }
        Event::listen(Login::class, function (Login $e) {
            $e->user->forceFill(['last_login_at' => now()])->save();
            request()->session()->put('auth_version', $e->user->auth_version);
            app(Audit::class)->record('auth.login', actor: $e->user);
        });
        Event::listen(Logout::class, fn (Logout $e) => app(Audit::class)->record('auth.logout', actor: $e->user));
        Event::listen(Failed::class, fn (Failed $e) => app(Audit::class)->record('auth.failed', actor: $e->user));
    }
}
