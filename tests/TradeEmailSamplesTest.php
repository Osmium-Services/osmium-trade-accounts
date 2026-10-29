<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeEmailSamples;
use Osmium\Services\TradeAccounts\Models\TradeLoginRules;
use Osmium\Services\TradeAccounts\Models\TradeMail;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeLoginRules.php';
require_once __DIR__ . '/../models/TradeMail.php';
require_once __DIR__ . '/../models/TradeEmailSamples.php';

final class TradeEmailSamplesTest extends TestCase
{
    public function test_both_login_emails_are_offered_under_trade_accounts_with_usable_ids_and_handlers(): void
    {
        $samples = TradeEmailSamples::samples([]);

        $this->assertSame(['trade.login_link', 'trade.login_code'], \array_column($samples, 'id'));
        foreach ($samples as $sample) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_.-]+$/', $sample['id']);
            $this->assertSame('Trade Accounts', $sample['group']);
            $this->assertTrue(\is_callable(\explode('::', $sample['handler'])), $sample['handler'] . ' is not callable');
        }
    }

    public function test_the_sample_data_is_made_up_and_builds_the_real_emails(): void
    {
        $url = TradeMail::linkUrl(fqdn: 'https://x.test', token: TradeEmailSamples::SAMPLE_TOKEN, trailingSlash: false);
        $link = TradeMail::link(customer: TradeEmailSamples::SAMPLE_CUSTOMER, url: $url, siteName: 'Solar Co');
        $code = TradeMail::code(customer: TradeEmailSamples::SAMPLE_CUSTOMER, code: TradeEmailSamples::SAMPLE_CODE, siteName: 'Solar Co');

        $this->assertStringContainsString('Hello Jane Doe', $link['html']);
        $this->assertStringContainsString('SAMPLE-TOKEN-NOT-VALID', $link['html']);
        $this->assertStringContainsString('123456', $code['html']);
        $this->assertStringContainsString((string) TradeLoginRules::CODE_MINUTES, $code['html']);
    }
}
