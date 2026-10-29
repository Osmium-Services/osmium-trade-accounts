<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Answers trade.customer.current: who is logged in to the trade portal on this request, so another service can offer
 * something to trade customers only without calling Trade's PHP. Read-only. It shares only what a service needs to
 * greet and tell customers apart, never contact details, the logo, notes or anything about the login.
 */
final class TradeCurrentCustomer
{
    /**
     * @param array{osmium?: object} $payload
     * @return array{id: int, company_name: string, contact_name: string, status: string}|null null when nobody is logged in
     */
    public static function get(array $payload): ?array
    {
        $osmium = $payload['osmium'] ?? null;
        $noOsmium = !\is_object($osmium);
        if ($noOsmium) return null;

        $customer = TradeAuthFactory::make($osmium)->currentCustomer();

        return $customer === null ? null : self::shape($customer);
    }

    /**
     * @param array<string, mixed> $customer a trade_customers row
     * @return array{id: int, company_name: string, contact_name: string, status: string}
     */
    public static function shape(array $customer): array
    {
        return [
            'id' => (int) $customer['id'],
            'company_name' => (string) ($customer['company_name'] ?? ''),
            'contact_name' => (string) ($customer['contact_name'] ?? ''),
            'status' => (string) $customer['status'],
        ];
    }
}
