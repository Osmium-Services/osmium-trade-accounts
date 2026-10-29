<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * The hook entry points for the LACRM adapter (see service.json): they build a TradeLacrmAdapter wired to the real
 * hooks and customers. Trade names LACRM's hooks but never calls its PHP, and works with LACRM not installed.
 */
final class TradeLacrmHooks
{
    private const SERVICE_ID = 'osmium-lacrm';

    /** trade.sources: offered as a customer source while the LACRM service is installed and enabled. */
    public static function source(array $payload): ?array
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) return null;

        $installed = (new ServiceLoader(new ServiceModel($db)))->isEnabled(self::SERVICE_ID);
        if (!$installed) return null;

        return ['id' => TradeLacrmMapper::SOURCE, 'label' => 'Less Annoying CRM', 'ready' => true, 'settingsRoute' => 'admin/settings/lacrm'];
    }

    public static function formSubmitted(array $payload): void
    {
        self::adapter($payload)?->formSubmitted($payload);
    }

    public static function saleWon(array $payload): void
    {
        self::adapter($payload)?->saleWon($payload);
    }

    public static function saleLost(array $payload): void
    {
        self::adapter($payload)?->saleLost($payload);
    }

    public static function contactUpdated(array $payload): void
    {
        self::adapter($payload)?->contactUpdated($payload);
    }

    /** The bulk import, or null when LACRM is not the selected source. Errors from LACRM reach the caller. */
    public static function importer(OsmiumPDO $db): ?TradeLacrmImport
    {
        $notSelected = (string) TradeConfig::get()['source'] !== TradeLacrmMapper::SOURCE;
        if ($notSelected) return null;

        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($db)));
        $customers = new TradeCustomer($db);

        return new TradeLacrmImport(
            ask: static fn(string $question, array $asked): mixed => $hooks->handle($question, $asked + ['dataSource' => $db]),
            announce: static fn(string $event, array $told) => $hooks->dispatch($event, $told + ['dataSource' => $db]),
            findByRef: static fn(string $ref): ?array => $customers->getBySource(source: TradeLacrmMapper::SOURCE, sourceRef: $ref),
            emailOwner: static function (string $email) use ($customers): ?string {
                $holder = $customers->getByEmail($email);
                $tiedElsewhere = $holder !== null && $holder['source'] !== null;

                return $tiedElsewhere ? (string) $holder['company_name'] : null;
            },
        );
    }

    private static function adapter(array $payload): ?TradeLacrmAdapter
    {
        $db = $payload['dataSource'] ?? null;
        $noDatabase = !($db instanceof OsmiumPDO);
        if ($noDatabase) return null;

        $selected = (string) TradeConfig::get()['source'];
        $notSelected = $selected !== TradeLacrmMapper::SOURCE;
        if ($notSelected) return null; // nothing is asked of LACRM unless it is the chosen source

        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($db)));
        $customers = new TradeCustomer($db);

        return new TradeLacrmAdapter(
            selectedSource: $selected,
            ask: static fn(string $question, array $asked): mixed => $hooks->handle($question, $asked + ['dataSource' => $db]),
            announce: static fn(string $event, array $told) => $hooks->dispatch($event, $told + ['dataSource' => $db]),
            findByRef: static fn(string $ref): ?array => $customers->getBySource(source: TradeLacrmMapper::SOURCE, sourceRef: $ref),
            log: static fn(string $message) => \error_log($message),
        );
    }
}
