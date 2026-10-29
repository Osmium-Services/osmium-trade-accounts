<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * The passwordless-login rules as plain functions: no database, no session, no clock of its own
 * (callers pass the time). The numbers are the defaults; settings may override them later.
 */
final class TradeLoginRules
{
    public const LINK_MINUTES = 30;
    public const CODE_MINUTES = 5;
    public const LINK_EMAIL_SECONDS = 60; // one link request per email per minute
    public const CODE_RESEND_SECONDS = 60;
    public const IP_WINDOW_SECONDS = 900; // 15 minutes
    public const IP_MAX_REQUESTS = 10;
    public const LOCKOUT_THRESHOLD = 10; // failures that lock the account
    public const LOCKOUT_SECONDS = 3600;
    public const CODE_MAX_WRONG = 3; // wrong codes before the login is abandoned

    /** What an attempt is recorded as. */
    public const REASONS = [
        'link_sent', 'unknown_email', 'inactive', 'locked', 'email_rate_limited', 'ip_rate_limited',
        'link_invalid', 'link_expired', 'link_used', 'code_invalid', 'login_success',
    ];

    public static function isCodeFormat(string $code): bool
    {
        return \preg_match('/^\d{6}$/', $code) === 1;
    }

    /** @return string A random 6-digit code, leading zeros kept */
    public static function newCode(): string
    {
        return \str_pad(string: (string) \random_int(min: 0, max: 999999), length: 6, pad_string: '0', pad_type: STR_PAD_LEFT);
    }

    /** @return string A long random token for a login link (64 hex characters) */
    public static function newToken(): string
    {
        return \bin2hex(\random_bytes(32));
    }

    /** What is stored for a token, so a copy of the table cannot log anyone in. */
    public static function tokenHash(string $token): string
    {
        return \hash(algo: 'sha256', data: $token);
    }

    /** A link in the shape a login link should have, so junk is refused before touching the database. */
    public static function isTokenFormat(string $token): bool
    {
        return \preg_match('/^[0-9a-f]{64}$/', $token) === 1;
    }

    /** True while the lock runs ($lockoutUntil is the stored datetime, or null/empty for none). */
    public static function isLocked(?string $lockoutUntil, int $now): bool
    {
        $none = $lockoutUntil === null || $lockoutUntil === '';
        if ($none) return false;

        $until = \strtotime($lockoutUntil);

        return $until !== false && $until > $now;
    }

    public static function shouldLock(int $failedAttempts, int $threshold = self::LOCKOUT_THRESHOLD): bool
    {
        return $failedAttempts >= $threshold;
    }

    public static function ipOverLimit(int $requestsInWindow, int $max = self::IP_MAX_REQUESTS): bool
    {
        return $requestsInWindow >= $max;
    }

    /**
     * Why a link did not work: it never existed, was already used, or ran out.
     *
     * @param array{used_at: ?string, expires_at: string}|null $link The stored row for the token, if any
     */
    public static function linkFailure(?array $link, int $now): string
    {
        $unknown = $link === null;
        if ($unknown) return 'link_invalid';

        $used = !empty($link['used_at']);
        if ($used) return 'link_used';

        $expired = \strtotime($link['expires_at']) <= $now;
        if ($expired) return 'link_expired';

        return 'link_invalid';
    }

    public static function linkFailureMessage(string $reason): string
    {
        return match ($reason) {
            'link_expired' => 'This login link has expired. Please request a new one.',
            'link_used' => 'This login link has already been used. Please request a new one.',
            default => 'Invalid login link. Please request a new one.',
        };
    }

    public static function wrongCodesLeft(int $wrongSoFar, int $max = self::CODE_MAX_WRONG): int
    {
        return \max(0, $max - $wrongSoFar);
    }

    public static function codeAbandoned(int $wrongSoFar, int $max = self::CODE_MAX_WRONG): bool
    {
        return $wrongSoFar >= $max;
    }
}
