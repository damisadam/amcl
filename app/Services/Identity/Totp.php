<?php

namespace App\Services\Identity;

/**
 * RFC 6238 time-based one-time passwords (HMAC-SHA1, 30-second step, 6 digits) with RFC 4648 base32 secrets.
 * Implemented here to avoid an external dependency; compatible with Google Authenticator, Microsoft
 * Authenticator, Authy and other standard apps.
 */
final class Totp
{
    public const STEP = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function timestep(?int $time = null): int
    {
        return intdiv($time ?? \Illuminate\Support\Carbon::now()->getTimestamp(), self::STEP);
    }

    public static function code(string $secret, ?int $timestep = null): string
    {
        $counter = pack('N2', 0, $timestep ?? self::timestep());
        $hash = hash_hmac('sha1', $counter, self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8) | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a code within ±$window steps. Returns the matched timestep (for replay protection) or null.
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = self::timestep($time);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $now + $i), $code)) {
                return $now + $i;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::STEP,
        ]);
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[\s=]/', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
