<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * Extra fields other services add to the customer edit form (trade.customer.fields, trade.customer.fields.validate and
 * trade.customer.fields.save), so a service such as Trade Ordering can keep its own per-customer data without Trade
 * knowing it. The shaping is pure; the hook calls are at the bottom.
 */
final class TradeCustomerExtras
{
    public const TYPES = ['text', 'number', 'textarea'];
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const FORM_PREFIX = 'ext_';

    /**
     * What every service answered to trade.customer.fields, as one list of fields and one map of values.
     *
     * @param array<int, mixed> $answers One answer per service: ['fields' => [...], 'values' => [customerId => [name => value]]]
     * @return array{fields: array<int, array<string, mixed>>, values: array<int, array<string, string>>}
     */
    public static function merge(array $answers): array
    {
        $fields = [];
        $values = [];

        foreach ($answers as $answer) {
            $usable = \is_array($answer);
            if (!$usable) continue;

            foreach ((array) ($answer['fields'] ?? []) as $field) {
                $clean = self::field($field);
                $skip = $clean === null || isset($fields[$clean['name']]); // unusable, or a name another service already took
                if (!$skip) $fields[$clean['name']] = $clean;
            }

            foreach ((array) ($answer['values'] ?? []) as $customerId => $byName) {
                foreach ((array) $byName as $name => $value) {
                    $values[(int) $customerId][(string) $name] = \is_scalar($value) ? (string) $value : '';
                }
            }
        }

        // A value only counts for a field that was defined
        foreach ($values as $customerId => $byName) {
            $values[$customerId] = \array_intersect_key($byName, $fields);
        }

        return ['fields' => \array_values($fields), 'values' => $values];
    }

    /**
     * The extra values a form posted, only for fields that were defined.
     *
     * @param array<string, mixed> $input The posted form
     * @param array<int, array<string, mixed>> $fields The merged field list
     * @return array<string, string>
     */
    public static function submitted(array $input, array $fields): array
    {
        $submitted = [];
        foreach ($fields as $field) {
            $key = self::FORM_PREFIX . $field['name'];
            $posted = isset($input[$key]) && \is_scalar($input[$key]);
            if ($posted) $submitted[$field['name']] = \trim((string) $input[$key]);
        }

        return $submitted;
    }

    /**
     * Problems services reported to trade.customer.fields.validate.
     *
     * @param array<int, mixed> $answers Each a message, a list of messages, or null
     * @return string[]
     */
    public static function problems(array $answers): array
    {
        $problems = [];
        foreach ($answers as $answer) {
            foreach ((array) $answer as $message) {
                $isMessage = \is_string($message) && \trim($message) !== '';
                if ($isMessage) $problems[] = \trim($message);
            }
        }

        return $problems;
    }

    /** @return array<string, mixed>|null A field description cleaned up, or null when it is not usable */
    public static function field(mixed $field): ?array
    {
        $isArray = \is_array($field);
        if (!$isArray) return null;

        $name = (string) ($field['name'] ?? '');
        $label = \trim((string) ($field['label'] ?? ''));
        $type = (string) ($field['type'] ?? 'text');
        $usable = \preg_match(self::NAME_PATTERN, $name) === 1 && $label !== '' && \in_array($type, self::TYPES, true);
        if (!$usable) return null;

        return [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'help' => \trim((string) ($field['help'] ?? '')),
            'default' => \is_scalar($field['default'] ?? null) ? (string) $field['default'] : '',
            'min' => \is_numeric($field['min'] ?? null) ? (string) $field['min'] : '',
            'max' => \is_numeric($field['max'] ?? null) ? (string) $field['max'] : '',
            'step' => \is_numeric($field['step'] ?? null) ? (string) $field['step'] : '',
        ];
    }

    // The hook calls

    /**
     * @param int[] $customerIds The customers on the page
     * @return array{fields: array<int, array<string, mixed>>, values: array<int, array<string, string>>}
     */
    public static function load(OsmiumPDO $db, array $customerIds): array
    {
        $answers = self::hooks($db)->collect('trade.customer.fields', ['customerIds' => $customerIds, 'dataSource' => $db]);

        return self::merge($answers);
    }

    /** @param array<string, string> $values @return string[] */
    public static function validate(OsmiumPDO $db, int $customerId, array $values): array
    {
        $answers = self::hooks($db)->collect('trade.customer.fields.validate', ['customerId' => $customerId, 'values' => $values, 'dataSource' => $db]);

        return self::problems($answers);
    }

    /** @param array<string, string> $values */
    public static function save(OsmiumPDO $db, int $customerId, array $values): void
    {
        self::hooks($db)->dispatch('trade.customer.fields.save', ['customerId' => $customerId, 'values' => $values, 'dataSource' => $db]);
    }

    private static function hooks(OsmiumPDO $db): ServiceHooks
    {
        return new ServiceHooks(new ServiceLoader(new ServiceModel($db)));
    }
}
