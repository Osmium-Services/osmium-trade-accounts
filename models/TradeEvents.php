<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * Handlers for the four neutral events (see TradeEventPlan). A source service, or one of Trade's adapters, fires
 * the event with `customer` (email, company_name, contact_name, phone, address_line_1/2, city, county, postcode,
 * country, notes, source, source_ref) and `dataSource`. Trade acts only when `customer.source` is the source
 * selected in settings. An event that writes details (received, and updated) needs a complete, valid customer;
 * approved and suspended need only enough to find one (source and source_ref, else email).
 */
final class TradeEvents
{
    public static function received(array $payload): void
    {
        self::handle(event: 'trade.application.received', payload: $payload);
    }

    public static function approved(array $payload): void
    {
        self::handle(event: 'trade.customer.approved', payload: $payload);
    }

    public static function suspended(array $payload): void
    {
        self::handle(event: 'trade.customer.suspended', payload: $payload);
    }

    public static function updated(array $payload): void
    {
        self::handle(event: 'trade.customer.updated', payload: $payload);
    }

    /** @param array<string, mixed> $payload */
    public static function handle(string $event, array $payload, ?string $selectedSource = null): void
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) {
            \error_log("Trade Accounts: {$event} arrived without a dataSource and was ignored");

            return;
        }

        $customer = \is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $data = TradeCustomerInput::parse($customer);
        $selected = $selectedSource ?? (string) TradeConfig::get()['source'];

        $model = new TradeCustomer($db);
        $existing = self::find(model: $model, data: $data);
        $plan = TradeEventPlan::decide(event: $event, existing: $existing, eventSource: (string) $data['source'], selectedSource: $selected);
        if ($plan['action'] === TradeEventPlan::IGNORE) return;

        $needsDetails = TradeEventPlan::writesDetails($plan);
        $problem = $needsDetails ? TradeCustomerInput::validate($data) : null;
        if ($problem !== null) {
            \error_log("Trade Accounts: {$event} from '{$data['source']}' ignored: {$problem}");

            return;
        }

        self::apply(db: $db, model: $model, existing: $existing, plan: $plan, data: $data);
    }

    /** The live customer an event is about: by the source's own id when it gave one, else by email. */
    private static function find(TradeCustomer $model, array $data): ?array
    {
        $hasRef = (string) $data['source'] !== '' && (string) $data['source_ref'] !== '';
        if ($hasRef) {
            $bySource = $model->getBySource(source: (string) $data['source'], sourceRef: (string) $data['source_ref']);
            if ($bySource !== null) return $bySource;
        }

        $hasEmail = (string) $data['email'] !== '';

        return $hasEmail ? $model->getByEmail((string) $data['email']) : null;
    }

    /** @param array{action: string, status: ?string, adopt: bool} $plan */
    private static function apply(OsmiumPDO $db, TradeCustomer $model, ?array $existing, array $plan, array $data): void
    {
        if ($plan['action'] === TradeEventPlan::CREATE) {
            $data['status'] = $plan['status'];
            $model->create($data);

            return;
        }

        $id = (int) $existing['id'];

        if ($plan['adopt']) $model->setSource(id: $id, source: (string) $data['source'], sourceRef: (string) $data['source_ref']);
        if ($plan['action'] === TradeEventPlan::UPDATE) $model->update(id: $id, data: $data);
        if ($plan['action'] === TradeEventPlan::STATUS) TradeStatusChange::apply(db: $db, model: $model, customer: $existing, to: (string) $plan['status']);
    }
}
