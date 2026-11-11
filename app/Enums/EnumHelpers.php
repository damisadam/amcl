<?php

namespace App\Enums;

use Illuminate\Support\Str;

/** Shared helpers for backed enums: labels, select options and badge tones. */
trait EnumHelpers
{
    public function label(): string
    {
        return Str::headline($this->value);
    }

    /** Badge tone: green|red|amber|gray|blue|black */
    public function tone(): string
    {
        return 'gray';
    }

    /** @return array<string,string> value => label */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
