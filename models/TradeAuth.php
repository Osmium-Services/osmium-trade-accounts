<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * Passwordless login for trade customers: an emailed link, then (optionally) an emailed 6-digit code.
 *
 * Nothing here reveals whether an address has an account: asking for a link always looks the same unless the
 * visitor's own address has asked too often. The session is passed in by reference so a test can use a plain array.
 */
class TradeAuth
{
    public const SESSION_SECONDS = 7200; // logged out after two hours without a request

    public const SESSION_ID = 'trade_customer_id';
    public const SESSION_TIME = 'trade_login_time';
    public const SESSION_CODE_CUSTOMER = 'trade_code_customer_id';
    public const SESSION_CODE_WRONG = 'trade_code_wrong';

    /** @var callable(): void */
    private $regenerateSession;

    /**
     * @param array<string, mixed> $session
     * @param callable(): void|null $regenerateSession Called at the moment of login (new session id); default does it for the real session
     */
    public function __construct(
        private TradeCustomer $customers,
        private TradeMagicLink $links,
        private TradeOtpCode $codes,
        private TradeLoginAttempt $attempts,
        private TradeLoginMailer $mailer,
        private array &$session,
        private string $ipAddress,
        private string $userAgent,
        private bool $codeRequired = true,
        ?callable $regenerateSession = null,
    ) {
        $this->regenerateSession = $regenerateSession ?? static function (): void {
            $active = \session_status() === PHP_SESSION_ACTIVE;
            if ($active) \session_regenerate_id(true);
        };
    }

    // Asking for a link

    /** @return array{success: bool, error: ?string} */
    public function requestLink(string $email): array
    {
        $email = \strtolower(\trim($email));

        $ipOver = TradeLoginRules::ipOverLimit($this->attempts->requestsFromIp($this->ipAddress));
        if ($ipOver) {
            $this->log(email: $email, customerId: null, reason: 'ip_rate_limited');

            return ['success' => false, 'error' => 'Too many requests. Please try again later.'];
        }

        $customer = $this->customers->getByEmail($email);
        $unknown = $customer === null;
        if ($unknown) {
            $this->log(email: $email, customerId: null, reason: 'unknown_email');

            return $this->looksSent();
        }

        $id = (int) $customer['id'];

        $locked = $this->isLocked($customer);
        if ($locked) {
            $this->log(email: $email, customerId: $id, reason: 'locked');

            return $this->looksSent();
        }

        $inactive = !TradeStatus::canLogin($customer['status']);
        if ($inactive) {
            $this->log(email: $email, customerId: $id, reason: 'inactive');

            return $this->looksSent();
        }

        $tooSoon = $this->links->issuedRecently($id);
        if ($tooSoon) {
            $this->log(email: $email, customerId: $id, reason: 'email_rate_limited');

            return $this->looksSent();
        }

        $token = $this->links->issue(customerId: $id, ipAddress: $this->ipAddress, userAgent: $this->userAgent);
        $this->mailer->sendLink(customer: $customer, token: $token);
        $this->log(email: $email, customerId: $id, reason: 'link_sent');

        return $this->looksSent();
    }

    // Clicking it

    /** @return array{success: bool, error: ?string, reason: ?string, codeRequired: bool} */
    public function useLink(string $token): array
    {
        $wrongShape = !TradeLoginRules::isTokenFormat($token);
        if ($wrongShape) {
            $this->log(email: '', customerId: null, reason: 'link_invalid');

            return $this->linkFailed('link_invalid');
        }

        $link = $this->links->consume($token);
        $notUsable = $link === null;
        if ($notUsable) return $this->explainBadLink($token);

        $customer = $this->customers->getById((int) $link['customer_id']);
        $gone = $customer === null || !TradeStatus::canLogin($customer['status']);
        if ($gone) {
            $this->log(email: '', customerId: (int) $link['customer_id'], reason: 'inactive');

            return $this->linkFailed(reason: 'inactive', message: 'Your account is not active.');
        }

        $locked = $this->isLocked($customer);
        if ($locked) {
            $this->log(email: $customer['email'], customerId: (int) $customer['id'], reason: 'locked');

            return $this->linkFailed(reason: 'locked', message: 'This account is temporarily locked. Please try again later.');
        }

        if ($this->codeRequired) return $this->startCodeStep($customer);

        $this->finishLogin($customer);

        return ['success' => true, 'error' => null, 'reason' => null, 'codeRequired' => false];
    }

    // The code step

    /** The customer who clicked a link and now owes a code, or null when there is no such login in progress. */
    public function codePendingCustomer(): ?array
    {
        $id = (int) ($this->session[self::SESSION_CODE_CUSTOMER] ?? 0);
        $none = $id === 0;
        if ($none) return null;

        $customer = $this->customers->getById($id);
        $gone = $customer === null || !TradeStatus::canLogin($customer['status']);
        if ($gone) {
            $this->clearCodeStep();

            return null;
        }

        return $customer;
    }

    /** @return array{success: bool, error: ?string, abandoned: bool} */
    public function verifyCode(string $code): array
    {
        $customer = $this->codePendingCustomer();
        $noLogin = $customer === null;
        if ($noLogin) return ['success' => false, 'error' => 'Your login has timed out. Please start again.', 'abandoned' => true];

        $id = (int) $customer['id'];
        $right = $this->codes->consume(customerId: $id, code: \trim($code));
        if ($right) {
            $this->clearCodeStep();
            $this->finishLogin($customer);

            return ['success' => true, 'error' => null, 'abandoned' => false];
        }

        $this->log(email: $customer['email'], customerId: $id, reason: 'code_invalid');

        $wrong = (int) ($this->session[self::SESSION_CODE_WRONG] ?? 0) + 1;
        $this->session[self::SESSION_CODE_WRONG] = $wrong;

        $abandoned = TradeLoginRules::codeAbandoned($wrong);
        if ($abandoned) {
            $this->recordFailure($id);
            $this->clearCodeStep();

            return ['success' => false, 'error' => 'Too many incorrect codes. Please request a new login link.', 'abandoned' => true];
        }

        $left = TradeLoginRules::wrongCodesLeft($wrong);

        return ['success' => false, 'error' => "That code is not right. {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.', 'abandoned' => false];
    }

    /** @return array{success: bool, error: ?string} */
    public function resendCode(): array
    {
        $customer = $this->codePendingCustomer();
        $noLogin = $customer === null;
        if ($noLogin) return ['success' => false, 'error' => 'Your login has timed out. Please start again.'];

        $tooSoon = $this->codes->sentRecently((int) $customer['id']);
        if ($tooSoon) return ['success' => false, 'error' => 'Please wait a minute before asking for another code.'];

        $sent = $this->sendCode($customer);
        if (!$sent) return ['success' => false, 'error' => 'We could not send your code. Please try again.'];

        $this->session[self::SESSION_CODE_WRONG] = 0;

        return ['success' => true, 'error' => null];
    }

    // Being logged in

    /**
     * The logged-in customer, or null. Reads the customer afresh each time, so a customer suspended or deleted
     * after logging in is out on their next request.
     */
    public function currentCustomer(): ?array
    {
        $id = (int) ($this->session[self::SESSION_ID] ?? 0);
        $since = (int) ($this->session[self::SESSION_TIME] ?? 0);
        $none = $id === 0 || $since === 0;
        if ($none) return null;

        $timedOut = \time() - $since > self::SESSION_SECONDS;
        if ($timedOut) {
            $this->logout();

            return null;
        }

        $customer = $this->customers->getById($id);
        $gone = $customer === null || !TradeStatus::canLogin($customer['status']);
        if ($gone) {
            $this->logout();

            return null;
        }

        $this->session[self::SESSION_TIME] = \time();

        return $customer;
    }

    public function logout(): void
    {
        unset($this->session[self::SESSION_ID], $this->session[self::SESSION_TIME]);
        $this->clearCodeStep();
    }

    /**
     * Logs a customer in directly: for an admin's "Login as customer", which skips the link and code and leaves the
     * session id alone (the admin is already signed in on it).
     */
    public function loginAs(array $customer, bool $newSessionId = true): void
    {
        $this->clearCodeStep();
        if ($newSessionId) ($this->regenerateSession)();
        $this->session[self::SESSION_ID] = (int) $customer['id'];
        $this->session[self::SESSION_TIME] = \time();
    }

    // Inside

    /** @return array{success: bool, error: null} */
    private function looksSent(): array
    {
        return ['success' => true, 'error' => null];
    }

    /** @param array<string, mixed> $customer */
    private function isLocked(array $customer): bool
    {
        $id = (int) $customer['id'];
        $now = \time();

        $running = TradeLoginRules::isLocked($customer['lockout_until'] ?? null, $now);
        if ($running) return true;

        $ranOut = !empty($customer['lockout_until']);
        if ($ranOut) {
            $this->customers->clearFailedLogins($id);

            return false;
        }

        $tooMany = TradeLoginRules::shouldLock((int) $customer['failed_login_attempts']);
        if ($tooMany) {
            $this->customers->lock($id);

            return true;
        }

        return false;
    }

    private function recordFailure(int $customerId): void
    {
        $this->customers->addFailedLogin($customerId);

        $customer = $this->customers->getById($customerId);
        $tooMany = $customer !== null && TradeLoginRules::shouldLock((int) $customer['failed_login_attempts']);
        if ($tooMany) $this->customers->lock($customerId);
    }

    /** @return array{success: bool, error: ?string, reason: ?string, codeRequired: bool} */
    private function explainBadLink(string $token): array
    {
        $link = $this->links->getByToken($token);
        $reason = TradeLoginRules::linkFailure(link: $link, now: \time());

        $customerId = $link === null ? null : (int) $link['customer_id'];
        $this->log(email: '', customerId: $customerId, reason: $reason);
        if ($customerId !== null) $this->recordFailure($customerId);

        return $this->linkFailed($reason);
    }

    /** @return array{success: bool, error: ?string, reason: ?string, codeRequired: bool} */
    private function linkFailed(string $reason, ?string $message = null): array
    {
        return ['success' => false, 'error' => $message ?? TradeLoginRules::linkFailureMessage($reason), 'reason' => $reason, 'codeRequired' => false];
    }

    /**
     * @param array<string, mixed> $customer
     * @return array{success: bool, error: ?string, reason: ?string, codeRequired: bool}
     */
    private function startCodeStep(array $customer): array
    {
        $this->session[self::SESSION_CODE_CUSTOMER] = (int) $customer['id'];
        $this->session[self::SESSION_CODE_WRONG] = 0;

        $sent = $this->sendCode($customer);
        if (!$sent) {
            $this->clearCodeStep();

            return ['success' => false, 'error' => 'We could not send your verification code. Please try again.', 'reason' => 'code_not_sent', 'codeRequired' => false];
        }

        return ['success' => true, 'error' => null, 'reason' => null, 'codeRequired' => true];
    }

    /** @param array<string, mixed> $customer */
    private function sendCode(array $customer): bool
    {
        try {
            $code = $this->codes->issue((int) $customer['id']);
        } catch (\Throwable $e) {
            \error_log('Trade Accounts: could not make a login code: ' . $e->getMessage());

            return false;
        }

        return $this->mailer->sendCode(customer: $customer, code: $code);
    }

    private function clearCodeStep(): void
    {
        unset($this->session[self::SESSION_CODE_CUSTOMER], $this->session[self::SESSION_CODE_WRONG]);
    }

    /** @param array<string, mixed> $customer */
    private function finishLogin(array $customer): void
    {
        $id = (int) $customer['id'];

        $this->loginAs($customer);
        $this->log(email: $customer['email'], customerId: $id, reason: 'login_success');
        $this->customers->clearFailedLogins($id);
        $this->customers->touchLastLogin($id);
    }

    private function log(string $email, ?int $customerId, string $reason): void
    {
        $this->attempts->log(email: $email, customerId: $customerId, ipAddress: $this->ipAddress, userAgent: $this->userAgent, reason: $reason);
    }
}
