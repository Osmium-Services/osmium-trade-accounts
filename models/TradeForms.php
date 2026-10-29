<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * What the four Trade forms do with a submission, called from the api/trade-* routes. Each one checks the guard,
 * acts through TradeAuth, leaves a one-time message (TradeFlash) and redirects: the page it comes back to shows
 * the message. Nothing here says which check failed.
 */
final class TradeForms
{
    private const TRY_AGAIN = 'Something went wrong. Please try again.';

    public static function login(object $osmium): void
    {
        $to = self::paths($osmium);
        $notPost = ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST';
        if ($notPost) self::go($osmium, $to['login']);

        $bot = TradeFormGuard::honeypotFilled($_POST);
        $passed = self::guard(minSeconds: TradeFormGuard::MIN_SECONDS_LOGIN) && self::protectionPassed($osmium);
        $email = \strtolower(\trim((string) ($_POST['email'] ?? '')));

        if ($bot) self::done($osmium, $to['login'], ['sent' => true, 'email' => $email]); // a bot is told it worked
        if (!$passed) self::done($osmium, $to['login'], ['error' => self::TRY_AGAIN, 'email' => $email]);

        $validEmail = \filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        if (!$validEmail) self::done($osmium, $to['login'], ['error' => 'Please enter a valid email address.', 'email' => $email]);

        $result = TradeAuthFactory::make($osmium)->requestLink($email);
        if (!$result['success']) self::done($osmium, $to['login'], ['error' => $result['error'], 'email' => $email]);

        self::done($osmium, $to['login'], ['sent' => true, 'email' => $email]);
    }

    /** The visitor pressed Continue on the page the emailed link opens: this is what uses the link up. */
    public static function verify(object $osmium): void
    {
        $to = self::paths($osmium);
        $notPost = ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST';
        if ($notPost) self::go($osmium, $to['login']);

        $token = (string) ($_POST['t'] ?? '');
        unset($_SESSION[TradeClaim::SESSION_TOKEN]);

        $passed = self::guard();
        if (!$passed) self::done($osmium, $to['login'], ['error' => self::TRY_AGAIN]);

        $result = TradeAuthFactory::make($osmium)->useLink($token);
        if (!$result['success']) self::done($osmium, $to['login'], ['error' => $result['error']]);

        self::go($osmium, $result['codeRequired'] ? $to['code'] : $to['account']);
    }

    /** Both forms of the code page: type=resend sends a new code, anything else checks the one typed. */
    public static function code(object $osmium): void
    {
        $to = self::paths($osmium);
        $notPost = ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST';
        if ($notPost) self::go($osmium, $to['login']);

        $passed = self::guard();
        if (!$passed) self::done($osmium, $to['code'], ['error' => self::TRY_AGAIN]);

        $auth = TradeAuthFactory::make($osmium);

        $isResend = ($_POST['type'] ?? '') === 'resend';
        if ($isResend) {
            $sent = $auth->resendCode();
            self::done($osmium, $to['code'], $sent['success'] ? ['notice' => 'A new code has been sent to your email.'] : ['error' => $sent['error']]);
        }

        $result = $auth->verifyCode((string) ($_POST['code'] ?? ''));
        if ($result['success']) self::go($osmium, $to['account']);
        if ($result['abandoned']) self::done($osmium, $to['login'], ['error' => $result['error']]);

        self::done($osmium, $to['code'], ['error' => $result['error']]);
    }

    public static function logout(object $osmium): void
    {
        $to = self::paths($osmium);
        $notPost = ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST';
        if ($notPost) self::go($osmium, $to['login']);

        $passed = self::guard();
        if ($passed) TradeAuthFactory::make($osmium)->logout();

        self::go($osmium, $to['login']);
    }

    // Inside

    /** Token, honeypot and (for the login form) speed, then the token is spent either way. */
    private static function guard(int $minSeconds = 0): bool
    {
        $now = \time();
        $tokenOk = TradeFormGuard::tokenMatches($_POST, $_SESSION);
        $filled = TradeFormGuard::honeypotFilled($_POST);
        $fast = TradeFormGuard::tooFast(session: $_SESSION, now: $now, minSeconds: $minSeconds);

        $_SESSION = TradeFormGuard::spend($_SESSION);

        return $tokenOk && !$filled && !$fast;
    }

    /** Any installed bot protection answers form.verify; Trade never names one. */
    private static function protectionPassed(object $osmium): bool
    {
        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($osmium->dataSource)));
        $answers = $hooks->collect('form.verify', ['form' => 'trade-login', 'post' => $_POST, 'dataSource' => $osmium->dataSource]);

        return !\in_array(false, $answers, true);
    }

    /** @return array{login: string, code: string, account: string} */
    private static function paths(object $osmium): array
    {
        $slash = ($osmium->config->site->urlTrailingSlash ?? false) ? '/' : '';

        return ['login' => '/trade/login' . $slash, 'code' => '/trade/code' . $slash, 'account' => '/trade' . $slash];
    }

    /** @param array<string, mixed> $flash */
    private static function done(object $osmium, string $to, array $flash): never
    {
        TradeFlash::set($_SESSION, $flash);
        self::go($osmium, $to);
    }

    private static function go(object $osmium, string $to): never
    {
        $osmium->header->redirect(targetURL: $to, statusCode: 302);
        exit; // redirect() ends the request; this only satisfies "never"
    }
}
