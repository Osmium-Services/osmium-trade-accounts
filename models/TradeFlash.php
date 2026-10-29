<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * A one-time message carried in the session from a form handler to the page it sends the visitor to
 * (read once, then gone). The session is passed in by reference so a test can use a plain array.
 */
final class TradeFlash
{
    public const KEY = 'trade_flash';

    /** @param array<string, mixed> $session @param array<string, mixed> $values e.g. error, notice, email, sent */
    public static function set(array &$session, array $values): void
    {
        $session[self::KEY] = $values;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed> What was set, or an empty array; it is removed from the session
     */
    public static function take(array &$session): array
    {
        $values = $session[self::KEY] ?? [];
        unset($session[self::KEY]);

        return \is_array($values) ? $values : [];
    }
}
