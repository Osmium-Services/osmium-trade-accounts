<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\IpAddress;

/**
 * Builds the login service for the current request: the real session, the visitor's address (proxy headers only
 * when the site has said it is behind a proxy) and the site's mailer.
 */
final class TradeAuthFactory
{
    public static function make(object $osmium): TradeAuth
    {
        $db = $osmium->dataSource;
        $trustProxy = (bool) ($osmium->config->site->trustProxyHeaders ?? false);
        $ip = (new IpAddress())->getVisitorIP($trustProxy);

        return new TradeAuth(
            customers: new TradeCustomer($db),
            links: new TradeMagicLink($db),
            codes: new TradeOtpCode($db),
            attempts: new TradeLoginAttempt($db),
            mailer: new TradeMailer($osmium),
            session: $_SESSION,
            ipAddress: $ip,
            userAgent: (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            codeRequired: (bool) TradeConfig::get()['codeRequired'],
        );
    }
}
