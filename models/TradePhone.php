<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * A phone number shown in the admin lists as a tap-to-call link when it looks dialable.
 */
final class TradePhone
{
    /** @return string|null The number as a tel: link target (digits, optional leading +), or null when it is not a usable number */
    public static function dialable(string $phone): ?string
    {
        $cleaned = \str_replace('(0)', '', $phone); // "+44 (0)1283 ..." is dialled without the 0
        $cleaned = \preg_replace('/[\s\-.()]/', '', $cleaned) ?? '';

        $isNumber = \preg_match('/^\+?\d{7,15}$/', $cleaned) === 1;

        return $isNumber ? $cleaned : null;
    }

    /** The cell HTML: a call link for a dialable number, the plain text otherwise, '-' when empty. */
    public static function html(string $phone): string
    {
        $shown = \htmlspecialchars($phone);
        $number = self::dialable($phone);
        $hasNumber = $number !== null;
        if ($hasNumber) return '<a href="tel:' . \htmlspecialchars($number) . '">' . $shown . '</a>';

        return $phone === '' ? '-' : $shown;
    }
}
