<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Chairman = 'chairman';
    case Member = 'member';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',self::Chairman => 'Chairman',self::Member => 'Member',self::Staff => 'Staff'
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }

    public static function assignable(UserRole $actor): array
    {
        return $actor === self::Admin ? self::options() : [];
    }
}
