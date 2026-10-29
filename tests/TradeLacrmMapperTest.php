<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeLacrmMapper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeLacrmMapper.php';

final class TradeLacrmMapperTest extends TestCase
{
    /** @return array<string, mixed> */
    private function company(): array
    {
        return [
            'ContactId' => '123',
            'Company Name' => ' Acme Solar Ltd ',
            'Email' => [['Text' => '', 'Type' => 'Work'], ['Text' => ' Sales@Acme.TEST ', 'Type' => 'Work']],
            'Phone' => [['Text' => '01234 567890', 'Type' => 'Work']],
            'Address' => [['Street' => "Unit 4,\r\n  Park Road \n", 'City' => 'Leeds', 'State' => 'West Yorkshire', 'Zip' => 'LS1 1AA', 'Country' => 'United Kingdom']],
        ];
    }

    public function test_a_company_contact_becomes_a_customer_with_the_crm_as_source(): void
    {
        $customer = TradeLacrmMapper::contact($this->company(), [['Name' => ['FirstName' => 'Sam', 'LastName' => 'Jones']]]);

        $this->assertSame('sales@acme.test', \strtolower($customer['email']));
        $this->assertSame('Acme Solar Ltd', $customer['company_name']);
        $this->assertSame('Sam Jones', $customer['contact_name']);
        $this->assertSame('01234 567890', $customer['phone']);
        $this->assertSame('Unit 4, Park Road', $customer['address_line_1']);
        $this->assertSame('Leeds', $customer['city']);
        $this->assertSame('West Yorkshire', $customer['county']);
        $this->assertSame('LS1 1AA', $customer['postcode']);
        $this->assertSame('lacrm', $customer['source']);
        $this->assertSame('123', $customer['source_ref']);
    }

    public function test_a_contact_with_nothing_but_an_id_does_not_crash(): void
    {
        $customer = TradeLacrmMapper::contact(['ContactId' => '9']);

        $this->assertSame('', $customer['email']);
        $this->assertSame('', $customer['company_name']);
        $this->assertSame('', $customer['contact_name']);
        $this->assertSame('9', $customer['source_ref']);
    }

    public function test_a_form_submission_maps_labels_and_puts_the_extras_in_the_notes(): void
    {
        $customer = TradeLacrmMapper::formSubmission([
            ['Label' => 'Email', 'Value' => ' Buyer@Firm.TEST '],
            ['Label' => 'Company Name', 'Value' => 'Firm Ltd'],
            ['Label' => 'Name', 'Value' => 'Pat Buyer'],
            ['Label' => 'Main Company Phone', 'Value' => '0113 000'],
            ['Label' => 'Address', 'Value' => ['Street' => '1 High St', 'City' => 'York', 'State' => 'N Yorks', 'Zip' => 'YO1 1AA', 'Country' => '']],
            ['Label' => 'VAT Number', 'Value' => 'GB123'],
            ['Label' => 'Delivery address (if different to the above)', 'Value' => ['Street' => 'Dock 2', 'City' => 'Hull', 'State' => '', 'Zip' => 'HU1 1AA', 'Country' => 'UK']],
            ['Label' => 'Contact Name Accounts', 'Value' => 'Ann Accounts'],
            ['Label' => 'Accounts Email', 'Value' => 'accounts@firm.test'],
        ]);

        $this->assertSame('buyer@firm.test', $customer['email']);
        $this->assertSame('Firm Ltd', $customer['company_name']);
        $this->assertSame('Pat Buyer', $customer['contact_name']);
        $this->assertSame('0113 000', $customer['phone']);
        $this->assertSame('1 High St', $customer['address_line_1']);
        $this->assertSame('YO1 1AA', $customer['postcode']);
        $this->assertSame('', $customer['source_ref']); // a form has no contact id yet
        $this->assertStringContainsString('VAT Number: GB123', $customer['notes']);
        $this->assertStringContainsString("=== Delivery address ===\nDock 2\nHull, HU1 1AA\nUK", $customer['notes']);
        $this->assertStringContainsString("=== Accounts contact ===\nName: Ann Accounts\nEmail: accounts@firm.test", $customer['notes']);
    }

    public function test_a_form_with_a_plain_text_address_and_no_extras_has_only_the_heading_note(): void
    {
        $customer = TradeLacrmMapper::formSubmission([['Label' => 'Address', 'Value' => '5 Lane'], ['Label' => 'Email', 'Value' => 'a@b.test']]);

        $this->assertSame('5 Lane', $customer['address_line_1']);
        $this->assertSame('=== Imported from LACRM form ===', $customer['notes']);
    }

    public function test_the_trading_name_stands_in_when_there_is_no_company_name(): void
    {
        $customer = TradeLacrmMapper::formSubmission([['Label' => 'Company Name', 'Value' => '  '], ['Label' => 'Trading name (if different to the company name)', 'Value' => 'Trade As']]);

        $this->assertSame('Trade As', $customer['company_name']);
    }

    public function test_a_company_update_carries_only_what_the_webhook_says(): void
    {
        $changes = TradeLacrmMapper::changes(['Company Name' => 'New Name Ltd', 'Phone' => [['Text' => '0777']]]);

        $this->assertSame(['company_name' => 'New Name Ltd', 'phone' => '0777'], $changes);
    }

    public function test_a_person_update_sets_the_contact_name_and_a_bad_email_is_dropped(): void
    {
        $changes = TradeLacrmMapper::changes(['Name' => ['FirstName' => 'Sam', 'LastName' => 'Lee'], 'Email' => [['Text' => 'not an email']]]);

        $this->assertSame(['contact_name' => 'Sam Lee'], $changes);
    }

    public function test_an_address_update_maps_each_part_that_is_present(): void
    {
        $changes = TradeLacrmMapper::changes(['Address' => [['Street' => "A\nB", 'Zip' => ' X1 ']]]);

        $this->assertSame(['address_line_1' => 'A, B', 'postcode' => 'X1'], $changes);
    }

    public function test_changes_laid_over_a_customer_give_a_complete_customer_that_keeps_its_ref(): void
    {
        $existing = ['id' => 4, 'email' => 'old@x.test', 'company_name' => 'Old', 'phone' => '1', 'notes' => 'keep me', 'source_ref' => '77', 'status' => 'active', 'discount' => 'ignored'];

        $customer = TradeLacrmMapper::applyChanges($existing, ['company_name' => 'New', 'phone' => '2']);

        $this->assertSame('New', $customer['company_name']);
        $this->assertSame('2', $customer['phone']);
        $this->assertSame('old@x.test', $customer['email']);
        $this->assertSame('keep me', $customer['notes']);
        $this->assertSame('lacrm', $customer['source']);
        $this->assertSame('77', $customer['source_ref']);
        $this->assertArrayNotHasKey('status', $customer);
        $this->assertArrayNotHasKey('discount', $customer);
    }

    public function test_a_plain_text_name_with_an_is_company_flag_is_understood(): void
    {
        $this->assertSame(['company_name' => 'Plain Co Ltd'], TradeLacrmMapper::changes(['IsCompany' => true, 'Name' => ' Plain Co Ltd ']));
        $this->assertSame(['contact_name' => 'Sam Lee'], TradeLacrmMapper::changes(['IsCompany' => false, 'Name' => 'Sam Lee']));
        $this->assertSame([], TradeLacrmMapper::changes(['Name' => 'No flag']));
    }

    public function test_a_company_with_no_email_or_phone_takes_them_from_its_first_linked_person_who_has_them(): void
    {
        $people = [
            ['Name' => ['FirstName' => 'No', 'LastName' => 'Email'], 'Email' => []],
            ['Name' => ['FirstName' => 'Has', 'LastName' => 'Email'], 'Email' => [['Text' => ' Boss@Acme.TEST ']], 'Phone' => [['Text' => '0777']]],
        ];

        $customer = TradeLacrmMapper::contact(['ContactId' => '5', 'Company Name' => 'Acme'], $people);

        $this->assertSame('Boss@Acme.TEST', $customer['email']);
        $this->assertSame('0777', $customer['phone']);
        $this->assertSame('No Email', $customer['contact_name']); // the first person, as before
    }

    public function test_the_company_own_email_wins_over_a_linked_person(): void
    {
        $customer = TradeLacrmMapper::contact($this->company(), [['Name' => ['FirstName' => 'S', 'LastName' => 'J'], 'Email' => [['Text' => 'person@x.test']]]]);

        $this->assertSame('sales@acme.test', \strtolower($customer['email']));
    }
}
