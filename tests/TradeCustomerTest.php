<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeCustomerInput;
use Osmium\Services\TradeAccounts\Models\TradeLogo;
use Osmium\Services\TradeAccounts\Models\TradeStatus;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeStatus.php';
require_once __DIR__ . '/../models/TradeCustomerInput.php';
require_once __DIR__ . '/../models/TradeLogo.php';

final class TradeCustomerTest extends TestCase
{
    // Status

    public function test_there_are_four_statuses_and_only_active_can_log_in(): void
    {
        $this->assertSame(['pending', 'active', 'suspended', 'closed'], TradeStatus::all());
        $this->assertTrue(TradeStatus::canLogin('active'));
        foreach (['pending', 'suspended', 'closed', 'bogus'] as $status) {
            $this->assertFalse(TradeStatus::canLogin($status), $status);
        }
    }

    public function test_only_suspended_and_closed_are_inactive(): void
    {
        $this->assertTrue(TradeStatus::isInactive('suspended'));
        $this->assertTrue(TradeStatus::isInactive('closed'));
        $this->assertFalse(TradeStatus::isInactive('pending'));
        $this->assertFalse(TradeStatus::isInactive('active'));
    }

    public function test_labels_and_validity(): void
    {
        $this->assertSame('Suspended', TradeStatus::label('suspended'));
        $this->assertTrue(TradeStatus::isValid('closed'));
        $this->assertFalse(TradeStatus::isValid('Active'));
        $this->assertFalse(TradeStatus::isValid(''));
    }

    // Input

    public function test_details_are_trimmed_lowercased_and_blanks_become_null(): void
    {
        $data = TradeCustomerInput::parse([
            'email' => '  Sam@Example.COM ',
            'email2' => ' ',
            'company_name' => ' Acme Ltd ',
            'contact_name' => '',
            'phone' => ' 01234 ',
            'postcode' => 'sw1a   1aa',
        ]);

        $this->assertSame('sam@example.com', $data['email']);
        $this->assertNull($data['email2']);
        $this->assertSame('Acme Ltd', $data['company_name']);
        $this->assertNull($data['contact_name']);
        $this->assertSame('01234', $data['phone']);
        $this->assertSame('SW1A 1AA', $data['postcode']);
        $this->assertSame('United Kingdom', $data['country']);
        $this->assertSame('pending', $data['status']);
    }

    public function test_the_usual_ways_of_writing_the_uk_all_become_united_kingdom_and_other_countries_are_kept(): void
    {
        foreach (['UK', 'U.K.', ' gb ', 'Great Britain', 'Scotland', 'england', 'WALES', 'Northern Ireland', 'united kingdom', ''] as $written) {
            $this->assertSame('United Kingdom', TradeCustomerInput::parse(['country' => $written])['country'], "'{$written}'");
        }

        $this->assertSame('Ireland', TradeCustomerInput::parse(['country' => 'Ireland'])['country']);
    }

    public function test_an_unknown_status_falls_back_to_pending_and_a_known_one_is_kept(): void
    {
        $this->assertSame('pending', TradeCustomerInput::parse(['status' => 'nonsense'])['status']);
        $this->assertSame('active', TradeCustomerInput::parse(['status' => 'active'])['status']);
    }

    public function test_non_text_values_do_not_break_parsing(): void
    {
        $data = TradeCustomerInput::parse(['email' => ['x'], 'company_name' => null, 'phone' => 12345]);

        $this->assertSame('', $data['email']);
        $this->assertSame('', $data['company_name']);
        $this->assertSame('12345', $data['phone']);
    }

    public function test_valid_details_pass_and_the_first_problem_is_named(): void
    {
        $good = TradeCustomerInput::parse(['email' => 'a@b.co', 'company_name' => 'Acme']);
        $this->assertNull(TradeCustomerInput::validate($good));

        $this->assertSame('Primary email is required', TradeCustomerInput::validate(TradeCustomerInput::parse(['company_name' => 'Acme'])));
        $this->assertSame('Invalid primary email address', TradeCustomerInput::validate(TradeCustomerInput::parse(['email' => 'nope', 'company_name' => 'Acme'])));
        $this->assertSame('Invalid secondary email address', TradeCustomerInput::validate(TradeCustomerInput::parse(['email' => 'a@b.co', 'email2' => 'nope', 'company_name' => 'Acme'])));
        $this->assertSame('Company name is required', TradeCustomerInput::validate(TradeCustomerInput::parse(['email' => 'a@b.co'])));
    }

    public function test_a_contact_name_is_optional(): void
    {
        $data = TradeCustomerInput::parse(['email' => 'a@b.co', 'company_name' => 'Acme', 'contact_name' => '']);

        $this->assertNull(TradeCustomerInput::validate($data));
    }

    public function test_over_long_values_are_refused_by_field(): void
    {
        $data = TradeCustomerInput::parse(['email' => 'a@b.co', 'company_name' => \str_repeat('x', 256)]);

        $this->assertSame('Company name must be 255 characters or fewer', TradeCustomerInput::validate($data));
    }

    // Logo

    public function test_only_image_types_are_allowed_for_logos(): void
    {
        foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'] as $type) {
            $this->assertTrue(TradeLogo::isAllowedType($type), $type);
        }
        foreach (['image/svg+xml', 'application/pdf', 'text/html', ''] as $type) {
            $this->assertFalse(TradeLogo::isAllowedType($type), $type);
        }
    }

    public function test_the_logo_address_is_built_from_the_file_name_only(): void
    {
        $this->assertSame('/assets/images/trade/abc.png', TradeLogo::url('abc.png'));
        $this->assertSame('/assets/images/trade/passwd', TradeLogo::url('../../etc/passwd'));
        $this->assertSame('', TradeLogo::url(null));
        $this->assertSame('', TradeLogo::url(''));
    }

    public function test_big_logos_scale_down_to_fit_and_small_ones_are_left_alone(): void
    {
        $this->assertSame(1.0, TradeLogo::scale(400));
        $this->assertSame(1.0, TradeLogo::scale(120));
        $this->assertSame(0.5, TradeLogo::scale(800));
    }
}
