<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradePhone;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradePhone.php';

final class TradePhoneTest extends TestCase
{
    public function test_common_uk_formats_are_dialable(): void
    {
        $this->assertSame('01283733211', TradePhone::dialable('01283 733211'));
        $this->assertSame('07792588696', TradePhone::dialable('07792-588-696'));
        $this->assertSame('+441283733211', TradePhone::dialable('+44 (0)1283 733211'));
        $this->assertSame('+441283733211', TradePhone::dialable('+44 1283 733211'));
    }

    public function test_text_or_too_short_or_too_long_is_not_dialable(): void
    {
        $this->assertNull(TradePhone::dialable(''));
        $this->assertNull(TradePhone::dialable('call after 5'));
        $this->assertNull(TradePhone::dialable('12345'));
        $this->assertNull(TradePhone::dialable('0123456789012345678'));
        $this->assertNull(TradePhone::dialable('01283 733211 ext 4'));
    }

    public function test_html_links_a_valid_number_and_escapes_the_rest(): void
    {
        $this->assertSame('<a href="tel:01283733211">01283 733211</a>', TradePhone::html('01283 733211'));
        $this->assertSame('call &lt;b&gt;', TradePhone::html('call <b>'));
        $this->assertSame('-', TradePhone::html(''));
    }
}
