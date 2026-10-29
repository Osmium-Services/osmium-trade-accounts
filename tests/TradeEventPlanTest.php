<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeEventPlan;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeStatus.php';
require_once __DIR__ . '/../models/TradeEventPlan.php';

final class TradeEventPlanTest extends TestCase
{
    private const RECEIVED = 'trade.application.received';
    private const APPROVED = 'trade.customer.approved';
    private const SUSPENDED = 'trade.customer.suspended';
    private const UPDATED = 'trade.customer.updated';

    /** @return array<string, mixed> */
    private function customer(string $source = 'crm'): array
    {
        return ['id' => 5, 'status' => 'pending', 'source' => $source];
    }

    public function test_events_from_a_source_that_is_not_selected_do_nothing(): void
    {
        foreach ([self::RECEIVED, self::APPROVED, self::SUSPENDED, self::UPDATED] as $event) {
            $this->assertSame('ignore', TradeEventPlan::decide($event, $this->customer(), 'other', 'crm')['action'], $event);
            $this->assertSame('ignore', TradeEventPlan::decide($event, null, 'crm', '')['action'], "{$event} with no source selected");
        }
    }

    public function test_an_unknown_event_does_nothing(): void
    {
        $this->assertSame('ignore', TradeEventPlan::decide('trade.something.else', null, 'crm', 'crm')['action']);
    }

    public function test_an_application_creates_a_pending_customer_unless_one_exists(): void
    {
        $this->assertSame(['action' => 'create', 'status' => 'pending', 'adopt' => false], TradeEventPlan::decide(self::RECEIVED, null, 'crm', 'crm'));
        $this->assertSame('ignore', TradeEventPlan::decide(self::RECEIVED, $this->customer(), 'crm', 'crm')['action']); // never downgrades
    }

    public function test_approval_activates_an_existing_customer_or_creates_an_active_one(): void
    {
        $this->assertSame(['action' => 'status', 'status' => 'active', 'adopt' => false], TradeEventPlan::decide(self::APPROVED, $this->customer(), 'crm', 'crm'));
        $this->assertSame(['action' => 'create', 'status' => 'active', 'adopt' => false], TradeEventPlan::decide(self::APPROVED, null, 'crm', 'crm'));
    }

    public function test_suspension_acts_only_on_a_customer_that_exists(): void
    {
        $this->assertSame(['action' => 'status', 'status' => 'suspended', 'adopt' => false], TradeEventPlan::decide(self::SUSPENDED, $this->customer(), 'crm', 'crm'));
        $this->assertSame('ignore', TradeEventPlan::decide(self::SUSPENDED, null, 'crm', 'crm')['action']);
    }

    public function test_an_update_changes_details_of_an_existing_customer_and_never_status(): void
    {
        $plan = TradeEventPlan::decide(self::UPDATED, $this->customer(), 'crm', 'crm');

        $this->assertSame('update', $plan['action']);
        $this->assertNull($plan['status']);
        $this->assertSame('ignore', TradeEventPlan::decide(self::UPDATED, null, 'crm', 'crm')['action']);
    }

    public function test_a_customer_added_by_hand_is_adopted_when_a_source_vouches_for_them(): void
    {
        $byHand = ['id' => 7, 'status' => 'active', 'source' => null];

        $this->assertTrue(TradeEventPlan::decide(self::APPROVED, $byHand, 'crm', 'crm')['adopt']);
        $this->assertTrue(TradeEventPlan::decide(self::UPDATED, $byHand, 'crm', 'crm')['adopt']);
        $this->assertFalse(TradeEventPlan::decide(self::APPROVED, $this->customer('crm'), 'crm', 'crm')['adopt']);
    }

    public function test_only_create_and_update_write_details(): void
    {
        $this->assertTrue(TradeEventPlan::writesDetails(['action' => 'create', 'status' => 'pending', 'adopt' => false]));
        $this->assertTrue(TradeEventPlan::writesDetails(['action' => 'update', 'status' => null, 'adopt' => false]));
        $this->assertFalse(TradeEventPlan::writesDetails(['action' => 'status', 'status' => 'active', 'adopt' => false]));
        $this->assertFalse(TradeEventPlan::writesDetails(['action' => 'ignore', 'status' => null, 'adopt' => false]));
    }
}
