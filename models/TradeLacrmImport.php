<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Brings in everyone LACRM holds at its Won status as active customers, the same as a won sale announced one at a
 * time. Contacts that are already customers are left alone: LACRM's webhook keeps them current. Safe to run twice.
 * The other way round, suspends the customers LACRM holds at its Lost status, to catch up on sales lost before the
 * webhook was registered or while it was missed.
 * Everything outside is passed in, so this can be tested without LACRM or a database.
 */
final class TradeLacrmImport
{
    /**
     * @param callable(string, array): mixed $ask Asks another service a question; errors are allowed to throw
     * @param callable(string, array): void $announce Fires a neutral event
     * @param callable(string): ?array $findByRef The live customer with this LACRM contact id, or null
     * @param callable(string): ?string $emailOwner The company already tied to another contact that uses this email
     *        (an email is one customer's login), or null when it is free or only a hand-made customer has it
     */
    public function __construct(private $ask, private $announce, private $findByRef, private $emailOwner)
    {
    }

    /**
     * @return array{new: array<int, array{id: string, name: string}>, existing: int}|null null when LACRM is not
     *         connected or has no Won status set
     */
    public function preview(): ?array
    {
        $items = ($this->ask)('lacrm.sales.won', []);
        $unavailable = !\is_array($items);
        if ($unavailable) return null;

        $new = [];
        $existing = 0;
        foreach (self::contactsAmong($items) as $contact) {
            $isCustomer = ($this->findByRef)($contact['id']) !== null;
            if ($isCustomer) $existing++;
            if (!$isCustomer) $new[] = $contact;
        }

        return ['new' => $new, 'existing' => $existing];
    }

    /** @return array{imported: int, existing: int, skipped: string[]}|null null as preview() */
    public function run(): ?array
    {
        $preview = $this->preview();
        $unavailable = $preview === null;
        if ($unavailable) return null;

        $found = ($this->ask)('lacrm.contacts.get_many', ['contactIds' => \array_column($preview['new'], 'id')]);
        $contacts = \is_array($found) ? ($found['results'] ?? []) : [];
        $errors = \is_array($found) ? ($found['errors'] ?? []) : [];

        $imported = 0;
        $skipped = [];
        foreach ($preview['new'] as $row) {
            $problem = $this->importOne(id: $row['id'], contact: $contacts[$row['id']] ?? null, fetchError: (string) ($errors[$row['id']] ?? ''));
            if ($problem === null) $imported++;
            if ($problem !== null) $skipped[] = "{$row['name']}: {$problem}";
        }

        return ['imported' => $imported, 'existing' => $preview['existing'], 'skipped' => $skipped];
    }

    /**
     * Customers LACRM says are lost and who can still be suspended (active or pending: a closed account stays closed).
     *
     * @return array{suspend: array<int, array{id: string, name: string}>, ignored: int}|null null as preview()
     */
    public function previewLost(): ?array
    {
        $items = ($this->ask)('lacrm.sales.lost', []);
        $unavailable = !\is_array($items);
        if ($unavailable) return null;

        $suspend = [];
        $ignored = 0;
        foreach (self::contactsAmong($items) as $contact) {
            $customer = ($this->findByRef)($contact['id']);
            $canSuspend = $customer !== null && \in_array($customer['status'], [TradeStatus::ACTIVE, TradeStatus::PENDING], true);
            if ($canSuspend) $suspend[] = ['id' => $contact['id'], 'name' => (string) $customer['company_name']];
            if (!$canSuspend) $ignored++;
        }

        return ['suspend' => $suspend, 'ignored' => $ignored];
    }

    /** @return array{suspended: int, ignored: int, failed: string[]}|null null as preview() */
    public function suspendLost(): ?array
    {
        $preview = $this->previewLost();
        $unavailable = $preview === null;
        if ($unavailable) return null;

        $suspended = 0;
        $failed = [];
        foreach ($preview['suspend'] as $row) {
            ($this->announce)('trade.customer.suspended', ['customer' => ['source' => TradeLacrmMapper::SOURCE, 'source_ref' => $row['id']]]);

            $now = ($this->findByRef)($row['id']);
            $done = $now !== null && $now['status'] === TradeStatus::SUSPENDED;
            if ($done) $suspended++;
            if (!$done) $failed[] = "{$row['name']}: was not suspended";
        }

        return ['suspended' => $suspended, 'ignored' => $preview['ignored'], 'failed' => $failed];
    }

    // Inside

    /** @return string|null Why this contact was not imported, or null when it was */
    private function importOne(string $id, ?array $contact, string $fetchError): ?string
    {
        $missing = $contact === null;
        if ($missing) return 'could not be fetched from LACRM' . ($fetchError === '' ? '' : " ({$fetchError})");

        try {
            $people = $this->linkedPeople(contact: $contact, id: $id);
            $customer = TradeLacrmMapper::contact($contact, $people);
            $customer['source_ref'] = $id;

            $problem = TradeCustomerInput::validate(TradeCustomerInput::parse($customer));
            if ($problem !== null) return $problem;

            $owner = ($this->emailOwner)(\strtolower((string) $customer['email']));
            if ($owner !== null) return "its email is already used by {$owner}, and an email can belong to only one customer";

            ($this->announce)('trade.customer.approved', ['customer' => $customer]);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        $saved = ($this->findByRef)($id) !== null;

        return $saved ? null : 'was not saved';
    }

    /** @return array<int, array<string, mixed>> */
    private function linkedPeople(array $contact, string $id): array
    {
        $notCompany = !TradeLacrmMapper::needsLinkedPeople($contact);
        if ($notCompany) return [];

        $people = ($this->ask)('lacrm.contact.linked_people', ['companyId' => $id, 'companyName' => \trim((string) $contact['Company Name'])]);

        return \is_array($people) ? $people : [];
    }

    /**
     * @param array<int, mixed> $items Pipeline items
     * @return array<int, array{id: string, name: string}> each contact once (a list, because PHP turns a numeric-string key into an int)
     */
    private static function contactsAmong(array $items): array
    {
        $contacts = [];
        $seen = [];
        foreach ($items as $item) {
            $id = \is_array($item) ? (string) ($item['ContactId'] ?? '') : '';
            $usable = $id !== '' && !isset($seen[$id]);
            if (!$usable) continue;

            $seen[$id] = true;
            $contacts[] = ['id' => $id, 'name' => (string) ($item['ContactMetaData']['Name'] ?? 'Unknown')];
        }

        return $contacts;
    }
}
