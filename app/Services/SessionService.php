<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Hash;
use UnexpectedValueException;

class SessionService
{
    /** Marks a session token made by passwordToken(); older sessions hold a bcrypt hash instead. */
    private const TOKEN_PREFIX = 'h1:';

    public function setLoginData(int | string | null $userId, string $password): void
    {
        session([
            'user_id' => $userId,
            'user_password' => $this->passwordToken($password),
        ]);
    }

    /**
     * Every game page checks the session still matches the account's password hash, so a password
     * change logs other sessions out. That check was a bcrypt verify (~60 ms on every page, 2026-09-30);
     * an HMAC of the same input keeps the guarantee for ~0.01 ms. Sessions from before the change
     * are verified with bcrypt once and upgraded in place, so nobody gets logged out.
     */
    public function checkPasswordToken(string $password, mixed $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        if (str_starts_with($token, self::TOKEN_PREFIX)) {
            return hash_equals($this->passwordToken($password), $token);
        }

        if (!Hash::check($password . '-' . $this->secretWord(), $token)) {
            return false;
        }

        session(['user_password' => $this->passwordToken($password)]);

        return true;
    }

    private function passwordToken(string $password): string
    {
        return self::TOKEN_PREFIX . hash_hmac('sha256', $password, $this->secretWord());
    }

    private function secretWord(): string
    {
        $secretWord = config('SECRETWORD');

        if (!is_string($secretWord)) {
            throw new UnexpectedValueException('SECRETWORD must be a string.');
        }

        return $secretWord;
    }
}
