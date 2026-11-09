<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') && $this->user()->can('manage-user', $this->route('user'));
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'member_number' => ['nullable', 'string', 'max:150', Rule::excludeIf(! in_array($this->input('role'), ['member', 'chairman'], true))],
            'floor' => ['nullable', 'string', 'max:150', Rule::excludeIf(! in_array($this->input('role'), ['member', 'chairman'], true))],
            'room_number' => ['nullable', 'string', 'max:150', Rule::excludeIf(! in_array($this->input('role'), ['member', 'chairman'], true))],
            'lift_button_number' => ['nullable', 'string', 'max:150', Rule::excludeIf(! in_array($this->input('role'), ['member', 'chairman'], true))],
            'name_of_po' => ['nullable', 'string', 'max:150', Rule::excludeIf(! in_array($this->input('role'), ['member', 'chairman'], true))],
            'mobile_number' => ['nullable', 'string', 'regex:/^[0-9]{11}$/'],
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],

            'designation_id' => [
                'required',
                'integer',
                Rule::exists('designations', 'id')
                    ->where('is_active', true),
            ],

            'unit' => ['required', 'string', Rule::exists('units', 'code')->where('is_active', true)],

            'role' => [
                'required',
                Rule::in(array_column(UserRole::assignable($this->user()->role), 'value')),
            ],

            'password' => [
                'nullable',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'designation_id' => __('Designation'),
            'unit' => __('Unit'),
        ];
    }
}
