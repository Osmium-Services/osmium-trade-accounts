<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\MailerFactory;

/**
 * Trade Accounts' emails for the admin Email settings test tool, offered through the email.samples hook. Each one is the
 * real email built from made-up data (no customer, no real token or code) and sent to the address given and nobody else.
 */
final class TradeEmailSamples
{
    public const SAMPLE_CUSTOMER = ['contact_name' => 'Jane Doe', 'company_name' => 'Sample Trade Ltd', 'email' => 'jane@example.com'];
    public const SAMPLE_TOKEN = 'SAMPLE-TOKEN-NOT-VALID';
    public const SAMPLE_CODE = '123456';

    /** @return array<int, array{id: string, label: string, group: string, handler: string}> */
    public static function samples(array $payload): array
    {
        return [
            ['id' => 'trade.login_link', 'label' => 'Trade login link (sample data)', 'group' => 'Trade Accounts', 'handler' => self::class . '::sendLink'],
            ['id' => 'trade.login_code', 'label' => 'Trade verification code (sample data)', 'group' => 'Trade Accounts', 'handler' => self::class . '::sendCode'],
        ];
    }

    /**
     * @param array{toEmail: string, osmium: object} $payload
     * @return array{success: bool, error?: string}
     */
    public static function sendLink(array $payload): array
    {
        $site = $payload['osmium']->config->site;
        $url = TradeMail::linkUrl(fqdn: (string) $site->FQDN, token: self::SAMPLE_TOKEN, trailingSlash: (bool) ($site->urlTrailingSlash ?? false));
        $mail = TradeMail::link(customer: self::SAMPLE_CUSTOMER, url: $url, siteName: (string) $site->name);

        return self::send(payload: $payload, mail: $mail);
    }

    /**
     * @param array{toEmail: string, osmium: object} $payload
     * @return array{success: bool, error?: string}
     */
    public static function sendCode(array $payload): array
    {
        $mail = TradeMail::code(customer: self::SAMPLE_CUSTOMER, code: self::SAMPLE_CODE, siteName: (string) $payload['osmium']->config->site->name);

        return self::send(payload: $payload, mail: $mail);
    }

    /**
     * @param array{toEmail: string, osmium: object} $payload
     * @param array{subject: string, html: string} $mail
     * @return array{success: bool, error?: string}
     */
    private static function send(array $payload, array $mail): array
    {
        return MailerFactory::make($payload['osmium']->config)->send(
            recipients: [['email' => $payload['toEmail'], 'name' => '']],
            subject: $mail['subject'],
            htmlBody: $mail['html'],
        );
    }
}
