<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Turns submitted customer details (an admin form or a source's neutral event)
 * into clean values, and says what is wrong with them. No database, no globals.
 */
final class TradeCustomerInput
{
    private const MAX = ['email' => 255, 'email2' => 255, 'company_name' => 255, 'contact_name' => 255, 'phone' => 50,
        'address_line_1' => 255, 'address_line_2' => 255, 'city' => 100, 'county' => 100, 'postcode' => 20, 'country' => 100];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> every customer column an admin or source may set
     */
    public static function parse(array $input): array
    {
        $status = (string) ($input['status'] ?? TradeStatus::PENDING);
        $country = self::text($input['country'] ?? '');
        $email2 = \strtolower(self::text($input['email2'] ?? ''));
        $contactName = self::text($input['contact_name'] ?? '');

        return [
            'email' => \strtolower(self::text($input['email'] ?? '')),
            'email2' => $email2 === '' ? null : $email2,
            'company_name' => self::text($input['company_name'] ?? ''),
            'contact_name' => $contactName === '' ? null : $contactName,
            'phone' => self::nullIfEmpty($input['phone'] ?? ''),
            'address_line_1' => self::nullIfEmpty($input['address_line_1'] ?? ''),
            'address_line_2' => self::nullIfEmpty($input['address_line_2'] ?? ''),
            'city' => self::nullIfEmpty($input['city'] ?? ''),
            'county' => self::nullIfEmpty($input['county'] ?? ''),
            'postcode' => self::postcode($input['postcode'] ?? ''),
            'country' => $country === '' ? 'United Kingdom' : $country,
            'status' => TradeStatus::isValid($status) ? $status : TradeStatus::PENDING,
            'source' => self::nullIfEmpty($input['source'] ?? ''),
            'source_ref' => self::nullIfEmpty($input['source_ref'] ?? ''),
            'notes' => self::nullIfEmpty($input['notes'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $data As parse() gives it
     * @return string|null The first problem in plain words, or null if the details are fine
     */
    public static function validate(array $data): ?string
    {
        $noEmail = $data['email'] === '';
        if ($noEmail) return 'Primary email is required';

        $emailBad = !\filter_var($data['email'], FILTER_VALIDATE_EMAIL);
        if ($emailBad) return 'Invalid primary email address';

        $hasEmail2 = $data['email2'] !== null;
        $email2Bad = $hasEmail2 && !\filter_var($data['email2'], FILTER_VALIDATE_EMAIL);
        if ($email2Bad) return 'Invalid secondary email address';

        $noCompany = $data['company_name'] === '';
        if ($noCompany) return 'Company name is required';

        return self::tooLong(data: $data);
    }

    /** @param array<string, mixed> $data */
    private static function tooLong(array $data): ?string
    {
        foreach (self::MAX as $field => $limit) {
            $value = (string) ($data[$field] ?? '');
            $over = \mb_strlen($value) > $limit;
            if ($over) return \ucfirst(\str_replace('_', ' ', $field)) . " must be {$limit} characters or fewer";
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? \trim((string) $value) : '';
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === '' ? null : $text;
    }

    /** Postcodes are kept upper case with single spaces, so "sw1a  1aa" and "SW1A 1AA" are one thing. */
    private static function postcode(mixed $value): ?string
    {
        $text = \strtoupper(self::text($value));
        $collapsed = (string) \preg_replace('/\s+/', ' ', $text);

        return $collapsed === '' ? null : $collapsed;
    }
}
