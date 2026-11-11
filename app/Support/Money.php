<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Decimal-safe PKR arithmetic on numeric strings (bcmath). Never use floats for money.
 * All results are rounded half-up to 2 decimal places.
 */
final class Money
{
    public const SCALE = 2;

    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }
        if (is_float($value)) {
            $value = number_format($value, 6, '.', '');
        }
        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid money amount [{$value}]");
        }

        return self::round($value);
    }

    public static function add(string|int|float|null ...$values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, self::of($v), self::SCALE);
        }

        return self::of($sum);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string|int|float|null $a, string|int|float $factor): string
    {
        return self::round(bcmul(self::of($a), self::num($factor), 8));
    }

    public static function div(string|int|float|null $a, string|int|float $divisor): string
    {
        if (bccomp(self::num($divisor), '0', 8) === 0) {
            throw new InvalidArgumentException('Division by zero');
        }

        return self::round(bcdiv(self::of($a), self::num($divisor), 8));
    }

    /** Percentage of an amount, e.g. pct('10000', '2.5') = 250.00 */
    public static function pct(string|int|float|null $amount, string|int|float $percent): string
    {
        return self::round(bcdiv(bcmul(self::of($amount), self::num($percent), 8), '100', 8));
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function gt($a, $b): bool { return self::cmp($a, $b) > 0; }
    public static function gte($a, $b): bool { return self::cmp($a, $b) >= 0; }
    public static function lt($a, $b): bool { return self::cmp($a, $b) < 0; }
    public static function lte($a, $b): bool { return self::cmp($a, $b) <= 0; }
    public static function eq($a, $b): bool { return self::cmp($a, $b) === 0; }
    public static function isZero($a): bool { return self::cmp($a, '0') === 0; }
    public static function isPositive($a): bool { return self::cmp($a, '0') > 0; }

    public static function min($a, $b): string { return self::lte($a, $b) ? self::of($a) : self::of($b); }
    public static function max($a, $b): string { return self::gte($a, $b) ? self::of($a) : self::of($b); }

    /**
     * Split an amount into $parts equal pieces; the last piece absorbs the rounding remainder
     * so the pieces always sum exactly to the original amount.
     *
     * @return list<string>
     */
    public static function split(string|int|float $amount, int $parts): array
    {
        $amount = self::of($amount);
        $each = bcdiv($amount, (string) $parts, self::SCALE);
        $out = array_fill(0, $parts, $each);
        $out[$parts - 1] = bcsub($amount, bcmul($each, (string) ($parts - 1), self::SCALE), self::SCALE);

        return $out;
    }

    public static function format(string|int|float|null $value, bool $withCurrency = true): string
    {
        $v = self::of($value);
        $negative = str_starts_with($v, '-');
        [$int, $dec] = explode('.', ltrim($v, '-'));
        $int = strrev(implode(',', str_split(strrev($int), 3)));
        $s = ($negative ? '-' : '').$int.'.'.$dec;

        return $withCurrency ? 'PKR '.$s : $s;
    }

    private static function num(string|int|float $v): string
    {
        return is_float($v) ? number_format($v, 8, '.', '') : (string) $v;
    }

    private static function round(string $value): string
    {
        $value = (string) $value;
        if (! str_contains($value, '.')) {
            return bcadd($value, '0', self::SCALE);
        }
        $offset = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $offset, self::SCALE + 6), '0', self::SCALE);
    }
}
