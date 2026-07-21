<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * One-time recovery (backup) codes for 2FA (Kanwar, 2026-07-21).
 *
 * The one real gap in the otherwise-complete TOTP flow: a lost authenticator
 * used to mean only an admin `php artisan mfa:reset` could get you back in.
 * Now enrollment issues a set of single-use recovery codes, shown ONCE. They
 * are stored hashed (bcrypt) — a DB leak never exposes usable codes — and each
 * is consumed the moment it's used at the login challenge.
 *
 * Codes are drawn from an unambiguous alphabet (no 0/1/o/l/i) and displayed as
 * two 5-char groups ("ab2cd-ef3gh"). Verification normalises input (strips the
 * dash/spaces, lowercases) so a user can type it however they copied it.
 */
final class RecoveryCodes
{
    public const COUNT = 8;

    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789'; // 31 chars, no 0/1/i/l/o

    /**
     * Fresh plaintext codes to show the user once.
     *
     * @return array<int, string>
     */
    public static function generate(int $count = self::COUNT): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = self::one();
        }

        return $codes;
    }

    /**
     * Hash a set of plaintext codes for storage (normalised first, so a
     * verify against the same normalisation always matches).
     *
     * @param  array<int, string> $plain
     * @return array<int, string>
     */
    public static function hashAll(array $plain): array
    {
        return array_values(array_map(
            static fn (string $c): string => Hash::make(self::normalize($c)),
            $plain,
        ));
    }

    /**
     * Normalise user input: keep only alphanumerics, lowercase. "AB2-CD3 EFGHJ"
     * and "ab2cd3efghj" both reduce to the same comparable token.
     */
    public static function normalize(string $code): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /**
     * A recovery code is anything that isn't a bare 6-digit TOTP — used to
     * decide which verifier to run at the login challenge.
     */
    public static function looksLikeRecoveryCode(string $code): bool
    {
        return preg_match('/^\d{6}$/', self::normalize($code)) !== 1
            && strlen(self::normalize($code)) >= 8;
    }

    /**
     * Try to consume one code. Returns the REMAINING hashes (the matched one
     * removed) on success, or null when nothing matched.
     *
     * @param  array<int, string> $hashes stored recovery-code hashes
     * @return array<int, string>|null
     */
    public static function consume(array $hashes, string $input): ?array
    {
        $needle = self::normalize($input);
        if ($needle === '') {
            return null;
        }

        foreach ($hashes as $i => $hash) {
            if (is_string($hash) && Hash::check($needle, $hash)) {
                unset($hashes[$i]);

                return array_values($hashes);
            }
        }

        return null;
    }

    private static function one(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $raw = '';
        for ($i = 0; $i < 10; $i++) {
            $raw .= self::ALPHABET[random_int(0, $max)];
        }

        return substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }
}
