<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeLoginRules;
use Osmium\Services\TradeAccounts\Models\TradeMail;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeLoginRules.php';
require_once __DIR__ . '/../models/TradeMail.php';

final class TradeMailTest extends TestCase
{
    private const TOKEN = 'abc123';

    public function test_the_link_points_at_verify_and_follows_the_sites_trailing_slash_habit(): void
    {
        $this->assertSame('https://x.test/trade/verify?t=abc123', TradeMail::linkUrl('https://x.test', self::TOKEN, false));
        $this->assertSame('https://x.test/trade/verify/?t=abc123', TradeMail::linkUrl('https://x.test/', self::TOKEN, true));
    }

    public function test_the_link_email_has_a_button_a_plain_link_and_the_expiry(): void
    {
        $mail = TradeMail::link(['contact_name' => 'Sam', 'company_name' => 'Acme'], 'https://x.test/trade/verify?t=abc123', 'Solar Co');

        $this->assertSame('Your Solar Co trade login link', $mail['subject']);
        $this->assertSame(3, \substr_count($mail['html'], 'https://x.test/trade/verify?t=abc123'));
        $this->assertStringContainsString('Hello Sam', $mail['html']);
        $this->assertStringContainsString((string) TradeLoginRules::LINK_MINUTES . ' minutes', $mail['html']);
    }

    public function test_the_code_email_shows_the_code_and_its_expiry(): void
    {
        $mail = TradeMail::code(['contact_name' => '', 'company_name' => 'Acme'], '012345', 'Solar Co');

        $this->assertStringContainsString('012345', $mail['html']);
        $this->assertStringContainsString('Hello Acme', $mail['html']); // no contact name: the company is greeted
        $this->assertStringContainsString((string) TradeLoginRules::CODE_MINUTES . ' minutes', $mail['html']);
    }

    public function test_a_customer_with_no_names_is_greeted_as_there(): void
    {
        $mail = TradeMail::code(['contact_name' => null, 'company_name' => ''], '111111', 'Solar Co');

        $this->assertStringContainsString('Hello there', $mail['html']);
    }

    public function test_names_and_the_site_name_cannot_inject_markup(): void
    {
        $mail = TradeMail::link(['contact_name' => '<script>x</script>', 'company_name' => ''], 'https://x.test/?a=1&b="2"', '<b>Site</b>');

        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringNotContainsString('<b>Site</b>', $mail['html']);
        $this->assertStringContainsString('&amp;b=&quot;2&quot;', $mail['html']);
    }
}
