<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeCustomerExtras;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeCustomerExtras.php';

final class TradeCustomerExtrasTest extends TestCase
{
    private function field(string $name = 'discount_percent', array $more = []): array
    {
        return \array_merge(['name' => $name, 'label' => 'Trade discount %', 'type' => 'number'], $more);
    }

    public function test_fields_from_several_services_are_merged_and_cleaned(): void
    {
        $merged = TradeCustomerExtras::merge([
            ['fields' => [$this->field(more: ['min' => 0, 'max' => 100, 'step' => '0.5', 'help' => ' Percent '])]],
            ['fields' => [$this->field('payment_terms', ['label' => 'Payment terms', 'type' => 'text', 'default' => '30 days'])]],
        ]);

        $this->assertSame(['discount_percent', 'payment_terms'], \array_column($merged['fields'], 'name'));
        $this->assertSame('0', $merged['fields'][0]['min']);
        $this->assertSame('100', $merged['fields'][0]['max']);
        $this->assertSame('Percent', $merged['fields'][0]['help']);
        $this->assertSame('30 days', $merged['fields'][1]['default']);
    }

    public function test_unusable_fields_and_taken_names_are_dropped(): void
    {
        $merged = TradeCustomerExtras::merge([
            ['fields' => [
                $this->field('Bad Name'),
                $this->field('no_label', ['label' => '  ']),
                $this->field('odd_type', ['type' => 'file']),
                'not an array',
                $this->field(),
            ]],
            ['fields' => [$this->field(more: ['label' => 'Second service'])]],
            'not an answer',
        ]);

        $this->assertSame(['discount_percent'], \array_column($merged['fields'], 'name'));
        $this->assertSame('Trade discount %', $merged['fields'][0]['label']); // the first to claim a name keeps it
    }

    public function test_values_are_kept_per_customer_for_defined_fields_only(): void
    {
        $merged = TradeCustomerExtras::merge([
            ['fields' => [$this->field()], 'values' => [7 => ['discount_percent' => 12.5, 'stray' => 'x'], '8' => ['discount_percent' => '5']]],
        ]);

        $this->assertSame([7 => ['discount_percent' => '12.5'], 8 => ['discount_percent' => '5']], $merged['values']);
    }

    public function test_only_posted_values_for_defined_fields_are_taken_and_trimmed(): void
    {
        $fields = TradeCustomerExtras::merge([['fields' => [$this->field()]]])['fields'];

        $submitted = TradeCustomerExtras::submitted(['ext_discount_percent' => ' 10 ', 'ext_other' => 'x', 'discount_percent' => '99'], $fields);

        $this->assertSame(['discount_percent' => '10'], $submitted);
        $this->assertSame([], TradeCustomerExtras::submitted([], $fields));
    }

    public function test_problems_are_gathered_from_messages_and_lists_and_blanks_are_ignored(): void
    {
        $problems = TradeCustomerExtras::problems(['Too high', ['Bad terms', '  ', 5], '', null]);

        $this->assertSame(['Too high', 'Bad terms'], $problems);
    }
}
