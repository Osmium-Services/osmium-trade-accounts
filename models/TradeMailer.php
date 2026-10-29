<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\MailerFactory;

/**
 * Sends the login emails through the site's chosen mail provider. A failure is logged and reported as false; it
 * never throws into the login.
 */
class TradeMailer implements TradeLoginMailer
{
    public function __construct(private object $osmium)
    {
    }

    public function sendLink(array $customer, string $token): bool
    {
        $site = $this->osmium->config->site;
        $url = TradeMail::linkUrl(fqdn: (string) $site->FQDN, token: $token, trailingSlash: (bool) ($site->urlTrailingSlash ?? false));

        return $this->send(customer: $customer, mail: TradeMail::link(customer: $customer, url: $url, siteName: (string) $site->name));
    }

    public function sendCode(array $customer, string $code): bool
    {
        $mail = TradeMail::code(customer: $customer, code: $code, siteName: (string) $this->osmium->config->site->name);

        return $this->send(customer: $customer, mail: $mail);
    }

    /** @param array{subject: string, html: string} $mail */
    private function send(array $customer, array $mail): bool
    {
        try {
            $result = MailerFactory::make($this->osmium->config)->send(
                recipients: [['email' => $customer['email'], 'name' => (string) ($customer['contact_name'] ?? '')]],
                subject: $mail['subject'],
                htmlBody: $mail['html'],
            );
            $sent = !empty($result['success']);
            if (!$sent) \error_log('Trade Accounts: login email failed: ' . ($result['error'] ?? 'unknown error'));

            return $sent;
        } catch (\Throwable $e) {
            \error_log('Trade Accounts: login email failed: ' . $e->getMessage());

            return false;
        }
    }
}
