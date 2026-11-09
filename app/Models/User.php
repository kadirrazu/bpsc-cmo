<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, \Illuminate\Database\Eloquent\SoftDeletes, Notifiable;

    protected $attributes = ['role' => 'staff', 'is_active' => true, 'auth_version' => 1, 'preferred_locale' => 'en'];

    protected $fillable = [
        'mobile_number',
        'member_number', 'floor', 'room_number', 'lift_button_number', 'name_of_po',
        'name',
        'email',
        'password',
        'designation_id',
        'unit',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'auth_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'auth_version' => 'integer',
        ];
    }

    public function vivaPools()
    {
        return $this->belongsToMany(VivaPool::class, 'viva_pool_user', 'user_id', 'viva_pool_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $user) => $user->tokens()->delete());

        static::updating(function (self $user) {
            if ($user->isDirty(['password', 'role', 'is_active', 'email', 'deleted_at'])) {
                $user->auth_version = ((int) $user->getRawOriginal('auth_version')) + 1;
                $user->remember_token = null;
                $user->tokens()->delete();
            }
        });
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
}
