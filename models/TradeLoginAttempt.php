<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * A record of every login request and outcome, for the per-address limit and for looking back.
 */
class TradeLoginAttempt extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'trade_login_attempts');
    }

    public function log(string $email, ?int $customerId, string $ipAddress, string $userAgent, string $reason): void
    {
        $unknownReason = !\in_array($reason, TradeLoginRules::REASONS, true);
        if ($unknownReason) throw new \InvalidArgumentException("Unknown login attempt reason '{$reason}'");

        $this->database->prepare(sql: $this->loadSqlFile('attempt-cleanup.sql'), bindings: [])->execute();

        $bindings = [
            ':email' => \mb_substr($email, 0, 255),
            ':customer_id' => $customerId,
            ':ip_address' => $ipAddress,
            ':user_agent' => \mb_substr($userAgent, 0, 255),
            ':reason' => $reason,
        ];
        $this->database->prepare(sql: $this->loadSqlFile('attempt-insert.sql'), bindings: $bindings)->execute();
    }

    /** How many link requests this address made inside the window. */
    public function requestsFromIp(string $ipAddress, int $windowSeconds = TradeLoginRules::IP_WINDOW_SECONDS): int
    {
        $bindings = [':ip_address' => $ipAddress, ':window_seconds' => $windowSeconds];
        $row = $this->database->prepare(sql: $this->loadSqlFile('attempt-count-by-ip.sql'), bindings: $bindings)->single();

        return (int) ($row['total'] ?? 0);
    }
}
