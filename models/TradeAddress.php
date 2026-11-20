<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * A one-line address ("12 High St, Leeds, West Yorkshire, LS1 4AP, UK") for the customer's address fields. The order of
 * UK address parts is a convention, not a rule, so it is not split into town and county: the whole text is kept in
 * address line 1. Two parts are reliable: a UK postcode at the end (optionally followed by a UK country name), which moves
 * to the postcode field, and a non-UK country name at the end ("..., Germany"), which moves to the country field so a
 * foreign address is never marked as UK. Country names come from the intl extension's ICU data; without it, nothing is
 * detected and the address is kept whole. Pure, no database.
 */
final class TradeAddress
{
    private const UK_NAMES = ['uk', 'u.k.', 'gb', 'gbr', 'great britain', 'united kingdom', 'england', 'scotland', 'wales', 'northern ireland'];
    private const POSTCODE = '/(?<![A-Z0-9])([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})(?![A-Z0-9])/i';

    /** @return array{address_line_1: string, postcode: string, country: string} the postcode tidied to "AB1 2CD" and the non-UK country name, each '' when there is none */
    public static function split(string $raw): array
    {
        $text = \trim((string) \preg_replace('/\s+/', ' ', \str_replace(["\r\n", "\r", "\n", ';'], ', ', $raw)), ' ,');
        $text = (string) \preg_replace('/(\s*,)+/', ',', $text);

        [$text, $country] = self::withoutCountry($text);
        $whole = ['address_line_1' => $text, 'postcode' => '', 'country' => $country];

        $found = \preg_match_all(self::POSTCODE, $text, $matches, PREG_OFFSET_CAPTURE) > 0;
        if (!$found) return $whole;

        $last = \end($matches[0]);
        $tail = \trim(\substr($text, $last[1] + \strlen($last[0])), ' ,');
        $notAtEnd = $tail !== '' && !\in_array(\strtolower($tail), self::UK_NAMES, true);
        if ($notAtEnd) return $whole; // a postcode-shaped bit in the middle of the text is not the postcode

        $head = \trim(\substr($text, 0, $last[1]), ' ,');
        $line = \trim($head . ($tail === '' ? '' : ', ' . $tail), ' ,');

        return ['address_line_1' => $line, 'country' => $country, 'postcode' => \strtoupper($matches[1][\count($matches[1]) - 1][0]) . ' ' . \strtoupper($matches[2][\count($matches[2]) - 1][0])];
    }

    /**
     * @return array{0: string, 1: string} the text without a trailing non-UK country name, and that country's name ('' when the last part is not one)
     */
    private static function withoutCountry(string $text): array
    {
        $comma = \strrpos($text, ',');
        $last = \trim($comma === false ? $text : \substr($text, $comma + 1));
        $head = $comma === false ? '' : \trim(\substr($text, 0, $comma), ' ,');

        $country = self::countryNamed($last);
        $none = $country === null || ($head === '' && $comma === false); // a lone word is the address, not a country
        if ($none) return [$text, ''];

        return [$head, $country];
    }

    /** The English name of the non-UK country this text names, or null (also when intl is not installed). */
    private static function countryNamed(string $name): ?string
    {
        $key = \mb_strtolower($name);
        $isUk = \in_array($key, self::UK_NAMES, true);
        $noIntl = !\class_exists(\ResourceBundle::class);
        if ($key === '' || $isUk || $noIntl) return null;

        $names = self::countryNames();

        return $names[$key] ?? null;
    }

    /** @return array<string, string> lower-case English name => name, for every country ICU knows */
    private static function countryNames(): array
    {
        static $names = null;
        if ($names !== null) return $names;

        $names = [];
        $bundle = \ResourceBundle::create('en', 'ICUDATA-region');
        foreach ($bundle === null ? [] : $bundle['Countries'] as $code => $name) {
            $isCountry = \strlen((string) $code) === 2 && !\ctype_digit((string) $code);
            if ($isCountry) $names[\mb_strtolower($name)] = $name;
        }

        return $names;
    }
}
