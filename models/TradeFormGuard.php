<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * The checks every public Trade form passes before anything happens: the session's form token (one use), a hidden
 * honeypot field that must stay empty, and optionally a minimum time since the token was issued. Pure: the post and
 * session arrays and the clock are passed in.
 */
final class TradeFormGuard
{
    public const MIN_SECONDS_LOGIN = 4; // a person cannot read and fill the login form faster

    /** @param array<string, mixed> $post @param array<string, mixed> $session */
    public static function tokenMatches(array $post, array $session): bool
    {
        $submitted = (string) ($post['token'] ?? '');
        $stored = (string) ($session['token'] ?? '');
        $present = $submitted !== '' && $stored !== '';
        if (!$present) return false;

        return \hash_equals(known_string: $stored, user_string: $submitted);
    }

    /** @param array<string, mixed> $post */
    public static function honeypotFilled(array $post): bool
    {
        return \trim((string) ($post[TradeClaim::HONEYPOT] ?? '')) !== '';
    }

    /** @param array<string, mixed> $session */
    public static function tooFast(array $session, int $now, int $minSeconds): bool
    {
        $noMinimum = $minSeconds <= 0;
        if ($noMinimum) return false;

        $issuedAt = (int) ($session['token_issued_at'] ?? 0);
        $unknown = $issuedAt === 0;
        if ($unknown) return true;

        return $now - $issuedAt < $minSeconds;
    }

    /** What the session holds after a submission: the token is spent whether or not the check passed. @param array<string, mixed> $session @return array<string, mixed> */
    public static function spend(array $session): array
    {
        unset($session['token'], $session['token_issued_at']);

        return $session;
    }
}
