<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * The customer fields a spreadsheet column can fill, a first guess at which column is which from the headings, and a
 * check that a chosen matching is usable. A matching is field => column index (or null for "not in my file").
 */
final class TradeImportFields
{
    /** field => label, whether it must be matched, and the headings (lower case, letters and digits only) that suggest it */
    private const FIELDS = [
        'company_name' => ['label' => 'Company name', 'required' => true, 'headings' => ['company', 'companyname', 'business', 'businessname', 'organisation', 'organization', 'organisationname', 'accountname', 'account', 'customer', 'customername', 'tradingname']],
        'contact_name' => ['label' => 'Contact name', 'required' => false, 'headings' => ['contact', 'contactname', 'primarycontact', 'keycontact', 'contactfullname', 'name', 'fullname', 'contactperson']],
        'email' => ['label' => 'Email', 'required' => true, 'headings' => ['email', 'emailaddress', 'primaryemail', 'mail', 'email1', 'emailaddress1']],
        'email2' => ['label' => 'Second email', 'required' => false, 'headings' => ['email2', 'secondemail', 'secondaryemail', 'altemail', 'alternativeemail', 'otheremail', 'emailaddress2']],
        'phone' => ['label' => 'Phone', 'required' => false, 'headings' => ['phone', 'telephone', 'tel', 'mobile', 'phonenumber', 'phone1', 'telephonenumber', 'contactnumber']],
        'full_address' => ['label' => 'Full address (one column)', 'required' => false, 'headings' => ['fulladdress', 'completeaddress', 'addressfull', 'wholeaddress', 'address', 'location']],
        'address_line_1' => ['label' => 'Address line 1', 'required' => false, 'headings' => ['address1', 'addressline1', 'street', 'streetaddress', 'line1', 'addr1']],
        'address_line_2' => ['label' => 'Address line 2', 'required' => false, 'headings' => ['address2', 'addressline2', 'line2', 'addr2']],
        'city' => ['label' => 'Town / city', 'required' => false, 'headings' => ['city', 'town', 'towncity', 'townorcity']],
        'county' => ['label' => 'County', 'required' => false, 'headings' => ['county', 'state', 'region', 'province']],
        'postcode' => ['label' => 'Postcode', 'required' => false, 'headings' => ['postcode', 'postalcode', 'zip', 'zipcode', 'postzipcode']],
        'country' => ['label' => 'Country', 'required' => false, 'headings' => ['country']],
        'notes' => ['label' => 'Notes', 'required' => false, 'headings' => ['notes', 'note', 'comments', 'comment', 'memo']],
    ];

    /** @return array<int, array{field: string, label: string, required: bool}> in the order they are shown */
    public static function describe(): array
    {
        $described = [];
        foreach (self::FIELDS as $field => $info) {
            $described[] = ['field' => $field, 'label' => $info['label'], 'required' => $info['required']];
        }

        return $described;
    }

    /**
     * @param array<int, string> $headings The first row of the file
     * @return array<string, int|null> field => the column whose heading matches it, each column used once
     */
    public static function guess(array $headings): array
    {
        $normal = \array_map(static fn(string $heading) => (string) \preg_replace('/[^a-z0-9]/', '', \strtolower($heading)), $headings);

        $matching = [];
        $used = [];
        foreach (self::FIELDS as $field => $info) {
            $matching[$field] = null;
            foreach ($normal as $column => $heading) {
                $fits = \in_array($heading, $info['headings'], true) && !isset($used[$column]);
                if (!$fits) continue;

                $matching[$field] = $column;
                $used[$column] = true;
                break;
            }
        }

        $separate = $matching['address_line_1'] !== null || $matching['city'] !== null || $matching['postcode'] !== null;
        $firstLine = $matching['full_address'] !== null && $separate; // "Address" beside Town and Postcode columns is the first line, not the whole address
        if ($firstLine) {
            $matching['address_line_1'] ??= $matching['full_address'];
            $matching['full_address'] = null;
        }

        return $matching;
    }

    /**
     * @param array<string, mixed> $matching as the browser sent it: field => column index, or empty for none
     * @return array<string, int|null> only the known fields, columns as ints
     */
    public static function clean(array $matching, int $columnCount): array
    {
        $clean = [];
        foreach (self::FIELDS as $field => $info) {
            $value = $matching[$field] ?? '';
            $isColumn = \is_numeric($value) && (int) $value >= 0 && (int) $value < $columnCount;
            $clean[$field] = $isColumn ? (int) $value : null;
        }

        return $clean;
    }

    /**
     * @param array<string, int|null> $matching as clean() gives it
     * @return string|null The first problem in plain words, or null when the matching can be used
     */
    public static function problem(array $matching): ?string
    {
        foreach (self::FIELDS as $field => $info) {
            $missing = $info['required'] && $matching[$field] === null;
            if ($missing) return 'Choose which column holds the ' . \strtolower($info['label']);
        }

        $columns = \array_filter($matching, static fn($column) => $column !== null);
        $twice = \count($columns) !== \count(\array_unique($columns));
        if ($twice) return 'Each column can only be used for one field';

        return null;
    }
}
