<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

/**
 * The choices for "Customer source": the sources installed services offer by answering the `trade.sources` hook
 * (`id`, `label`, `ready`, `settingsRoute`), cleaned up. Pure: the hook answers are passed in.
 */
final class TradeSources
{
    /**
     * @param array<int, mixed> $answers What the trade.sources handlers returned (each a descriptor array)
     * @return array<string, array{id: string, label: string, ready: bool, settingsRoute: ?string, available: bool}> by id; a descriptor with a bad id or no label is dropped, the first of a repeated id wins
     */
    public static function options(array $answers, string $saved = ''): array
    {
        $options = [];
        foreach ($answers as $answer) {
            $option = self::clean($answer);
            if ($option === null) continue;
            if (isset($options[$option['id']])) continue;

            $options[$option['id']] = $option;
        }

        $savedMissing = $saved !== '' && !isset($options[$saved]);
        if ($savedMissing) $options[$saved] = ['id' => $saved, 'label' => $saved . ' (not installed)', 'ready' => false, 'settingsRoute' => null, 'available' => false];

        return $options;
    }

    /** @return array{id: string, label: string, ready: bool, settingsRoute: ?string, available: bool}|null */
    private static function clean(mixed $answer): ?array
    {
        $notArray = !\is_array($answer);
        if ($notArray) return null;

        $id = (string) ($answer['id'] ?? '');
        $label = \trim((string) ($answer['label'] ?? ''));
        $bad = !TradeConfig::isValidSource($id) || $label === '';
        if ($bad) return null;

        $route = \trim((string) ($answer['settingsRoute'] ?? ''));

        return ['id' => $id, 'label' => $label, 'ready' => !empty($answer['ready']), 'settingsRoute' => $route === '' ? null : $route, 'available' => true];
    }
}
