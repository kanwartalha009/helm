<?php

declare(strict_types=1);

namespace App\Services\Encryption;

use Illuminate\Support\Facades\Crypt;

/**
 * Thin wrapper around Laravel's Crypt facade for use sites outside model casts.
 * Useful when persisting a transient token to a non-Eloquent store (cache, queue
 * payload) where we don't want the raw value in plaintext.
 *
 * Models that store encrypted values should use the 'encrypted' cast instead.
 */
final class TokenVault
{
    public function encrypt(string $plaintext): string
    {
        return Crypt::encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        return Crypt::decryptString($ciphertext);
    }
}
