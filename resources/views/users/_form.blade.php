@php
    $editing = isset($user);
    $selectedRole = old(
        'role',
        $editing ? $user->role->value : 'staff'
    );
@endphp

<div class="row g-3">
    <div class="col-md-6">
        <label for="name" class="form-label"><i class="bi bi-person" aria-hidden="true"></i> 
            {{ __('Name') }} <span class="text-danger">*</span>
        </label>

        <input
            type="text"
            id="name"
            name="name"
            value="{{ old('name', $user->name ?? '') }}"
            class="form-control @error('name') is-invalid @enderror"
            required
            autofocus
        >

        @error('name')
            <div class="invalid-feedback">
                {{ $message }}
            </div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="email" class="form-label"><i class="bi bi-envelope" aria-hidden="true"></i> 
            {{ __('Email') }} <span class="text-danger">*</span>
        </label>

        <input
            type="email"
            id="email"
            name="email"
            value="{{ old('email', $user->email ?? '') }}"
            class="form-control @error('email') is-invalid @enderror"
            required
        >

        @error('email')
            <div class="invalid-feedback">
                {{ $message }}
            </div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="designation_id" class="form-label"><i class="bi bi-person-badge" aria-hidden="true"></i> 
            {{ __('Designation') }} <span class="text-danger">*</span>
        </label>

        <select
            id="designation_id"
            name="designation_id"
            class="form-select @error('designation_id') is-invalid @enderror"
            required
        >
            <option value="">{{ __('Select designation') }}</option>

            @foreach ($designations as $designation)
                <option
                    value="{{ $designation->id }}"
                    @selected(
                        old(
                            'designation_id',
                            $user->designation_id ?? ''
                        ) == $designation->id
                    )
                >
                    {{ __($designation->name) }}
                </option>
            @endforeach
        </select>

        @error('designation_id')
            <div class="invalid-feedback">
                {{ $message }}
            </div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="unit" class="form-label required"><i class="bi bi-building" aria-hidden="true"></i> {{ __('Unit') }}</label>
        <select id="unit" name="unit" class="form-select @error('unit') is-invalid @enderror" required>
            <option value="">{{ __('Select unit') }}</option>
            @foreach(app(\App\Services\Library\Catalog::class)->units('users') as $unit)
                <option value="{{ $unit }}" @selected(old('unit', $user->unit ?? '') === $unit)>{{ \App\Support\Ui::unit($unit) }}</option>
            @endforeach
        </select>
        @error('unit')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label for="role" class="form-label"><i class="bi bi-shield-lock" aria-hidden="true"></i> 
            {{ __('Role') }} <span class="text-danger">*</span>
        </label>

        <select
            id="role"
            name="role"
            class="form-select @error('role') is-invalid @enderror"
            required
        >
            @foreach ($roles as $role)
                <option
                    value="{{ $role['value'] }}"
                    @selected($selectedRole === $role['value'])
                >
                    {{ $role['label'] }}
                </option>
            @endforeach
        </select>

        @error('role')
            <div class="invalid-feedback">
                {{ $message }}
            </div>
        @enderror
    </div>

    <div class="col-md-6"><label class="form-label" for="mobile_number">Mobile Number <span class="text-secondary">(Optional)</span></label><input class="form-control @error('mobile_number') is-invalid @enderror" type="tel" inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11" id="mobile_number" name="mobile_number" value="{{ old('mobile_number',$user->mobile_number ?? '') }}" placeholder="01912234567">@error('mobile_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-md-6">
        <label for="password" class="form-label"><i class="bi bi-lock" aria-hidden="true"></i> 
            {{ __('Password') }}
            @unless ($editing)
                <span class="text-danger">*</span>
            @endunless
        </label>

        <div class="password-control"><input
            type="password"
            id="password"
            name="password"
            class="form-control @error('password') is-invalid @enderror"
            @required(! $editing)
        ><button type="button" class="password-toggle" data-password-toggle data-show-label="{{ __('Show password') }}" data-hide-label="{{ __('Hide password') }}" aria-label="{{ __('Show password') }}" title="{{ __('Show password') }}" aria-pressed="false" hidden><i class="bi bi-eye" aria-hidden="true"></i></button></div>

        @unless ($editing)<small class="form-hint">{{ __('Use at least 8 characters, with uppercase/lowercase letters, a number and a symbol.') }}</small>@endunless
        @if ($editing)
            <small class="form-hint">
                {{ __('Leave blank to keep the current password. New passwords need 8+ characters, mixed case, a number and a symbol.') }}
            </small>
        @endif

        @error('password')
            <div class="invalid-feedback">
                {{ $message }}
            </div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="password_confirmation" class="form-label"><i class="bi bi-lock" aria-hidden="true"></i> 
            {{ __('Confirm Password') }}
        </label>

        <div class="password-control"><input
            type="password"
            id="password_confirmation"
            name="password_confirmation"
            class="form-control"
            @required(! $editing)
        ><button type="button" class="password-toggle" data-password-toggle data-show-label="{{ __('Show password') }}" data-hide-label="{{ __('Hide password') }}" aria-label="{{ __('Show password') }}" title="{{ __('Show password') }}" aria-pressed="false" hidden><i class="bi bi-eye" aria-hidden="true"></i></button></div>
    </div>

    <div class="col-12">
        <label class="form-check form-switch"><i class="bi bi-info-circle" aria-hidden="true"></i> 
            <input
                type="hidden"
                name="is_active"
                value="0"
            >

            <input
                type="checkbox"
                name="is_active"
                value="1"
                class="form-check-input"
                @checked(
                    old(
                        'is_active',
                        isset($user) ? $user->is_active : true
                    )
                )
            >

            <span class="form-check-label">
                {{ __('Active user') }}
            </span>
        </label>

        @error('is_active')
            <div class="text-danger small mt-1">
                {{ $message }}
            </div>
        @enderror
    </div>
</div>
<div class="card card-body mt-3" data-member-details @if(!in_array($selectedRole,['member','chairman'])) hidden @endif><h3>Viva Board Member Details <small class="text-secondary">Optional</small></h3><p class="text-secondary small">Displayed with assigned boards to help candidates find the member's room.</p><div class="row g-3">@foreach(['member_number'=>'Member Number','floor'=>'Floor','room_number'=>'Room Number','lift_button_number'=>'Lift Button Number','name_of_po'=>'Name of PO'] as $field=>$label)<div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-control" id="{{ $field }}" name="{{ $field }}" maxlength="150" value="{{ old($field,($user ?? null)?->$field ?? '') }}"></div>@endforeach</div></div>
