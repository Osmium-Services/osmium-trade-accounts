<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeCustomerLookup;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeStatus.php';
require_once __DIR__ . '/../models/TradeCustomerInput.php';
require_once __DIR__ . '/../models/TradeCustomerLookup.php';

final class TradeCustomerLookupTest extends TestCase
{
    public function test_a_customer_row_is_shared_as_only_what_a_source_needs(): void
    {
        $row = ['id' => '7', 'status' => 'active', 'company_name' => 'Acme', 'email' => 'a@example.com', 'source' => 'lacrm', 'source_ref' => '123', 'phone' => '0123', 'notes' => 'private'];

        $this->assertSame(
            ['id' => 7, 'status' => 'active', 'company_name' => 'Acme', 'email' => 'a@example.com', 'source' => 'lacrm', 'source_ref' => '123'],
            TradeCustomerLookup::shape($row)
        );
    }

    public function test_a_hand_made_customer_has_no_source(): void
    {
        $shaped = TradeCustomerLookup::shape(['id' => 1, 'status' => 'pending', 'company_name' => 'B', 'email' => 'b@example.com', 'source' => null, 'source_ref' => null]);

        $this->assertNull($shaped['source']);
        $this->assertNull($shaped['source_ref']);
    }

    public function test_without_a_database_nothing_is_found(): void
    {
        $this->assertNull(TradeCustomerLookup::lookup(['email' => 'a@example.com']));
    }

    public function test_the_check_uses_the_same_rules_as_the_admin_form(): void
    {
        $good = ['email' => 'a@example.com', 'company_name' => 'Acme', 'contact_name' => 'A B'];

        $this->assertSame(['valid' => true, 'problem' => null], TradeCustomerLookup::check(['customer' => $good]));
        $this->assertSame(['valid' => false, 'problem' => 'Primary email is required'], TradeCustomerLookup::check(['customer' => ['company_name' => 'Acme']]));
    }
}
