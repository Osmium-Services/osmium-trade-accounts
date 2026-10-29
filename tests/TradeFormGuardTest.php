<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeFormGuard;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeClaim.php'; // for HONEYPOT; its other classes load only when it runs
require_once __DIR__ . '/../models/TradeFormGuard.php';

final class TradeFormGuardTest extends TestCase
{
    public function test_the_posted_token_must_match_the_sessions(): void
    {
        $this->assertTrue(TradeFormGuard::tokenMatches(['token' => 'abc'], ['token' => 'abc']));
        $this->assertFalse(TradeFormGuard::tokenMatches(['token' => 'abd'], ['token' => 'abc']));
    }

    public function test_a_missing_token_never_matches_even_when_both_are_missing(): void
    {
        $this->assertFalse(TradeFormGuard::tokenMatches([], []));
        $this->assertFalse(TradeFormGuard::tokenMatches(['token' => ''], ['token' => '']));
        $this->assertFalse(TradeFormGuard::tokenMatches(['token' => 'abc'], []));
        $this->assertFalse(TradeFormGuard::tokenMatches([], ['token' => 'abc']));
    }

    public function test_the_honeypot_field_must_stay_empty(): void
    {
        $this->assertFalse(TradeFormGuard::honeypotFilled([]));
        $this->assertFalse(TradeFormGuard::honeypotFilled(['company_website' => '  ']));
        $this->assertTrue(TradeFormGuard::honeypotFilled(['company_website' => 'http://spam.test']));
    }

    public function test_a_submission_faster_than_the_minimum_is_too_fast(): void
    {
        $session = ['token_issued_at' => '1000'];

        $this->assertTrue(TradeFormGuard::tooFast($session, 1003, 4));
        $this->assertFalse(TradeFormGuard::tooFast($session, 1004, 4));
    }

    public function test_no_minimum_means_never_too_fast_and_an_unknown_issue_time_is_too_fast(): void
    {
        $this->assertFalse(TradeFormGuard::tooFast(['token_issued_at' => '1000'], 1000, 0));
        $this->assertFalse(TradeFormGuard::tooFast([], 1000, 0));
        $this->assertTrue(TradeFormGuard::tooFast([], 1000, 4));
    }

    public function test_spending_removes_the_token_and_nothing_else(): void
    {
        $spent = TradeFormGuard::spend(['token' => 'abc', 'token_issued_at' => '1', 'keep' => 'me']);

        $this->assertSame(['keep' => 'me'], $spent);
    }
}
