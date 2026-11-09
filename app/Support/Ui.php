<?php

namespace App\Support;

use App\Services\Library\Catalog;
use Carbon\Carbon;

class Ui
{
    public static function unit(?string $code): string
    {
        return app(Catalog::class)->unitLabel($code);
    }

    public static function digits(mixed $value): string
    {
        return (string) $value;
    }

    public static function bengaliDigits(mixed $value): string
    {
        return strtr((string) $value, array_combine(str_split('0123456789'), preg_split('//u', '০১২৩৪৫৬৭৮৯', -1, PREG_SPLIT_NO_EMPTY)));
    }

    public static function ascii(mixed $value): mixed
    {
        return is_string($value) ? strtr($value, array_combine(preg_split('//u', '০১২৩৪৫৬৭৮৯', -1, PREG_SPLIT_NO_EMPTY), str_split('0123456789'))) : $value;
    }

    public static function number(mixed $value): string
    {
        return $value === null ? '—' : static::digits(number_format((float) $value));
    }

    public static function date(mixed $value, string $format = 'd M Y'): string
    {
        if (! $value) {
            return '—';
        }

        $date = Carbon::parse($value)->locale(app()->getLocale());
        $displayFormat = '';
        for ($i = 0; $i < strlen($format); $i++) {
            $character = $format[$i];
            if ($character === '\\' && $i + 1 < strlen($format)) {
                $displayFormat .= $character.$format[++$i];
            } elseif ($character === 'A' || $character === 'a') {
                // Preserve English AM/PM while translating dates and display digits.
                $displayFormat .= '\\'.implode('\\', str_split($date->format($character)));
            } else {
                $displayFormat .= $character;
            }
        }

        return static::digits($date->translatedFormat($displayFormat));
    }
}
