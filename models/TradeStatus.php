<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * The four states of a trade account. Any state can move to any other (a closed
 * account can be reopened); only `active` customers can log in.
 */
final class TradeStatus
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const CLOSED = 'closed';

    /** @var array<string, string> status => label, in the order they are listed */
    private const LABELS = [
        self::PENDING => 'Pending',
        self::ACTIVE => 'Active',
        self::SUSPENDED => 'Suspended',
        self::CLOSED => 'Closed',
    ];

    /** @return string[] */
    public static function all(): array
    {
        return \array_keys(self::LABELS);
    }

    public static function isValid(string $status): bool
    {
        return isset(self::LABELS[$status]);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? \ucfirst($status);
    }

    public static function canLogin(string $status): bool
    {
        return $status === self::ACTIVE;
    }

    /** Statuses shown in the main customer list; the rest are listed separately as inactive. */
    public static function isInactive(string $status): bool
    {
        return $status === self::SUSPENDED || $status === self::CLOSED;
    }
}
