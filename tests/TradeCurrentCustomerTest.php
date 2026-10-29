<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeCurrentCustomer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeCurrentCustomer.php';

final class TradeCurrentCustomerTest extends TestCase
{
    public function test_only_the_id_names_and_status_are_shared_never_contact_details(): void
    {
        $row = ['id' => '12', 'company_name' => 'Acme', 'contact_name' => null, 'status' => 'active', 'email' => 'a@b.co', 'phone' => '01', 'address_line_1' => 'x', 'notes' => 'private', 'logo_filename' => 'l.png', 'failed_logins' => 0];

        $this->assertSame(['id' => 12, 'company_name' => 'Acme', 'contact_name' => '', 'status' => 'active'], TradeCurrentCustomer::shape($row));
    }

    public function test_without_an_osmium_object_nobody_is_logged_in(): void
    {
        $this->assertNull(TradeCurrentCustomer::get([]));
    }
}
