<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Trade Accounts' tool for the /mcp endpoint, offered through core's `mcp.tools` hook: the customer base by
 * status, the approval queue and how the login is being used. Read only, and counts and dates only: it never
 * returns a company, a name, an email, a phone, an address, an IP address or a login token or code.
 */
class TradeAccountsMcp
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>> tool descriptors
     */
    public static function tools(array $payload): array
    {
        return [
            [
                'name' => 'trade_accounts_summary',
                'description' => 'The trade customer base: live customers by status (pending, active, suspended, closed), how many applications are waiting for approval and how many days the oldest has waited, how many joined in the last 7 and 30 days, how many active customers logged in within 30 days or never have, how many are locked out right now, and the last 7 days of login attempts by outcome (successful, link sent, unknown email, inactive, locked, rate limited, invalid or expired link, wrong code). Counts and dates only: no company, name, email, phone, address or IP. Read only.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'handler' => self::class . '::summary',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function summary(array $call): array
    {
        return self::shape((new TradeCustomer($call['dataSource']))->summaryByStatus(), (new TradeLoginAttempt($call['dataSource']))->summaryByReason());
    }

    /**
     * @param array<int, array<string, mixed>> $customers one row per status from customer-summary.sql
     * @param array<int, array<string, mixed>> $attempts from attempt-summary-by-reason.sql
     * @return array<string, mixed>
     */
    public static function shape(array $customers, array $attempts): array
    {
        $byStatus = [];
        foreach (TradeStatus::all() as $status) {
            $byStatus[$status] = 0;
        }
        $joined7 = $joined30 = $loggedIn = $never = $locked = 0;
        $pending = ['waiting' => 0, 'oldest_days' => null];

        foreach ($customers as $row) {
            $status = (string) $row['status'];
            $byStatus[$status] = (int) $row['customers'];
            $joined7 += (int) $row['joined_7_days'];
            $joined30 += (int) $row['joined_30_days'];
            $locked += (int) $row['locked_out'];

            if ($status === TradeStatus::PENDING) {
                $pending = ['waiting' => (int) $row['customers'], 'oldest_days' => (int) $row['oldest_days']];
            }
            if ($status === TradeStatus::ACTIVE) {
                $loggedIn = (int) $row['logged_in_30_days'];
                $never = (int) $row['never_logged_in'];
            }
        }

        return [
            'customers_by_status' => $byStatus,
            'pending_approval' => $pending,
            'joined' => ['last_7_days' => $joined7, 'last_30_days' => $joined30],
            'active_customers' => ['logged_in_last_30_days' => $loggedIn, 'never_logged_in' => $never],
            'locked_out_now' => $locked,
            'login_attempts_last_7_days' => \array_map(static fn(array $a): array => ['outcome' => (string) $a['reason'], 'attempts' => (int) $a['attempts']], $attempts),
        ];
    }
}
