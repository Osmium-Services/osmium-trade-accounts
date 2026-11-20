<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\Validator;

/**
 * A phone number shown in the admin lists as a tap-to-call link when core's Validator accepts it as a UK number.
 */
final class TradePhone
{
    /** @return string|null The number as a tel: link target (digits, optional leading +), or null when it is not a UK landline or mobile */
    public static function dialable(string $phone): ?string
    {
        $isUkNumber = (new Validator())->isValidUkPhone($phone);
        if (!$isUkNumber) return null;

        $cleaned = \str_replace('(0)', '', $phone); // "+44 (0)1283 ..." is dialled without the 0
        $cleaned = \preg_replace('/[\s\-.()]/', '', $cleaned) ?? '';

        return \preg_replace('/^0044/', '+44', $cleaned) ?? $cleaned;
    }

    /** The cell HTML: a call link for a dialable number, the plain text otherwise, '-' when empty. */
    public static function html(string $phone): string
    {
        $shown = \htmlspecialchars($phone);
        $number = self::dialable($phone);
        $hasNumber = $number !== null;
        if ($hasNumber) return '<a href="tel:' . \htmlspecialchars($number) . '" title="Call customer">' . $shown . '</a>';

        return $phone === '' ? '-' : $shown;
    }
}
