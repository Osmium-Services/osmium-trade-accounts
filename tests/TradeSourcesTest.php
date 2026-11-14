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

        $this->assertSame(['lacrm' => ['id' => 'lacrm', 'label' => 'Less Annoying CRM', 'ready' => true, 'settingsRoute' => 'admin/settings/lacrm', 'importRoute' => null, 'available' => true]], $options);
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

    public function test_an_import_route_is_kept_and_turned_into_the_customers_page_button(): void
    {
        $options = TradeSources::options([['id' => 'crm', 'label' => 'Some CRM', 'ready' => true, 'importRoute' => 'admin/trade/crm-import']]);

        $this->assertSame('admin/trade/crm-import', $options['crm']['importRoute']);
        $this->assertSame(['label' => 'Import from Some CRM', 'path' => 'trade/crm-import/'], TradeSources::importLink($options, 'crm'));
    }

    public function test_there_is_no_import_button_without_a_selected_ready_source_that_has_an_import_page(): void
    {
        $options = TradeSources::options([
            ['id' => 'crm', 'label' => 'Some CRM', 'ready' => true, 'importRoute' => 'admin/trade/crm-import'],
            ['id' => 'plain', 'label' => 'Plain', 'ready' => true],
            ['id' => 'waiting', 'label' => 'Waiting', 'ready' => false, 'importRoute' => 'admin/trade/waiting-import'],
        ], saved: 'gone');

        $this->assertNull(TradeSources::importLink($options, ''), 'added by hand');
        $this->assertNull(TradeSources::importLink($options, 'plain'), 'no import page');
        $this->assertNull(TradeSources::importLink($options, 'waiting'), 'not ready');
        $this->assertNull(TradeSources::importLink($options, 'gone'), 'selected source no longer installed');
    }
}
