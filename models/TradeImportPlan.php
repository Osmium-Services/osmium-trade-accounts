<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Turns spreadsheet rows and a column matching into what an import would do with each row: add the customer (with
 * defaults for missing or clashing details), skip it as a true duplicate, or reject the row with a reason. Pure:
 * existing customers are asked for through a callable, so this needs no database.
 *
 * No customer is dropped for being incomplete or for sharing an email. An email is one customer's login, so a row whose
 * email is already used by someone with a different (or no) phone is added with a placeholder email, and the notes keep
 * the original so the right address can be found by ringing the phone number. The same email and the same phone, or the
 * same email and the same company, is a true duplicate and is skipped, so running a file twice adds nothing the second time
 * (a customer with no phone number is still recognised by their company).
 */
final class TradeImportPlan
{
    public const NEW = 'new';
    public const EXISTS = 'exists';
    public const INVALID = 'invalid';
    public const PLACEHOLDER_DOMAIN = 'placeholder.invalid';
    private const LINE_MAX = 255;
    private const NOTES_MAX = 60000; // the notes column is TEXT (65,535 bytes)

    /**
     * @param array<int, array<int, string>> $rows The data rows, headings removed
     * @param array<string, int|null> $matching as TradeImportFields::clean() gives it
     * @param string $status The status every new customer gets (active or pending)
     * @param callable(string): ?array<string, mixed> $findByEmail The live customer using this email (primary or secondary), with at least `phone` and `company_name`, or null
     * @return array<int, array{line: int, result: string, reason: string, fixes: array<int, string>, data: ?array<string, mixed>}> one per row; line is the row number in the file (headings are line 1); fixes lists the defaults put in for missing, unusable or clashing details
     */
    public static function build(array $rows, array $matching, string $status, callable $findByEmail): array
    {
        $plan = [];
        $seen = []; // email => ['phone' => normalised phone, 'company_name' => ...] for rows already due to be added
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $input = self::input(row: $row, matching: $matching, status: $status);
            [$input, $addressFixes] = self::withSplitAddress(input: $input, raw: self::cell(row: $row, column: $matching['full_address'] ?? null));
            $data = TradeCustomerInput::parse($input);
            [$data, $fixes] = self::withDefaults(data: $data);
            $fixes = \array_merge($addressFixes, $fixes);

            $duplicate = self::duplicateOf(data: $data, findByEmail: $findByEmail, seen: $seen);
            if ($duplicate !== null) {
                $plan[] = ['line' => $line, 'fixes' => [], 'data' => $data, 'result' => self::EXISTS, 'reason' => $duplicate];
                continue;
            }

            [$data, $clashFixes] = self::withoutClashes(data: $data, findByEmail: $findByEmail, seen: $seen);
            $fixes = \array_merge($fixes, $clashFixes);

            $again = $clashFixes === [] ? null : self::duplicateOf(data: $data, findByEmail: $findByEmail, seen: $seen); // the placeholder from an earlier run is this same row
            if ($again !== null) {
                $plan[] = ['line' => $line, 'fixes' => [], 'data' => $data, 'result' => self::EXISTS, 'reason' => $again];
                continue;
            }

            $data = self::noted(data: $data, fixes: $fixes);

            $problem = TradeCustomerInput::validate($data);
            $result = $problem === null ? self::NEW : self::INVALID;
            $plan[] = ['line' => $line, 'fixes' => $fixes, 'data' => $data, 'result' => $result, 'reason' => (string) $problem];

            $isNew = $result === self::NEW;
            if ($isNew) $seen = self::remember(seen: $seen, data: $data);
        }

        return $plan;
    }

    /**
     * @param array<int, array{result: string, fixes: array<int, string>}> $plan
     * @return array{new: int, fixed: int, exists: int, invalid: int} fixed is the part of new that needs a default put in
     */
    public static function counts(array $plan): array
    {
        $counts = [self::NEW => 0, 'fixed' => 0, self::EXISTS => 0, self::INVALID => 0];
        foreach ($plan as $entry) {
            $counts[$entry['result']]++;
            $fixed = $entry['result'] === self::NEW && $entry['fixes'] !== [];
            if ($fixed) $counts['fixed']++;
        }

        return $counts;
    }

    /**
     * @param array<int, string> $row
     * @param array<string, int|null> $matching
     * @return array<string, mixed>
     */
    private static function input(array $row, array $matching, string $status): array
    {
        $input = ['status' => $status];
        foreach ($matching as $field => $column) {
            $input[$field] = self::cell(row: $row, column: $column);
        }
        $input['phone'] = self::phone((string) $input['phone']);

        return $input;
    }

    /** @param array<int, string> $row */
    private static function cell(array $row, ?int $column): string
    {
        return $column === null ? '' : (string) ($row[$column] ?? '');
    }

    /**
     * A one-line address fills the address line, postcode and (when it ends in a non-UK country name) country when they have no column of their own, so a separate
     * column always wins. An address longer than the field takes moves its overflow to the notes rather than losing it.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<int, string>} the input, and a note when the address overflowed
     */
    private static function withSplitAddress(array $input, string $raw): array
    {
        $none = \trim($raw) === '';
        if ($none) return [$input, []];

        $address = TradeAddress::split($raw);
        $lineFree = \trim((string) ($input['address_line_1'] ?? '')) === '';
        if ($lineFree) $input['address_line_1'] = $address['address_line_1'];

        $postcodeFree = \trim((string) ($input['postcode'] ?? '')) === '';
        if ($postcodeFree) $input['postcode'] = $address['postcode'];

        $countryFree = \trim((string) ($input['country'] ?? '')) === '';
        if ($countryFree && $address['country'] !== '') $input['country'] = $address['country'];

        $over = $lineFree && \mb_strlen($address['address_line_1']) > self::LINE_MAX;
        if (!$over) return [$input, []];

        $cut = \mb_strrpos(\mb_substr($address['address_line_1'], 0, self::LINE_MAX), ' ') ?: self::LINE_MAX;
        $input['address_line_1'] = \rtrim(\mb_substr($address['address_line_1'], 0, $cut), ' ,');
        $input['notes'] = \trim(($input['notes'] ?? '') . "\n\nAddress continued: " . \trim(\mb_substr($address['address_line_1'], $cut), ' ,'));

        return [$input, ['Address was longer than the field, the rest is in the notes']];
    }

    /** Excel drops the leading 0 of a number it thinks is a number ("07700 900123" becomes 7700900123); put it back on a bare 10-digit UK number. */
    private static function phone(string $phone): string
    {
        $lostZero = (bool) \preg_match('/^[1-9]\d{9}$/', $phone);

        return $lostZero ? '0' . $phone : $phone;
    }

    /** Digits only, with +44 or 0044 as 0, so "+44 7700 900101" and "07700 900101" are the same number; '' when there is none. */
    private static function normalPhone(?string $phone): string
    {
        $digits = (string) \preg_replace('/\D+/', '', (string) $phone);
        $international = (string) \preg_replace('/^(0044|44)(?=\d{9,10}$)/', '0', $digits);

        return $international;
    }

    /**
     * Missing company name and unusable emails get defaults, and the notes say so, so a customer is never dropped for being incomplete.
     *
     * @param array<string, mixed> $data As TradeCustomerInput::parse() gives it
     * @return array{0: array<string, mixed>, 1: array<int, string>} the details with defaults, and what was defaulted
     */
    private static function withDefaults(array $data): array
    {
        $fixes = [];

        $noCompany = $data['company_name'] === '';
        if ($noCompany) {
            $data['company_name'] = $data['contact_name'] ?: 'Unknown company';
            $fixes[] = 'No company name, used "' . $data['company_name'] . '"';
        }

        $emailBad = !\filter_var($data['email'], FILTER_VALIDATE_EMAIL) || \mb_strlen($data['email']) > TradeCustomerInput::limits()['email'];
        if ($emailBad) {
            $original = $data['email'] === '' ? 'blank' : '"' . \mb_substr($data['email'], 0, 300) . '"';
            $data['email'] = self::placeholder($data);
            $fixes[] = "Email was {$original}, placeholder used";
        }

        $email2Bad = $data['email2'] !== null && !\filter_var($data['email2'], FILTER_VALIDATE_EMAIL);
        if ($email2Bad) {
            $fixes[] = 'Second email "' . $data['email2'] . '" is not valid, left out';
            $data['email2'] = null;
        }

        [$data, $cut] = self::withoutOverlong($data);

        return [$data, \array_merge($fixes, $cut)];
    }

    /**
     * A value too long for its field is cut to fit and its full text goes in the notes, so no customer is rejected for it.
     *
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private static function withoutOverlong(array $data): array
    {
        $fixes = [];
        foreach (TradeCustomerInput::limits() as $field => $limit) {
            $skip = $field === 'email' || $field === 'email2'; // an overlong email is handled as an unusable one
            $value = (string) ($data[$field] ?? '');
            if ($skip || \mb_strlen($value) <= $limit) continue;

            $data[$field] = \rtrim(\mb_substr($value, 0, $limit));
            $fixes[] = \ucfirst(\str_replace('_', ' ', $field)) . " was longer than {$limit} characters and was cut. Full text: {$value}";
        }

        return [$data, $fixes];
    }

    /**
     * A placeholder email made from the row's own details, so the same row always gets the same one and a second run
     * finds it and skips the row instead of adding a copy.
     *
     * @param array<string, mixed> $data
     */
    private static function placeholder(array $data): string
    {
        $fingerprint = \strtolower($data['company_name'] . '|' . $data['contact_name'] . '|' . $data['phone'] . '|' . $data['postcode'] . '|' . $data['email']);

        return 'no-email-' . \substr(\sha1($fingerprint), 0, 12) . '@' . self::PLACEHOLDER_DOMAIN;
    }

    /**
     * Why this row is a true duplicate (same email and same phone, or same email and same company, as someone already there or earlier in the file), or null.
     * A placeholder email is made from the row itself, so finding it again means the same row.
     *
     * @param array<string, mixed> $data
     * @param array<string, array{phone: string, company_name: string}> $seen
     */
    private static function duplicateOf(array $data, callable $findByEmail, array $seen): ?string
    {
        $email = (string) $data['email'];
        $earlier = $seen[$email] ?? null;
        $existing = $findByEmail($email);
        $holder = $earlier ?? ($existing === null ? null : ['phone' => self::normalPhone($existing['phone'] ?? ''), 'company_name' => (string) $existing['company_name']]);
        $free = $holder === null;
        if ($free) return null;

        $where = $earlier !== null ? 'an earlier row' : 'an existing customer';
        $placeholder = \str_ends_with($email, '@' . self::PLACEHOLDER_DOMAIN);
        $phone = self::normalPhone($data['phone']);
        $samePhone = $phone !== '' && $phone === $holder['phone'];
        $sameCompany = \mb_strtolower(\trim((string) $data['company_name'])) === \mb_strtolower(\trim($holder['company_name']));
        $same = $placeholder || $samePhone || $sameCompany;

        return $same ? "Same email and " . ($sameCompany && !$samePhone ? 'company' : 'phone') . " as {$where} ({$holder['company_name']})" : null;
    }

    /**
     * An email or second email another customer already has (and a different phone) cannot be shared: the row gets a
     * placeholder email, or loses its second email, and the notes keep the original.
     *
     * @param array<string, mixed> $data
     * @param array<string, array{phone: string, company_name: string}> $seen
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private static function withoutClashes(array $data, callable $findByEmail, array $seen): array
    {
        $fixes = [];

        $taken = self::holder(email: (string) $data['email'], findByEmail: $findByEmail, seen: $seen);
        if ($taken !== null) {
            $fixes[] = "Email \"{$data['email']}\" is already used by {$taken}, placeholder used";
            $data['email'] = self::placeholder($data);
        }

        $email2 = (string) ($data['email2'] ?? '');
        $taken2 = $email2 === '' ? null : self::holder(email: $email2, findByEmail: $findByEmail, seen: $seen);
        $clash2 = $taken2 !== null || $email2 === $data['email'];
        if ($clash2) {
            $fixes[] = "Second email \"{$email2}\" is already used" . ($taken2 !== null ? " by {$taken2}" : '') . ', left out';
            $data['email2'] = null;
        }

        return [$data, $fixes];
    }

    /**
     * @param array<string, array{phone: string, company_name: string}> $seen
     * @return string|null the company that has this email, from the file or the database
     */
    private static function holder(string $email, callable $findByEmail, array $seen): ?string
    {
        $earlier = $seen[$email] ?? null;
        if ($earlier !== null) return $earlier['company_name'] . ' (earlier in the file)';

        $existing = $findByEmail($email);

        return $existing === null ? null : (string) $existing['company_name'];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fixes
     * @return array<string, mixed> with a line in the notes saying what was filled in
     */
    private static function noted(array $data, array $fixes): array
    {
        $none = $fixes === [];
        if ($none) return $data;

        $notes = \trim(($data['notes'] ?? '') . "\n\nImport: " . \implode('. ', $fixes) . '.');
        $data['notes'] = \mb_substr($notes, 0, self::NOTES_MAX);

        return $data;
    }

    /**
     * @param array<string, array{phone: string, company_name: string}> $seen
     * @param array<string, mixed> $data
     * @return array<string, array{phone: string, company_name: string}>
     */
    private static function remember(array $seen, array $data): array
    {
        $entry = ['phone' => self::normalPhone($data['phone']), 'company_name' => (string) $data['company_name']];
        foreach (\array_filter([$data['email'], $data['email2']]) as $email) {
            $seen[$email] = $entry;
        }

        return $seen;
    }
}
