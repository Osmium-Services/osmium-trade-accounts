<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeLinkedCustomers;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeLinkedCustomers.php';

final class TradeLinkedCustomersTest extends TestCase
{
    public function test_it_maps_each_contact_id_that_is_a_customer_and_skips_the_rest(): void
    {
        $find = static fn(string $source, string $ref): ?array => $source === 'lacrm' && $ref === '7' ? ['company_name' => 'Acme Ltd'] : null;

        $linked = TradeLinkedCustomers::lookup('lacrm', [7, '8', '', '7'], $find);

        $this->assertSame(['7' => ['label' => 'Acme Ltd', 'url' => 'trade/customers/']], $linked);
    }

    public function test_a_missing_source_or_database_answers_nothing(): void
    {
        $this->assertSame([], TradeLinkedCustomers::lookup('', ['7'], static fn(): array => ['company_name' => 'X']));
        $this->assertSame([], TradeLinkedCustomers::answer(['source' => 'lacrm', 'ids' => ['7']]));
    }
}
