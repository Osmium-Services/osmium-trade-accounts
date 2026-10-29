<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Answers core's `page.claim` hook for the four Trade Accounts pages. The theme supplies the views (it names them
 * relative to the site's module path); the claim hands over the data. A claim cannot redirect, so a visitor who is
 * not logged in and asks for the account page is shown the login view in place; every form posts to an api/trade-*
 * route, which can redirect.
 *
 * Views and their data. Every form needs a hidden field csrf_token holding the site's usual $template['formToken']:
 *   trade/views/login.phtml    $tradeLogin: action, honeypot (hidden field name, must stay empty), error,
 *                              sent (true after a request), email
 *   trade/views/verify.phtml   $tradeVerify: action, token, tokenOk (false when the address has no usable token)
 *   trade/views/code.phtml     $tradeCode: action, email, error, notice; a second form posts type=resend to the same action
 *   trade/views/account.phtml  $tradeAccount: customer (the row), logoutAction
 */
final class TradeClaim
{
    public const HONEYPOT = 'company_website';
    public const SESSION_TOKEN = 'trade_verify_token';

    /** @param array{pageId: int, slug: string, osmium: object, data?: array} $payload */
    public static function page(array $payload): ?array
    {
        $slug = $payload['slug'];
        $isOurs = \in_array($slug, TradePages::urls(), true);
        if (!$isOurs) return null;

        $osmium = $payload['osmium'];
        $auth = TradeAuthFactory::make($osmium);
        $flash = TradeFlash::take($_SESSION);
        $site = $osmium->config->site;
        $slash = ($site->urlTrailingSlash ?? false) ? '/' : '';

        $customer = $auth->currentCustomer();
        $loggedIn = $customer !== null;
        if ($loggedIn) return self::account(customer: $customer, slash: $slash);

        $isVerify = $slug === 'trade/verify';
        if ($isVerify) return self::verify(osmium: $osmium, slash: $slash);

        $isCode = $slug === 'trade/code';
        $pending = $isCode ? $auth->codePendingCustomer() : null;
        if ($pending !== null) return self::code(pending: $pending, flash: $flash, slash: $slash);

        return self::login(flash: $flash, slash: $slash);
    }

    /** @return array{view: string, data: array<string, mixed>} */
    private static function login(array $flash, string $slash): array
    {
        return ['view' => 'trade/views/login.phtml', 'data' => ['tradeLogin' => [
            'action' => '/api/trade-login' . $slash,
            'honeypot' => self::HONEYPOT,
            'error' => $flash['error'] ?? null,
            'sent' => !empty($flash['sent']),
            'email' => (string) ($flash['email'] ?? ''),
        ]]];
    }

    /** @return array{view: string, data: array<string, mixed>} */
    private static function verify(object $osmium, string $slash): array
    {
        $token = self::verifyToken($osmium);

        return ['view' => 'trade/views/verify.phtml', 'data' => ['tradeVerify' => [
            'action' => '/api/trade-verify' . $slash,
            'token' => $token,
            'tokenOk' => TradeLoginRules::isTokenFormat($token),
        ]]];
    }

    /**
     * The token from the emailed link. Core moves query values into the session and redirects, so it is read as a
     * stashed param; a usable one is kept in the session so a reload of the page still has it.
     */
    private static function verifyToken(object $osmium): string
    {
        $arrived = (string) $osmium->getStashedParam(key: 't', default: '');
        $osmium->clearStashedQuerystring();

        $usable = TradeLoginRules::isTokenFormat($arrived);
        if ($usable) $_SESSION[self::SESSION_TOKEN] = $arrived;

        return (string) ($_SESSION[self::SESSION_TOKEN] ?? '');
    }

    /** @return array{view: string, data: array<string, mixed>} */
    private static function code(array $pending, array $flash, string $slash): array
    {
        return ['view' => 'trade/views/code.phtml', 'data' => ['tradeCode' => [
            'action' => '/api/trade-code' . $slash,
            'email' => (string) $pending['email'],
            'error' => $flash['error'] ?? null,
            'notice' => $flash['notice'] ?? null,
        ]]];
    }

    /** @return array{view: string, data: array<string, mixed>} */
    private static function account(array $customer, string $slash): array
    {
        return ['view' => 'trade/views/account.phtml', 'data' => ['tradeAccount' => [
            'customer' => $customer,
            'logoutAction' => '/api/trade-logout' . $slash,
        ]]];
    }
}
