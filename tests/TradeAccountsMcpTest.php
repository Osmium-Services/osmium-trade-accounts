<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeAccountsMcp;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeStatus.php';
require_once __DIR__ . '/../models/TradeAccountsMcp.php';

final class TradeAccountsMcpTest extends TestCase
{
    private const ROWS = [
        ['status' => 'pending', 'customers' => '3', 'oldest_days' => '9', 'joined_7_days' => '2', 'joined_30_days' => '3', 'logged_in_30_days' => '0', 'never_logged_in' => '3', 'locked_out' => '0'],
        ['status' => 'active', 'customers' => '40', 'oldest_days' => '400', 'joined_7_days' => '1', 'joined_30_days' => '5', 'logged_in_30_days' => '22', 'never_logged_in' => '6', 'locked_out' => '1'],
    ];

    public function test_the_tool_is_read_only_and_takes_no_input(): void
    {
        $tools = TradeAccountsMcp::tools([]);

        $this->assertSame(['trade_accounts_summary'], \array_column($tools, 'name'));
        $this->assertArrayNotHasKey('write', $tools[0]);
    }

    public function test_every_status_is_listed_with_zero_for_the_missing_ones(): void
    {
        $out = TradeAccountsMcp::shape(self::ROWS, []);

        $this->assertSame(['pending' => 3, 'active' => 40, 'suspended' => 0, 'closed' => 0], $out['customers_by_status']);
    }

    public function test_the_approval_queue_and_activity_come_from_the_right_status(): void
    {
        $out = TradeAccountsMcp::shape(self::ROWS, []);

        $this->assertSame(['waiting' => 3, 'oldest_days' => 9], $out['pending_approval']);
        $this->assertSame(['logged_in_last_30_days' => 22, 'never_logged_in' => 6], $out['active_customers']);
        $this->assertSame(['last_7_days' => 3, 'last_30_days' => 8], $out['joined']);
        $this->assertSame(1, $out['locked_out_now']);
    }

    public function test_no_pending_applications_means_no_oldest_age(): void
    {
        $out = TradeAccountsMcp::shape([self::ROWS[1]], [['reason' => 'login_success', 'attempts' => '12'], ['reason' => 'unknown_email', 'attempts' => '4']]);

        $this->assertSame(['waiting' => 0, 'oldest_days' => null], $out['pending_approval']);
        $this->assertSame([['outcome' => 'login_success', 'attempts' => 12], ['outcome' => 'unknown_email', 'attempts' => 4]], $out['login_attempts_last_7_days']);
    }

    public function test_an_empty_site_gives_a_complete_answer(): void
    {
        $out = TradeAccountsMcp::shape([], []);

        $this->assertSame(0, $out['customers_by_status']['active']);
        $this->assertSame([], $out['login_attempts_last_7_days']);
        $this->assertSame(0, $out['locked_out_now']);
    }

    public function test_no_personal_field_reaches_the_answer(): void
    {
        $row = self::ROWS[0] + ['email' => 'sam@secret.example', 'company_name' => 'Secret Ltd', 'ip_address' => '203.0.113.9'];
        $json = \json_encode(TradeAccountsMcp::shape([$row], [['reason' => 'link_sent', 'attempts' => 1, 'email' => 'sam@secret.example', 'ip_address' => '203.0.113.9']]));

        foreach (['secret', 'Secret', '203.0.113', 'sam@'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }
}
