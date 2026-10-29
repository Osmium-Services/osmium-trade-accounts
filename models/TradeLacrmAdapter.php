<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Turns what Less Annoying CRM announces into Trade's neutral events, when LACRM is the selected customer source.
 * It lives in Trade so the LACRM service stays a plain connection that knows nothing about trade accounts.
 *
 *   form submitted      -> trade.application.received   (a new applicant, pending)
 *   sale won            -> trade.customer.approved      (the contact becomes, or is made, an active customer)
 *   sale lost           -> trade.customer.suspended
 *   contact updated     -> trade.customer.updated       (only for contacts that are already customers)
 *
 * Everything outside is passed in, so this can be tested without LACRM, a database or a network.
 */
final class TradeLacrmAdapter
{
    /**
     * @param callable(string, array): mixed $ask Asks another service a question (a handle hook); null when nobody answers
     * @param callable(string, array): void $announce Fires a neutral event
     * @param callable(string): ?array $findByRef The live customer with this LACRM contact id, or null
     * @param callable(string): void $log
     */
    public function __construct(
        private string $selectedSource,
        private $ask,
        private $announce,
        private $findByRef,
        private $log,
    ) {
    }

    /** @param array<string, mixed> $payload lacrm.form.submitted */
    public function formSubmitted(array $payload): void
    {
        if (!$this->isSelected()) return;

        $fields = $payload['fields'] ?? [];
        $empty = !\is_array($fields) || $fields === [];
        if ($empty) return;

        ($this->announce)('trade.application.received', ['customer' => TradeLacrmMapper::formSubmission($fields)]);
    }

    /** @param array<string, mixed> $payload lacrm.sale.won */
    public function saleWon(array $payload): void
    {
        if (!$this->isSelected()) return;

        $contactId = (string) ($payload['contactId'] ?? '');
        $noContact = $contactId === '';
        if ($noContact) return;

        $contact = $this->askQuietly('lacrm.contact.get', ['contactId' => $contactId]);
        $unavailable = !\is_array($contact); // LACRM is off, or the lookup failed (logged)
        if ($unavailable) return;

        $people = $this->linkedPeople(contact: $contact, contactId: $contactId);
        $customer = TradeLacrmMapper::contact($contact, $people);
        $customer['source_ref'] = $contactId;

        ($this->announce)('trade.customer.approved', ['customer' => $customer]);
    }

    /** @param array<string, mixed> $payload lacrm.sale.lost */
    public function saleLost(array $payload): void
    {
        if (!$this->isSelected()) return;

        $contactId = (string) ($payload['contactId'] ?? '');
        $noContact = $contactId === '';
        if ($noContact) return;

        ($this->announce)('trade.customer.suspended', ['customer' => ['source' => TradeLacrmMapper::SOURCE, 'source_ref' => $contactId]]);
    }

    /** @param array<string, mixed> $payload lacrm.contact.updated */
    public function contactUpdated(array $payload): void
    {
        if (!$this->isSelected()) return;

        foreach ((array) ($payload['contacts'] ?? []) as $contact) {
            $this->updateOne($contact);
        }
    }

    // Inside

    private function isSelected(): bool
    {
        return $this->selectedSource === TradeLacrmMapper::SOURCE;
    }

    /** @param mixed $contact One contact object from the webhook */
    private function updateOne(mixed $contact): void
    {
        $notContact = !\is_array($contact);
        $contactId = $notContact ? '' : (string) ($contact['ContactId'] ?? '');
        $noId = $contactId === '';
        if ($noId) return;

        $existing = ($this->findByRef)($contactId);
        $notACustomer = $existing === null; // LACRM tells us about every contact; only customers matter here
        if ($notACustomer) return;

        $changes = TradeLacrmMapper::changes($contact);
        $nothingToDo = $changes === [];
        if ($nothingToDo) return;

        ($this->announce)('trade.customer.updated', ['customer' => TradeLacrmMapper::applyChanges($existing, $changes)]);
    }

    /**
     * A company contact has no name of its own, so its first linked person gives the contact name.
     *
     * @param array<string, mixed> $contact
     * @return array<int, array<string, mixed>>
     */
    private function linkedPeople(array $contact, string $contactId): array
    {
        $notCompany = !TradeLacrmMapper::needsLinkedPeople($contact);
        if ($notCompany) return [];

        $people = $this->askQuietly('lacrm.contact.linked_people', ['companyId' => $contactId, 'companyName' => \trim((string) $contact['Company Name'])]);

        return \is_array($people) ? $people : [];
    }

    /** @param array<string, mixed> $payload */
    private function askQuietly(string $question, array $payload): mixed
    {
        try {
            return ($this->ask)($question, $payload);
        } catch (\Throwable $e) {
            ($this->log)("Trade Accounts: asking {$question} failed: {$e->getMessage()}");

            return null;
        }
    }
}
