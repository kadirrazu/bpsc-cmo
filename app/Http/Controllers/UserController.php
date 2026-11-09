<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Designation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Library\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:255', 'role' => ['nullable', Rule::enum(UserRole::class)], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'unit' => ['nullable', Rule::in(app(Catalog::class)->units('users'))]]);
        $search = trim($data['search'] ?? '');
        $users = User::with('designation')->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->orWhere('unit', 'like', '%'.$search.'%')->orWhereIn('unit', Unit::select('code')->where('name', 'like', '%'.$search.'%'))->orWhereHas('designation', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))))->when($data['role'] ?? null, fn ($q, $role) => $q->where('role', $role))->when($data['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'active'))->when($data['unit'] ?? null, fn ($q, $unit) => $q->where('unit', $unit))->latest('id')->paginate(25)->withQueryString();

        return $request->is('api/*') ? response()->json($users) : view('users.index', compact('users', 'search'));
    }

    public function create()
    {
        return view('users.create', ['designations' => $this->designations(), 'roles' => UserRole::assignable(auth()->user()->role)]);
    }

    public function store(StoreUserRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            app(Catalog::class)->lockUnit($request->input('unit'), 'users');

            return User::create($request->safe()->except('password_confirmation'));
        });
        if ($request->is('api/*')) {
            return response()->json(['data' => $user->load('designation')], 201);
        }

        return redirect()->route('users.index')->with('success', __('User created successfully.'));
    }

    public function show(Request $request, User $user)
    {
        Gate::authorize('manage-user', $user);

        return $request->is('api/*') ? response()->json(['data' => $user->load('designation')]) : redirect()->route('users.edit', $user);
    }

    public function edit(User $user)
    {
        Gate::authorize('manage-user', $user);

        return view('users.edit', ['user' => $user, 'designations' => $this->designations($user), 'roles' => UserRole::assignable(auth()->user()->role)]);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');
        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['remember_token'] = null;
        }
        DB::transaction(function () use ($request, $user, $data) {
            $admins = User::where('role', 'admin')->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage-user', $current);
            if ($request->user()->is($current) && (! $data['is_active'] || $data['role'] !== $current->role->value)) {
                throw ValidationException::withMessages(['role' => __('You cannot deactivate or remove administrator access from your own account.')]);
            }
            if ($current->role === UserRole::Admin && $current->is_active && (! $data['is_active'] || $data['role'] !== 'admin') && $admins->count() <= 1) {
                throw ValidationException::withMessages(['role' => __('At least one active Administrator must remain.')]);
            }
            app(Catalog::class)->lockUnit($data['unit'], 'users');
            $current->forceFill($data)->save();
        });
        if ($request->is('api/*')) {
            return response()->json(['data' => $user->fresh()->load('designation')]);
        }
        if ($request->user()->is($user)) {
            $request->session()->regenerate();
            $request->session()->put('auth_version', $user->fresh()->auth_version);
        }

        return redirect()->route('users.index')->with('success', __('User updated successfully.'));
    }

    public function confirmDelete(User $user)
    {
        Gate::authorize('manage-system');
        Gate::authorize('manage-user', $user);

        return view('users.delete', compact('user'));
    }

    public function destroy(Request $request, User $user)
    {
        Gate::authorize('manage-system');
        $request->validate(['confirmation' => ['required', Rule::in(['DELETE'])]]);
        DB::transaction(function () use ($request, $user) {
            $admins = User::where('role', 'admin')->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage-user', $current);
            if ($request->user()->is($current)) {
                throw ValidationException::withMessages(['confirmation' => __('You cannot delete your own account.')]);
            }
            if ($current->role === UserRole::Admin && $current->is_active && $admins->count() <= 1) {
                throw ValidationException::withMessages(['confirmation' => __('At least one active Administrator must remain.')]);
            }
            $current->forceFill(['is_active' => false, 'remember_token' => null])->save();
            $current->delete();
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $current->id)->delete();
            }
        });

        return $request->is('api/*') ? response()->noContent() : redirect()->route('users.index')->with('success', __('User deleted. This account can no longer sign in.'));
    }

    private function designations(?User $user = null)
    {
        return Designation::where(fn ($q) => $q->where('is_active', true)->when($user?->designation_id, fn ($q, $id) => $q->orWhere('id', $id)))->orderBy('sort_order')->orderBy('name')->get();
    }
}
