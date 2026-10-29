<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeConfig.php';

final class TradeConfigTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/trade-config-' . \bin2hex(\random_bytes(4)) . '.json.php';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->path)) \unlink($this->path);
    }

    public function test_a_missing_file_gives_the_defaults(): void
    {
        $this->assertSame(['source' => '', 'codeRequired' => true], TradeConfig::read($this->path));
    }

    public function test_saved_values_are_read_back_over_the_defaults(): void
    {
        TradeConfig::save(['source' => 'lacrm', 'codeRequired' => false], $this->path);

        $this->assertSame(['source' => 'lacrm', 'codeRequired' => false], TradeConfig::read($this->path));
    }

    public function test_the_file_cannot_be_read_as_a_web_page_and_unknown_keys_are_dropped(): void
    {
        TradeConfig::save(['source' => 'lacrm', 'secret' => 'x'], $this->path);

        $this->assertStringStartsWith('<?php exit(); ?>', (string) \file_get_contents($this->path));
        $this->assertStringNotContainsString('secret', (string) \file_get_contents($this->path));
    }

    public function test_a_save_keeps_what_it_was_not_given(): void
    {
        TradeConfig::save(['source' => 'lacrm'], $this->path);
        TradeConfig::save(['codeRequired' => false], $this->path);

        $this->assertSame(['source' => 'lacrm', 'codeRequired' => false], TradeConfig::read($this->path));
    }

    public function test_a_damaged_file_gives_the_defaults(): void
    {
        \file_put_contents($this->path, "<?php exit(); ?>\n{not json");

        $this->assertSame(TradeConfig::defaults(), TradeConfig::read($this->path));
    }

    public function test_source_ids_are_lower_case_words_and_dashes_only(): void
    {
        $this->assertTrue(TradeConfig::isValidSource('lacrm'));
        $this->assertTrue(TradeConfig::isValidSource('some-crm-2'));
        foreach (['', 'LACRM', 'a b', 'a/b', '../x', \str_repeat('a', 41)] as $bad) {
            $this->assertFalse(TradeConfig::isValidSource($bad), $bad);
        }
    }
}
