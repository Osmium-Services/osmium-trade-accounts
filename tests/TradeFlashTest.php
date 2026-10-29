<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeFlash;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeFlash.php';

final class TradeFlashTest extends TestCase
{
    public function test_a_message_is_read_once_then_gone(): void
    {
        $session = ['other' => 1];
        TradeFlash::set($session, ['error' => 'Nope', 'email' => 'a@b.test']);

        $this->assertSame(['error' => 'Nope', 'email' => 'a@b.test'], TradeFlash::take($session));
        $this->assertSame([], TradeFlash::take($session));
        $this->assertSame(['other' => 1], $session); // nothing else touched
    }

    public function test_nothing_set_gives_an_empty_array(): void
    {
        $session = [];

        $this->assertSame([], TradeFlash::take($session));
    }

    public function test_a_damaged_value_gives_an_empty_array(): void
    {
        $session = [TradeFlash::KEY => 'not an array'];

        $this->assertSame([], TradeFlash::take($session));
        $this->assertArrayNotHasKey(TradeFlash::KEY, $session);
    }
}
