<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * Login links. Only a hash of each token is stored; the token itself exists in the email and nowhere else.
 */
class TradeMagicLink extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'trade_magic_links');
    }

    /** @return string The new token to put in the email */
    public function issue(int $customerId, string $ipAddress, string $userAgent): string
    {
        $this->database->prepare(sql: $this->loadSqlFile('magic-link-cleanup.sql'), bindings: [])->execute();

        $token = TradeLoginRules::newToken();
        $bindings = [
            ':customer_id' => $customerId,
            ':token_hash' => TradeLoginRules::tokenHash($token),
            ':ip_address' => $ipAddress,
            ':user_agent' => \mb_substr($userAgent, 0, 255),
            ':expiry_minutes' => TradeLoginRules::LINK_MINUTES,
        ];
        $this->database->prepare(sql: $this->loadSqlFile('magic-link-insert.sql'), bindings: $bindings)->execute();

        return $token;
    }

    /**
     * Uses the link up if it is valid, in one statement so two clicks cannot both win.
     *
     * @return array<string, mixed>|null The link row when this call used it up, null when it did not work
     */
    public function consume(string $token): ?array
    {
        $hash = TradeLoginRules::tokenHash($token);

        $statement = $this->database->prepare(sql: $this->loadSqlFile('magic-link-consume.sql'), bindings: [':token_hash' => $hash]);
        $statement->execute();
        $used = $statement->rowCount() === 1;
        if (!$used) return null;

        return $this->getByToken($token);
    }

    /** The stored row for a token whether or not it still works, to explain a failure. */
    public function getByToken(string $token): ?array
    {
        $bindings = [':token_hash' => TradeLoginRules::tokenHash($token)];
        $row = $this->database->prepare(sql: $this->loadSqlFile('magic-link-get-by-hash.sql'), bindings: $bindings)->single();

        return $row ?: null;
    }

    /** True when a link was already issued to this customer inside the window. */
    public function issuedRecently(int $customerId, int $windowSeconds = TradeLoginRules::LINK_EMAIL_SECONDS): bool
    {
        $bindings = [':customer_id' => $customerId, ':window_seconds' => $windowSeconds];
        $row = $this->database->prepare(sql: $this->loadSqlFile('magic-link-recent.sql'), bindings: $bindings)->single();

        return !empty($row);
    }
}
