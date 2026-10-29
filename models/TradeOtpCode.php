<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * The 6-digit code sent after a login link is clicked. One live code per customer.
 */
class TradeOtpCode extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'trade_otp_codes');
    }

    /** @return string The new code to email; any earlier unused code is gone. */
    public function issue(int $customerId): string
    {
        $this->database->prepare(sql: $this->loadSqlFile('otp-cleanup.sql'), bindings: [])->execute();
        $this->database->prepare(sql: $this->loadSqlFile('otp-delete-unused.sql'), bindings: [':customer_id' => $customerId])->execute();

        $code = TradeLoginRules::newCode();
        $bindings = [':customer_id' => $customerId, ':code' => $code, ':expiry_minutes' => TradeLoginRules::CODE_MINUTES];
        $this->database->prepare(sql: $this->loadSqlFile('otp-insert.sql'), bindings: $bindings)->execute();

        return $code;
    }

    /** Uses the code up if it is right and live, in one statement. */
    public function consume(int $customerId, string $code): bool
    {
        $wrongShape = !TradeLoginRules::isCodeFormat($code);
        if ($wrongShape) return false;

        $statement = $this->database->prepare(sql: $this->loadSqlFile('otp-consume.sql'), bindings: [':customer_id' => $customerId, ':code' => $code]);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    /** True when a code was already sent to this customer inside the window. */
    public function sentRecently(int $customerId, int $windowSeconds = TradeLoginRules::CODE_RESEND_SECONDS): bool
    {
        $bindings = [':customer_id' => $customerId, ':window_seconds' => $windowSeconds];
        $row = $this->database->prepare(sql: $this->loadSqlFile('otp-recent.sql'), bindings: $bindings)->single();

        return !empty($row);
    }
}
