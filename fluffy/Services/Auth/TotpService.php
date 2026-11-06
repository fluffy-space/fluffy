<?php

namespace Fluffy\Services\Auth;

/**
 * Time-based one-time codes (RFC 6238) as authenticator apps make them: HMAC-SHA1, 6 digits,
 * 30-second steps, secret shared as base32. Pure maths, no storage: the caller keeps the secret
 * and the last step used, and refuses a step it has seen (one code signs in once).
 */
class TotpService
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    /** Steps accepted either side of now: covers a phone clock off by up to ~30 s. */
    public const WINDOW = 1;
    /** 160-bit secret, the size RFC 4226 recommends for SHA-1. */
    public const SECRET_BYTES = 20;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * The otpauth:// URI an authenticator app reads from the enrolment QR code.
     * $account is what the app shows under the issuer, usually the email.
     */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The step $code matches, or null. Only steps after $lastUsedStep count, so a code already
     * used (or an older one from the same window) is refused. Spaces in $code are ignored.
     */
    public function verify(string $secret, string $code, int $lastUsedStep = 0, ?int $now = null): ?int
    {
        $code = str_replace(' ', '', $code);
        if (strlen($code) !== self::DIGITS || !ctype_digit($code)) {
            return null;
        }
        $key = self::base32Decode($secret);
        if ($key === null || $key === '') {
            return null;
        }
        $current = intdiv($now ?? time(), self::PERIOD);
        // Check every step in the window, no early return, so timing does not say which matched.
        $matched = null;
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if (hash_equals(self::codeAt($key, $step), $code) && $step > $lastUsedStep && $matched === null) {
                $matched = $step;
            }
        }
        return $matched;
    }

    /** The code for one step; public for tests against the RFC 6238 vectors. */
    public static function codeAt(string $key, int $step): string
    {
        $hmac = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hmac[19]) & 0x0f;
        $value = ((ord($hmac[$offset]) & 0x7f) << 24)
            | (ord($hmac[$offset + 1]) << 16)
            | (ord($hmac[$offset + 2]) << 8)
            | ord($hmac[$offset + 3]);
        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    /** Bytes, or null on a character outside the alphabet. Case, spaces and '=' padding are ignored. */
    public static function base32Decode(string $text): ?string
    {
        $text = strtoupper(str_replace([' ', '='], '', $text));
        if ($text === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($text) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                return null;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
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
