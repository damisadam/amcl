<?php

namespace App\Services\Integrations;

/**
 * Deterministic simulation rules (docs/API_SIMULATION.md). Outcomes never use randomness so
 * demonstrations are repeatable. Driven by the CNIC's last digit unless a scenario is forced:
 *   0–6 → pass   7 → manual review (low face-match / partial NADRA match)
 *   8   → NADRA mismatch (fail)   9 → AML/PEP potential match
 */
final class Deterministic
{
    public static function lastDigit(?string $cnic): int
    {
        $digits = preg_replace('/\D/', '', (string) $cnic);

        return $digits === '' ? 0 : (int) substr($digits, -1);
    }

    public static function number(string $seed, int $min, int $max): int
    {
        return $min + (crc32($seed) % ($max - $min + 1));
    }

    public static function ref(string $prefix, string $seed): string
    {
        return $prefix.'-'.strtoupper(substr(hash('sha256', $seed), 0, 12));
    }
}
