<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * Moves a customer to a new status and tells other services, so later services (Trade Ordering, a CRM bridge) can
 * react without Trade knowing them. Used by the admin and by the neutral events, so both announce the same way.
 */
final class TradeStatusChange
{
    /**
     * @param array<string, mixed> $customer The row as it is now
     * @return bool true when the status changed (and the hook was fired); false when it was already that status
     */
    public static function apply(OsmiumPDO $db, TradeCustomer $model, array $customer, string $to): bool
    {
        $from = (string) $customer['status'];
        $same = $from === $to;
        if ($same) return false;

        $model->setStatus(id: (int) $customer['id'], status: $to);

        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($db)));
        $hooks->dispatch('trade.customer.status_changed', [
            'customerId' => (int) $customer['id'],
            'from' => $from,
            'to' => $to,
            'dataSource' => $db,
        ]);

        return true;
    }
}
