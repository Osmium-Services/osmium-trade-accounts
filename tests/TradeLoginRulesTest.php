<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeLoginRules;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeLoginRules.php';

final class TradeLoginRulesTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function test_codes_are_six_digits_with_leading_zeros_kept(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', TradeLoginRules::newCode());
        }
    }

    public function test_only_six_digit_strings_pass_as_a_code(): void
    {
        $this->assertTrue(TradeLoginRules::isCodeFormat('012345'));
        foreach (['12345', '1234567', '12345a', ' 12345', '', '１２３４５６'] as $bad) {
            $this->assertFalse(TradeLoginRules::isCodeFormat($bad), $bad);
        }
    }

    public function test_tokens_are_64_hex_characters_and_never_repeat(): void
    {
        $a = TradeLoginRules::newToken();
        $b = TradeLoginRules::newToken();

        $this->assertTrue(TradeLoginRules::isTokenFormat($a));
        $this->assertNotSame($a, $b);
        foreach (['', 'abc', \str_repeat('g', 64), \strtoupper($a), $a . 'a'] as $bad) {
            $this->assertFalse(TradeLoginRules::isTokenFormat($bad), $bad);
        }
    }

    public function test_the_stored_hash_is_not_the_token_and_is_stable(): void
    {
        $token = TradeLoginRules::newToken();

        $this->assertNotSame($token, TradeLoginRules::tokenHash($token));
        $this->assertSame(TradeLoginRules::tokenHash($token), TradeLoginRules::tokenHash($token));
        $this->assertSame(64, \strlen(TradeLoginRules::tokenHash($token)));
    }

    public function test_a_lock_runs_until_its_time_and_no_lock_means_unlocked(): void
    {
        $future = \gmdate('Y-m-d H:i:s', self::NOW + 60);
        $past = \gmdate('Y-m-d H:i:s', self::NOW - 60);
        $oldTz = \date_default_timezone_get();
        \date_default_timezone_set('UTC');

        $this->assertTrue(TradeLoginRules::isLocked($future, self::NOW));
        $this->assertFalse(TradeLoginRules::isLocked($past, self::NOW));
        $this->assertFalse(TradeLoginRules::isLocked(null, self::NOW));
        $this->assertFalse(TradeLoginRules::isLocked('', self::NOW));
        $this->assertFalse(TradeLoginRules::isLocked('not a date', self::NOW));

        \date_default_timezone_set($oldTz);
    }

    public function test_ten_failures_lock_and_nine_do_not(): void
    {
        $this->assertFalse(TradeLoginRules::shouldLock(9));
        $this->assertTrue(TradeLoginRules::shouldLock(10));
        $this->assertTrue(TradeLoginRules::shouldLock(11));
    }

    public function test_ten_requests_from_one_address_in_the_window_is_the_limit(): void
    {
        $this->assertFalse(TradeLoginRules::ipOverLimit(9));
        $this->assertTrue(TradeLoginRules::ipOverLimit(10));
    }

    public function test_a_failed_link_says_whether_it_is_unknown_used_or_expired(): void
    {
        $oldTz = \date_default_timezone_get();
        \date_default_timezone_set('UTC');
        $future = \gmdate('Y-m-d H:i:s', self::NOW + 60);
        $past = \gmdate('Y-m-d H:i:s', self::NOW - 60);

        $this->assertSame('link_invalid', TradeLoginRules::linkFailure(null, self::NOW));
        $this->assertSame('link_used', TradeLoginRules::linkFailure(['used_at' => $past, 'expires_at' => $future], self::NOW));
        $this->assertSame('link_used', TradeLoginRules::linkFailure(['used_at' => $past, 'expires_at' => $past], self::NOW)); // used wins over expired
        $this->assertSame('link_expired', TradeLoginRules::linkFailure(['used_at' => null, 'expires_at' => $past], self::NOW));
        $this->assertSame('link_invalid', TradeLoginRules::linkFailure(['used_at' => null, 'expires_at' => $future], self::NOW));

        \date_default_timezone_set($oldTz);
    }

    public function test_each_link_failure_has_its_own_plain_message(): void
    {
        $messages = [
            TradeLoginRules::linkFailureMessage('link_expired'),
            TradeLoginRules::linkFailureMessage('link_used'),
            TradeLoginRules::linkFailureMessage('link_invalid'),
        ];

        $this->assertCount(3, \array_unique($messages));
        $this->assertSame($messages[2], TradeLoginRules::linkFailureMessage('anything else'));
    }

    public function test_three_wrong_codes_abandon_the_login(): void
    {
        $this->assertSame(3, TradeLoginRules::wrongCodesLeft(0));
        $this->assertSame(1, TradeLoginRules::wrongCodesLeft(2));
        $this->assertSame(0, TradeLoginRules::wrongCodesLeft(5));
        $this->assertFalse(TradeLoginRules::codeAbandoned(2));
        $this->assertTrue(TradeLoginRules::codeAbandoned(3));
    }
}
