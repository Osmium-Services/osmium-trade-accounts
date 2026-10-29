<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * Answers `crm.contacts.linked`: which of a source's contact ids are trade customers. A source service that shows
 * contact-linked data (such as LACRM's calendar events) asks this without knowing Trade exists.
 */
final class TradeLinkedCustomers
{
    /**
     * @param array{source?: string, ids?: array<int, string>, dataSource?: OsmiumPDO} $payload
     * @return array<string, array{label: string, url: string}> contact id => the customer and its admin address
     */
    public static function answer(array $payload): array
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) return [];

        $customers = new TradeCustomer($db);

        return self::lookup(
            source: (string) ($payload['source'] ?? ''),
            ids: (array) ($payload['ids'] ?? []),
            find: static fn(string $source, string $ref): ?array => $customers->getBySource(source: $source, sourceRef: $ref),
        );
    }

    /**
     * @param array<int, mixed> $ids
     * @param callable(string, string): ?array $find The live customer for a source and contact id, or null
     * @return array<string, array{label: string, url: string}>
     */
    public static function lookup(string $source, array $ids, callable $find): array
    {
        $noSource = $source === '';
        if ($noSource) return [];

        $linked = [];
        foreach ($ids as $id) {
            $contactId = (string) $id;
            $customer = $contactId === '' ? null : $find($source, $contactId);
            if ($customer === null) continue;

            $linked[$contactId] = ['label' => (string) $customer['company_name'], 'url' => 'trade/customers/'];
        }

        return $linked;
    }
}
