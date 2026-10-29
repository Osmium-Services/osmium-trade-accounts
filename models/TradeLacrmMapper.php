<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Turns Less Annoying CRM data into the neutral customer Trade works with (email, company_name, contact_name,
 * phone, address lines, city, county, postcode, country, notes, source, source_ref). Pure: no database, no network.
 *
 * Which LACRM field becomes which column was worked out for SolarGo2's own CRM forms (form labels such as
 * "company name", "main company phone", "delivery address (if different to the above)"). It is the part most
 * likely to need adjusting for another site's form.
 */
final class TradeLacrmMapper
{
    public const SOURCE = 'lacrm';

    /** The columns a CRM change may set; anything else on a customer is left alone. */
    public const DETAIL_COLUMNS = [
        'email', 'email2', 'company_name', 'contact_name', 'phone', 'address_line_1', 'address_line_2',
        'city', 'county', 'postcode', 'country', 'notes',
    ];

    /**
     * A LACRM contact (GetContact) as a customer. A company contact has no person of its own, so the first linked
     * person gives the contact name, and the first linked person who has one gives the email or phone when the company
     * record has none (many companies keep their email only on their people).
     *
     * @param array<string, mixed> $contact
     * @param array<int, array<string, mixed>> $linkedPeople
     * @return array<string, mixed>
     */
    public static function contact(array $contact, array $linkedPeople = []): array
    {
        $address = self::contactAddress($contact);

        return [
            'email' => self::firstText($contact['Email'] ?? []) ?: self::firstPersonText($linkedPeople, 'Email'),
            'company_name' => \trim((string) ($contact['Company Name'] ?? '')),
            'contact_name' => self::linkedPersonName($linkedPeople),
            'phone' => self::firstText($contact['Phone'] ?? []) ?: self::firstPersonText($linkedPeople, 'Phone'),
            'address_line_1' => $address['street'],
            'city' => $address['city'],
            'county' => $address['state'],
            'postcode' => $address['zip'],
            'country' => $address['country'],
            'source' => self::SOURCE,
            'source_ref' => (string) ($contact['ContactId'] ?? ''),
        ];
    }

    /** A company contact has no person of its own, so its first linked person is fetched to give the contact name. */
    public static function needsLinkedPeople(array $contact): bool
    {
        $companyName = \trim((string) ($contact['Company Name'] ?? ''));

        return $companyName !== '' && !isset($contact['Name']);
    }

    /**
     * A public form submission (the FormFields list of label and value) as an applicant. The extra answers (VAT,
     * registration, delivery address, accounts contact) go into the notes.
     *
     * @param array<int, array<string, mixed>> $formFields
     * @return array<string, mixed>
     */
    public static function formSubmission(array $formFields): array
    {
        $byLabel = [];
        foreach ($formFields as $field) {
            $label = \strtolower(\trim((string) ($field['Label'] ?? '')));
            $byLabel[$label] = $field['Value'] ?? '';
        }

        $address = self::formAddress(byLabel: $byLabel, key: 'address');
        $delivery = self::formAddress(byLabel: $byLabel, key: 'delivery address (if different to the above)');

        return [
            'email' => \strtolower(\trim((string) ($byLabel['email'] ?? ''))),
            'company_name' => self::firstFilled($byLabel, ['company name', 'trading name (if different to the company name)']),
            'contact_name' => \trim((string) ($byLabel['name'] ?? '')),
            'phone' => self::firstFilled($byLabel, ['main company phone', 'phone (office number with line extension if applicable)']),
            'address_line_1' => $address['street'],
            'city' => $address['city'],
            'county' => $address['state'],
            'postcode' => $address['zip'],
            'country' => $address['country'],
            'notes' => self::formNotes(byLabel: $byLabel, delivery: $delivery),
            'source' => self::SOURCE,
            'source_ref' => '',
        ];
    }

    /**
     * The details a Contact.Update webhook carries, as customer columns. Only what the webhook says is returned.
     * A company contact has "Company Name" and no "Name"; a person has "Name" as {FirstName, LastName}.
     *
     * @param array<string, mixed> $contact
     * @return array<string, string>
     */
    public static function changes(array $contact): array
    {
        $changes = [];

        $name = $contact['Name'] ?? null;
        $companyName = $contact['Company Name'] ?? null;
        $isCompany = $name === null && $companyName !== null;
        $isPerson = \is_array($name);

        // Some payloads carry a plain-text Name and an IsCompany flag instead of "Company Name" / {FirstName, LastName}
        $plainName = \is_string($name) ? \trim($name) : '';
        $namedCompany = $plainName !== '' && ($contact['IsCompany'] ?? null) === true;
        $namedPerson = $plainName !== '' && ($contact['IsCompany'] ?? null) === false;
        if ($namedCompany) $companyName = $plainName;
        if ($namedCompany) $isCompany = true;

        $company = \trim((string) $companyName);
        if ($isCompany && $company !== '') $changes['company_name'] = $company;

        $person = $isPerson ? \trim(($name['FirstName'] ?? '') . ' ' . ($name['LastName'] ?? '')) : '';
        if ($namedPerson) $person = $plainName;
        if ($person !== '') $changes['contact_name'] = $person;

        $email = \strtolower(self::firstText($contact['Email'] ?? []));
        $emailUsable = $email !== '' && \filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        if ($emailUsable) $changes['email'] = $email;

        $phone = self::firstText($contact['Phone'] ?? []);
        if ($phone !== '') $changes['phone'] = $phone;

        return $changes + self::addressChanges($contact['Address'] ?? []);
    }

    /**
     * An existing customer with a CRM's changes laid over it, as the complete customer an "updated" event needs.
     *
     * @param array<string, mixed> $existing A customer row
     * @param array<string, string> $changes As changes() gives them
     * @return array<string, mixed>
     */
    public static function applyChanges(array $existing, array $changes): array
    {
        $customer = [];
        foreach (self::DETAIL_COLUMNS as $column) {
            $customer[$column] = $existing[$column] ?? '';
        }

        $customer = \array_merge($customer, $changes);
        $customer['source'] = self::SOURCE;
        $customer['source_ref'] = (string) ($existing['source_ref'] ?? '');

        return $customer;
    }

    // Contacts

    /** @param mixed $entries LACRM lists email and phone as [{Text, Type}, ...] */
    private static function firstText(mixed $entries): string
    {
        $notList = !\is_array($entries);
        if ($notList) return '';

        foreach ($entries as $entry) {
            $text = \trim((string) ($entry['Text'] ?? ''));
            $filled = $text !== '';
            if ($filled) return $text;
        }

        return '';
    }

    /** @return array{street: string, city: string, state: string, zip: string, country: string} */
    private static function contactAddress(array $contact): array
    {
        $addresses = $contact['Address'] ?? [];
        $none = !\is_array($addresses) || $addresses === [];
        $first = $none ? [] : $addresses[0];

        return [
            'street' => self::oneLine((string) ($first['Street'] ?? '')),
            'city' => \trim((string) ($first['City'] ?? '')),
            'state' => \trim((string) ($first['State'] ?? '')),
            'zip' => \trim((string) ($first['Zip'] ?? '')),
            'country' => \trim((string) ($first['Country'] ?? '')),
        ];
    }

    /** @return array<string, string> */
    private static function addressChanges(mixed $addresses): array
    {
        $none = !\is_array($addresses) || $addresses === [];
        if ($none) return [];

        $first = $addresses[0];
        $map = ['Street' => 'address_line_1', 'City' => 'city', 'State' => 'county', 'Zip' => 'postcode', 'Country' => 'country'];

        $changes = [];
        foreach ($map as $key => $column) {
            $present = isset($first[$key]);
            if (!$present) continue;

            $value = (string) $first[$key];
            $changes[$column] = $key === 'Street' ? self::oneLine($value) : \trim($value);
        }

        return $changes;
    }

    /** LACRM keeps a street over several lines; the customer has one line, so they are joined with commas. */
    private static function oneLine(string $street): string
    {
        $lines = \preg_split('/[\r\n]+/', \trim($street)) ?: [];
        $lines = \array_map(static fn(string $line): string => \trim($line, " ,\t"), $lines);

        return \implode(', ', \array_filter($lines, static fn(string $line): bool => $line !== ''));
    }

    /** The first email or phone among the linked people, '' when none has one. @param array<int, array<string, mixed>> $people */
    private static function firstPersonText(array $people, string $field): string
    {
        foreach ($people as $person) {
            $text = self::firstText($person[$field] ?? []);
            $filled = $text !== '';
            if ($filled) return $text;
        }

        return '';
    }

    /** @param array<int, array<string, mixed>> $people */
    private static function linkedPersonName(array $people): string
    {
        $name = $people[0]['Name'] ?? [];
        $notName = !\is_array($name);
        if ($notName) return '';

        return \trim(($name['FirstName'] ?? '') . ' ' . ($name['LastName'] ?? ''));
    }

    // Forms

    /** @return array{street: string, city: string, state: string, zip: string, country: string} */
    private static function formAddress(array $byLabel, string $key): array
    {
        $value = $byLabel[$key] ?? '';
        $empty = ['street' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => ''];

        if (\is_array($value)) {
            return [
                'street' => \trim((string) ($value['Street'] ?? $value['street'] ?? '')),
                'city' => \trim((string) ($value['City'] ?? $value['city'] ?? '')),
                'state' => \trim((string) ($value['State'] ?? $value['state'] ?? '')),
                'zip' => \trim((string) ($value['Zip'] ?? $value['zip'] ?? '')),
                'country' => \trim((string) ($value['Country'] ?? $value['country'] ?? '')),
            ];
        }

        $isText = \is_string($value) && $value !== '';

        return $isText ? ['street' => $value] + $empty : $empty;
    }

    /** The first of these labels that has an answer, trimmed ('' when none has). @param string[] $labels */
    private static function firstFilled(array $byLabel, array $labels): string
    {
        foreach ($labels as $label) {
            $answer = \trim((string) ($byLabel[$label] ?? ''));
            $filled = $answer !== '';
            if ($filled) return $answer;
        }

        return '';
    }

    /** @param array{street: string, city: string, state: string, zip: string, country: string} $delivery */
    private static function formNotes(array $byLabel, array $delivery): string
    {
        $notes = ['=== Imported from LACRM form ==='];

        $extras = [
            'Company Reg' => 'company registration number', 'VAT Number' => 'vat number', 'Website' => 'website',
            'Trading Name' => 'trading name (if different to the company name)', 'Job Title' => 'job title', 'Mobile' => 'phone (mobile)',
        ];
        foreach ($extras as $title => $label) {
            $answer = \trim((string) ($byLabel[$label] ?? ''));
            $filled = $answer !== '';
            if ($filled) $notes[] = "{$title}: {$answer}";
        }

        $hasDelivery = \array_filter($delivery) !== [];
        if ($hasDelivery) {
            $cityLine = \implode(', ', \array_filter([$delivery['city'], $delivery['state'], $delivery['zip']]));
            $notes[] = '';
            $notes[] = '=== Delivery address ===';
            $notes = \array_merge($notes, \array_filter([$delivery['street'], $cityLine, $delivery['country']]));
        }

        $accounts = self::accountsContact($byLabel);
        $hasAccounts = $accounts !== [];
        if ($hasAccounts) {
            $notes[] = '';
            $notes[] = '=== Accounts contact ===';
            $notes = \array_merge($notes, $accounts);
        }

        return \implode("\n", $notes);
    }

    /**
     * The accounts contact: any answer whose label mentions "accounts" and a contact name, email or phone.
     *
     * @return string[] lines such as "Name: Sam", empty when no such answer was given
     */
    private static function accountsContact(array $byLabel): array
    {
        $name = '';
        $email = '';
        $phone = '';

        foreach ($byLabel as $label => $value) {
            $answer = \trim((string) (\is_array($value) ? '' : $value));
            $relevant = \str_contains($label, 'accounts') && $answer !== '';
            if (!$relevant) continue;

            if (\str_contains($label, 'contact name')) $name = $answer;
            if (\str_contains($label, 'email')) $email = $answer;
            if (\str_contains($label, 'phone')) $phone = $answer;
        }

        $lines = [];
        if ($name !== '') $lines[] = "Name: {$name}";
        if ($email !== '') $lines[] = "Email: {$email}";
        if ($phone !== '') $lines[] = "Phone: {$phone}";

        return $lines;
    }
}
