<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeSources;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeConfig.php';
require_once __DIR__ . '/../models/TradeSources.php';

final class TradeSourcesTest extends TestCase
{
    public function test_no_answers_means_no_options(): void
    {
        $this->assertSame([], TradeSources::options([]));
    }

    public function test_a_good_descriptor_becomes_an_available_option(): void
    {
        $options = TradeSources::options([['id' => 'lacrm', 'label' => 'Less Annoying CRM', 'ready' => true, 'settingsRoute' => 'admin/settings/lacrm']]);

        $this->assertSame(['lacrm' => ['id' => 'lacrm', 'label' => 'Less Annoying CRM', 'ready' => true, 'settingsRoute' => 'admin/settings/lacrm', 'available' => true]], $options);
    }

    public function test_descriptors_with_a_bad_id_or_no_label_are_dropped(): void
    {
        $options = TradeSources::options([
            ['id' => 'BAD ID', 'label' => 'x'],
            ['id' => 'nolabel', 'label' => '  '],
            'not an array',
            ['label' => 'no id'],
            ['id' => 'fine', 'label' => 'Fine'],
        ]);

        $this->assertSame(['fine'], \array_keys($options));
        $this->assertFalse($options['fine']['ready']);
        $this->assertNull($options['fine']['settingsRoute']);
    }

    public function test_the_first_of_a_repeated_id_wins(): void
    {
        $options = TradeSources::options([['id' => 'a', 'label' => 'First'], ['id' => 'a', 'label' => 'Second']]);

        $this->assertSame('First', $options['a']['label']);
    }

    public function test_a_saved_source_that_is_no_longer_offered_is_shown_as_not_installed(): void
    {
        $options = TradeSources::options([['id' => 'a', 'label' => 'A']], 'gone');

        $this->assertFalse($options['gone']['available']);
        $this->assertSame('gone (not installed)', $options['gone']['label']);
        $this->assertTrue($options['a']['available']);
    }

    public function test_a_saved_source_that_is_still_offered_is_not_duplicated(): void
    {
        $options = TradeSources::options([['id' => 'a', 'label' => 'A']], 'a');

        $this->assertCount(1, $options);
        $this->assertTrue($options['a']['available']);
    }
}
