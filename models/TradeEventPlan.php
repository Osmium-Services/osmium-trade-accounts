<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * What a neutral event should do to the customers: the decision only, from the event, the customer it matches (if
 * any) and the selected source. No database, no hooks.
 *
 * Events: application.received (a new applicant, pending), customer.approved (accepted or reactivated, active),
 * customer.suspended (blocked, suspended), customer.updated (details changed, status untouched). Trade acts only
 * on events whose source is the one selected in settings.
 */
final class TradeEventPlan
{
    public const IGNORE = 'ignore';
    public const CREATE = 'create';
    public const STATUS = 'status';
    public const UPDATE = 'update';

    /** hook name => what it means */
    public const EVENTS = [
        'trade.application.received' => 'received',
        'trade.customer.approved' => 'approved',
        'trade.customer.suspended' => 'suspended',
        'trade.customer.updated' => 'updated',
    ];

    /**
     * @param array<string, mixed>|null $existing The live customer this event is about, if there is one
     * @return array{action: string, status: ?string, adopt: bool} adopt: link an existing customer who has no source yet to this one
     */
    public static function decide(string $event, ?array $existing, string $eventSource, string $selectedSource): array
    {
        $meaning = self::EVENTS[$event] ?? null;
        $unknown = $meaning === null;
        $noSource = $selectedSource === '' || $eventSource !== $selectedSource;
        if ($unknown || $noSource) return self::ignore();

        $exists = $existing !== null;
        $adopt = $exists && \trim((string) ($existing['source'] ?? '')) === '';

        return match ($meaning) {
            'received' => $exists ? self::ignore() : self::plan(self::CREATE, TradeStatus::PENDING, false),
            'approved' => $exists ? self::plan(self::STATUS, TradeStatus::ACTIVE, $adopt) : self::plan(self::CREATE, TradeStatus::ACTIVE, false),
            'suspended' => $exists ? self::plan(self::STATUS, TradeStatus::SUSPENDED, $adopt) : self::ignore(),
            'updated' => $exists ? self::plan(self::UPDATE, null, $adopt) : self::ignore(),
        };
    }

    /** Whether the plan writes customer details, so the payload must be a complete customer. */
    public static function writesDetails(array $plan): bool
    {
        return $plan['action'] === self::CREATE || $plan['action'] === self::UPDATE;
    }

    /** @return array{action: string, status: ?string, adopt: bool} */
    private static function plan(string $action, ?string $status, bool $adopt): array
    {
        return ['action' => $action, 'status' => $status, 'adopt' => $adopt];
    }

    /** @return array{action: string, status: ?string, adopt: bool} */
    private static function ignore(): array
    {
        return self::plan(self::IGNORE, null, false);
    }
}
