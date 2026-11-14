<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * Answers two questions another service may ask, so a customer source never needs Trade's PHP:
 *   trade.customer.lookup   a customer by `source` + `sourceRef` (the source's own id for them), or by `email`
 *   trade.source.selected   which customer source is chosen in settings (empty when customers are added by hand)
 *   trade.customer.check    `customer` (as a source would announce it): ['valid' => bool, 'problem' => ?string], by the same rules the admin form and the events use
 * Read-only, and it shares only what a source needs to tell customers apart.
 */
final class TradeCustomerLookup
{
    /**
     * @param array<string, mixed> $payload `dataSource`, then `source` and `sourceRef`, or `email`
     * @return array{id: int, status: string, company_name: string, email: string, source: ?string, source_ref: ?string}|null null when there is no such live customer
     */
    public static function lookup(array $payload): ?array
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) return null;

        $model = new TradeCustomer($db);
        $bySource = isset($payload['source'], $payload['sourceRef']);
        $row = $bySource
            ? $model->getBySource(source: (string) $payload['source'], sourceRef: (string) $payload['sourceRef'])
            : (isset($payload['email']) ? $model->getByEmail((string) $payload['email']) : null);

        return $row === null ? null : self::shape($row);
    }

    /**
     * @param array<string, mixed> $payload `customer`
     * @return array{valid: bool, problem: ?string}
     */
    public static function check(array $payload): array
    {
        $customer = \is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $problem = TradeCustomerInput::validate(TradeCustomerInput::parse($customer));

        return ['valid' => $problem === null, 'problem' => $problem];
    }

    /** @param array<string, mixed> $payload */
    public static function selectedSource(array $payload): string
    {
        return (string) TradeConfig::get()['source'];
    }

    /**
     * @param array<string, mixed> $row a trade_customers row
     * @return array{id: int, status: string, company_name: string, email: string, source: ?string, source_ref: ?string}
     */
    public static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'company_name' => (string) $row['company_name'],
            'email' => (string) $row['email'],
            'source' => $row['source'] === null ? null : (string) $row['source'],
            'source_ref' => $row['source_ref'] === null ? null : (string) $row['source_ref'],
        ];
    }
}
