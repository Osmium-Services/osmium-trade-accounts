<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * What the login needs from the mail side, so the rules can be tested without sending anything.
 */
interface TradeLoginMailer
{
    /** @param array<string, mixed> $customer */
    public function sendLink(array $customer, string $token): bool;

    /** @param array<string, mixed> $customer */
    public function sendCode(array $customer, string $code): bool;
}
