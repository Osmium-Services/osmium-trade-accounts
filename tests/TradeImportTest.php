<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeAddress;
use Osmium\Services\TradeAccounts\Models\TradeImportFields;
use Osmium\Services\TradeAccounts\Models\TradeImportFile;
use Osmium\Services\TradeAccounts\Models\TradeImportPlan;
use Osmium\Services\TradeAccounts\Models\TradeSpreadsheet;
use PHPUnit\Framework\TestCase;

foreach (['TradeStatus', 'TradeCustomerInput', 'TradeSpreadsheet', 'TradeImportFields', 'TradeImportPlan', 'TradeImportFile', 'TradeAddress'] as $model) {
    require_once __DIR__ . "/../models/{$model}.php";
}

final class TradeImportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/trade-import-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) \unlink($file);
        \rmdir($this->dir);
    }

    public function test_csv_with_bom_quotes_and_semicolons_is_read(): void
    {
        $path = $this->dir . '/a.csv';
        \file_put_contents($path, "\xEF\xBB\xBFCompany;Email\r\n\"Smith; Sons\";a@example.com\r\n\r\n");

        $this->assertSame([['Company', 'Email'], ['Smith; Sons', 'a@example.com']], TradeSpreadsheet::read($path, 'csv'));
    }

    public function test_windows_1252_csv_is_converted_to_utf8(): void
    {
        $path = $this->dir . '/a.csv';
        \file_put_contents($path, "Company,Email\n" . \mb_convert_encoding('Café Ltd', 'Windows-1252', 'UTF-8') . ",a@example.com\n");

        $this->assertSame('Café Ltd', TradeSpreadsheet::read($path, 'csv')[1][0]);
    }

    public function test_xlsx_is_read_with_shared_inline_and_numeric_cells_and_gaps(): void
    {
        $path = $this->xlsx([
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row>',
            '<row r="2"><c r="A2" t="inlineStr"><is><t>Acme</t></is></c><c r="B2"><v>7700900123</v></c><c r="C2" t="s"><v>2</v></c></row>',
        ], ['Company', 'Email', '<r><t>a@</t></r><r><t>example.com</t></r>']);

        $this->assertSame([['Company', '', 'Email'], ['Acme', '7700900123', 'a@example.com']], TradeSpreadsheet::read($path, 'xlsx'));
    }

    public function test_xlsx_first_sheet_is_found_through_the_workbook_and_its_relationships(): void
    {
        $path = $this->dir . '/b.xlsx';
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', "<workbook {$ns} xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"Customers\" sheetId=\"1\" r:id=\"rId7\"/></sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', "<worksheet {$ns}><sheetData><row r=\"1\"><c r=\"A1\" t=\"inlineStr\"><is><t>Wrong</t></is></c></row></sheetData></worksheet>");
        $zip->addFromString('xl/worksheets/sheet2.xml', "<worksheet {$ns}><sheetData><row r=\"1\"><c r=\"A1\" t=\"inlineStr\"><is><t>Right</t></is></c></row></sheetData></worksheet>");
        $zip->close();

        $this->assertSame([['Right']], TradeSpreadsheet::read($path, 'xlsx'));
    }

    public function test_xlsx_with_an_absolute_sheet_target_is_read(): void
    {
        $path = $this->dir . '/c.xlsx';
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', "<workbook {$ns} xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"Customers\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="/xl/worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', "<worksheet {$ns}><sheetData><row r=\"1\"><c r=\"A1\" t=\"inlineStr\"><is><t>Found</t></is></c></row></sheetData></worksheet>");
        $zip->close();

        $this->assertSame([['Found']], TradeSpreadsheet::read($path, 'xlsx'));
    }

    public function test_a_file_over_the_size_limit_is_refused_before_it_is_read(): void
    {
        $path = $this->dir . '/big.csv';
        $handle = \fopen($path, 'w');
        \fseek($handle, TradeSpreadsheet::MAX_BYTES + 1);
        \fwrite($handle, 'x');
        \fclose($handle);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('larger than 10 MB');
        TradeSpreadsheet::read($path, 'csv');
    }

    public function test_expired_uploads_are_purged_and_fresh_ones_kept(): void
    {
        $dir = $this->dir . '/store';
        \mkdir($dir);
        $old = TradeImportFile::store($this->makeCsv(), 'csv', $dir);
        $fresh = TradeImportFile::store($this->makeCsv(), 'csv', $dir);
        \touch(TradeImportFile::find($old, $dir)['path'], \time() - 7200);

        TradeImportFile::purgeExpired($dir);

        $this->assertNull(TradeImportFile::find($old, $dir));
        $this->assertNotNull(TradeImportFile::find($fresh, $dir));
        TradeImportFile::delete($fresh, $dir);
        \rmdir($dir);
    }

    private function makeCsv(): string
    {
        $path = $this->dir . '/' . \bin2hex(\random_bytes(3)) . '.csv';
        \file_put_contents($path, "a\n");

        return $path;
    }

    public function test_bad_files_are_refused_in_plain_words(): void
    {
        $path = $this->dir . '/bad.xlsx';
        \file_put_contents($path, 'not a zip');

        $this->expectException(\InvalidArgumentException::class);
        TradeSpreadsheet::read($path, 'xlsx');
    }

    public function test_only_csv_and_xlsx_names_are_accepted(): void
    {
        $this->assertSame('csv', TradeSpreadsheet::extension('x.CSV'));
        $this->assertSame('xlsx', TradeSpreadsheet::extension('x.xlsx'));
        $this->assertNull(TradeSpreadsheet::extension('x.xls'));
        $this->assertNull(TradeSpreadsheet::extension('x.php'));
    }

    public function test_headings_are_guessed_and_each_column_used_once(): void
    {
        $guess = TradeImportFields::guess(['Business Name', 'Email Address', 'Telephone', 'Post Code', 'Name', 'Email 2']);

        $this->assertSame(0, $guess['company_name']);
        $this->assertSame(1, $guess['email']);
        $this->assertSame(2, $guess['phone']);
        $this->assertSame(3, $guess['postcode']);
        $this->assertSame(4, $guess['contact_name']);
        $this->assertSame(5, $guess['email2']);
        $this->assertNull($guess['city']);
    }

    public function test_matching_needs_company_and_email_and_no_repeated_column(): void
    {
        $matching = TradeImportFields::clean(['company_name' => '0', 'email' => '', 'phone' => '9'], 3);
        $this->assertNull($matching['phone'], 'a column past the end is ignored');
        $this->assertStringContainsString('email', (string) TradeImportFields::problem($matching));

        $twice = TradeImportFields::clean(['company_name' => '0', 'email' => '0'], 3);
        $this->assertSame('Each column can only be used for one field', TradeImportFields::problem($twice));

        $this->assertNull(TradeImportFields::problem(TradeImportFields::clean(['company_name' => '0', 'email' => '1'], 3)));
    }

    public function test_plan_never_drops_incomplete_rows_and_skips_only_true_duplicates(): void
    {
        $matching = TradeImportFields::clean(['company_name' => 0, 'email' => 1, 'phone' => 2, 'contact_name' => 3], 4);
        $rows = [
            ['Acme', 'A@Example.com', '7700900123', ''],
            ['Acme again', 'a@example.com', '07700 900123', ''],
            ['Other Co', 'a@example.com', '01632 960000', ''],
            ['Blank Phone Co', 'a@example.com', '', ''],
            ['Old Customer', 'old@example.com', '01632 960001', ''],
            ['Old Customer Two', 'old@example.com', '+44 1632 960002', ''],
            ['No Email', '', '', ''],
            ['', 'x@example.com', '', 'Jo Bloggs'],
            ['', 'y@example.com', '', ''],
            ['Bad Email', 'not-an-email', '', ''],
        ];
        $existing = static fn(string $email) => $email === 'old@example.com' ? ['company_name' => 'Old Customer', 'phone' => '+44 1632 960001'] : null;

        $plan = TradeImportPlan::build($rows, $matching, 'active', $existing);

        $this->assertSame(['new', 'exists', 'new', 'new', 'exists', 'new', 'new', 'new', 'new', 'new'], \array_column($plan, 'result'));
        $this->assertSame('a@example.com', $plan[0]['data']['email']);
        $this->assertSame('07700900123', $plan[0]['data']['phone'], 'the leading 0 Excel dropped is put back');
        $this->assertSame([], $plan[0]['fixes']);
        $this->assertSame(2, $plan[0]['line']);

        $this->assertStringContainsString('same email and phone', \strtolower($plan[1]['reason']), 'same email and the same number, written differently');
        $this->assertStringEndsWith('@placeholder.invalid', $plan[2]['data']['email'], 'same email, different phone: kept, with a placeholder');
        $this->assertStringContainsString('"a@example.com" is already used by Acme', $plan[2]['data']['notes']);
        $this->assertStringEndsWith('@placeholder.invalid', $plan[3]['data']['email'], 'a blank phone cannot confirm a duplicate');
        $this->assertNotSame($plan[2]['data']['email'], $plan[3]['data']['email']);
        $this->assertSame('exists', $plan[4]['result'], '+44 and 0 forms of an existing customer\'s number match');
        $this->assertStringEndsWith('@placeholder.invalid', $plan[5]['data']['email']);

        $this->assertStringContainsString('Email was blank', $plan[6]['data']['notes']);
        $this->assertSame('Jo Bloggs', $plan[7]['data']['company_name'], 'the contact name stands in for a missing company');
        $this->assertSame('Unknown company', $plan[8]['data']['company_name']);
        $this->assertStringContainsString('"not-an-email"', $plan[9]['data']['notes'], 'the original bad email is kept in the notes');

        $this->assertSame(['new' => 8, 'fixed' => 7, 'exists' => 2, 'invalid' => 0], TradeImportPlan::counts($plan));
    }

    public function test_running_the_same_file_again_adds_nothing(): void
    {
        $matching = TradeImportFields::clean(['company_name' => 0, 'email' => 1, 'phone' => 2], 3);
        $rows = [['Acme', 'a@example.com', '01632 960000'], ['Other Co', 'a@example.com', '01632 960009'], ['No Email', '', '']];

        $first = TradeImportPlan::build($rows, $matching, 'active', static fn() => null);
        $this->assertSame(3, TradeImportPlan::counts($first)['new']);

        $stored = [];
        foreach ($first as $entry) $stored[$entry['data']['email']] = ['company_name' => $entry['data']['company_name'], 'phone' => $entry['data']['phone']];

        $second = TradeImportPlan::build($rows, $matching, 'active', static fn(string $email) => $stored[$email] ?? null);
        $this->assertSame(['exists', 'exists', 'exists'], \array_column($second, 'result'));
    }

    public function test_a_customer_with_no_phone_is_not_duplicated_by_a_second_run(): void
    {
        $matching = TradeImportFields::clean(['company_name' => 0, 'email' => 1, 'phone' => 2], 3);
        $rows = [['NoPhone Ltd', 'b@example.com', ''], ['Other Co', 'b@example.com', '']];

        $first = TradeImportPlan::build($rows, $matching, 'active', static fn() => null);
        $this->assertSame(['new', 'new'], \array_column($first, 'result'), 'a different company on the same email is kept');

        $stored = [];
        foreach ($first as $entry) $stored[$entry['data']['email']] = ['company_name' => $entry['data']['company_name'], 'phone' => ''];

        $second = TradeImportPlan::build($rows, $matching, 'active', static fn(string $email) => $stored[$email] ?? null);
        $this->assertSame(['exists', 'exists'], \array_column($second, 'result'));
        $this->assertStringContainsString('company', $second[0]['reason']);
    }

    public function test_a_bad_or_taken_second_email_is_left_out_and_noted(): void
    {
        $matching = TradeImportFields::clean(['company_name' => 0, 'email' => 1, 'email2' => 2], 3);
        $taken = static fn(string $email) => $email === 'used@example.com' ? ['company_name' => 'Elsewhere', 'phone' => ''] : null;

        $plan = TradeImportPlan::build([['Acme', 'a@example.com', 'nope'], ['Beta', 'b@example.com', 'used@example.com']], $matching, 'pending', $taken);

        $this->assertNull($plan[0]['data']['email2']);
        $this->assertNull($plan[1]['data']['email2']);
        $this->assertSame(['new', 'new'], \array_column($plan, 'result'));
        $this->assertCount(1, $plan[0]['fixes']);
        $this->assertCount(1, $plan[1]['fixes']);
    }

    public function test_a_one_line_address_keeps_its_text_and_only_the_uk_postcode_moves(): void
    {
        $full = TradeAddress::split('Unit 4, Mill Lane, Industrial Estate, Bristol, Avon, bs1 5tr, England');
        $this->assertSame(['Unit 4, Mill Lane, Industrial Estate, Bristol, Avon, England', 'BS1 5TR'], [$full['address_line_1'], $full['postcode']]);

        $joined = TradeAddress::split('3 Loch Road Inverness IV1 1AA');
        $this->assertSame(['3 Loch Road Inverness', 'IV1 1AA'], [$joined['address_line_1'], $joined['postcode']]);

        $lines = TradeAddress::split("10 Space Road\nBath\nba11aa");
        $this->assertSame(['10 Space Road, Bath', 'BA1 1AA'], [$lines['address_line_1'], $lines['postcode']]);

        $none = TradeAddress::split('7 Broken Way, Luton');
        $this->assertSame(['7 Broken Way, Luton', ''], [$none['address_line_1'], $none['postcode']]);

        $german = TradeAddress::split('9 Hauptstrasse, Berlin, 10115, germany');
        $this->assertSame(['9 Hauptstrasse, Berlin, 10115', '', 'Germany'], [$german['address_line_1'], $german['postcode'], $german['country']], 'a non-UK country moves to the country field');

        $us = TradeAddress::split('1 Main St, Springfield, United States');
        $this->assertSame('United States', $us['country']);

        $this->assertSame('', $none['country'], 'a town is never read as a country');
        $this->assertSame('', $full['country'], 'UK names are left in the address, country stays unset');
        $this->assertSame('', TradeAddress::split('Germany')['country'], 'a lone word is the address');

        $middle = TradeAddress::split('Flat 1AB 2CD Tower, Leeds, Somewhere');
        $this->assertSame('', $middle['postcode'], 'postcode-shaped text that is not at the end is left alone');

        $this->assertSame(['address_line_1' => '', 'postcode' => '', 'country' => ''], TradeAddress::split('  '));
    }

    public function test_a_one_line_foreign_address_sets_the_country_so_it_is_not_marked_uk(): void
    {
        $matching = TradeImportFields::clean(['company_name' => 0, 'email' => 1, 'full_address' => 2], 3);

        $plan = TradeImportPlan::build([['Acme GmbH', 'a@example.de', '9 Hauptstrasse, Berlin, 10115, Germany']], $matching, 'active', static fn() => null);

        $this->assertSame('Germany', $plan[0]['data']['country']);
        $this->assertSame('9 Hauptstrasse, Berlin, 10115', $plan[0]['data']['address_line_1']);

        $withColumn = TradeImportFields::clean(['company_name' => 0, 'email' => 1, 'full_address' => 2, 'country' => 3], 4);
        $plan = TradeImportPlan::build([['Acme', 'a@example.com', '9 Hauptstrasse, Berlin, Germany', 'France']], $withColumn, 'active', static fn() => null);
        $this->assertSame('France', $plan[0]['data']['country'], 'a separate Country column wins');
    }

    public function test_values_too_long_for_their_field_are_cut_and_kept_in_the_notes_not_rejected(): void
    {
        $matching = TradeImportFields::clean(['company_name' => 0, 'email' => 1, 'phone' => 2], 3);
        $company = \str_repeat('Long Name ', 40);

        $plan = TradeImportPlan::build([[$company, 'a@example.com', \str_repeat('1', 80)]], $matching, 'active', static fn() => null);

        $this->assertSame('new', $plan[0]['result']);
        $this->assertLessThanOrEqual(255, \mb_strlen($plan[0]['data']['company_name']));
        $this->assertLessThanOrEqual(50, \mb_strlen($plan[0]['data']['phone']));
        $this->assertStringContainsString(\trim($company), $plan[0]['data']['notes'], 'the full text is kept');
        $this->assertCount(2, $plan[0]['fixes']);

        $longEmail = TradeImportPlan::build([['Acme', \str_repeat('a', 250) . '@example.com', '']], $matching, 'active', static fn() => null);
        $this->assertSame('new', $longEmail[0]['result']);
        $this->assertStringEndsWith('@placeholder.invalid', $longEmail[0]['data']['email']);
    }

    public function test_full_address_column_fills_only_what_has_no_column_of_its_own(): void
    {
        $matching = TradeImportFields::clean(['company_name' => 0, 'email' => 1, 'full_address' => 2, 'postcode' => 3], 4);
        $plan = TradeImportPlan::build([['Acme', 'a@example.com', '1 High St, Leeds, LS1 4AP', 'M1 1AA']], $matching, 'active', static fn() => null);

        $this->assertSame('1 High St, Leeds', $plan[0]['data']['address_line_1']);
        $this->assertSame('M1 1AA', $plan[0]['data']['postcode'], 'a separate column wins');
        $this->assertSame([], $plan[0]['fixes']);
        $this->assertNull($plan[0]['data']['city']);
    }

    public function test_an_address_too_long_for_the_field_moves_its_overflow_to_the_notes(): void
    {
        $matching = TradeImportFields::clean(['company_name' => 0, 'email' => 1, 'full_address' => 2], 3);
        $long = \implode(', ', \array_fill(0, 40, 'Some Long Road Name'));

        $plan = TradeImportPlan::build([['Acme', 'a@example.com', $long . ', LS1 4AP']], $matching, 'active', static fn() => null);

        $this->assertSame('new', $plan[0]['result']);
        $this->assertLessThanOrEqual(255, \mb_strlen($plan[0]['data']['address_line_1']));
        $this->assertStringContainsString('Address continued: ', $plan[0]['data']['notes']);
        $this->assertCount(1, $plan[0]['fixes']);
        $this->assertSame('LS1 4AP', $plan[0]['data']['postcode']);
    }

    public function test_a_lone_address_column_is_the_whole_address_but_beside_town_and_postcode_it_is_line_one(): void
    {
        $alone = TradeImportFields::guess(['Company', 'Email', 'Address']);
        $this->assertSame(2, $alone['full_address']);
        $this->assertNull($alone['address_line_1']);

        $beside = TradeImportFields::guess(['Company', 'Email', 'Address', 'Town', 'ZIP']);
        $this->assertNull($beside['full_address']);
        $this->assertSame(2, $beside['address_line_1']);
    }

    public function test_stored_upload_is_found_by_its_token_only_and_deleted(): void
    {
        $source = $this->dir . '/src.csv';
        \file_put_contents($source, "a\n");

        $token = TradeImportFile::store($source, 'csv', $this->dir . '/store');
        $this->assertNotNull(TradeImportFile::find($token, $this->dir . '/store'));
        $this->assertNull(TradeImportFile::find('../../etc/passwd', $this->dir . '/store'));

        TradeImportFile::delete($token, $this->dir . '/store');
        $this->assertNull(TradeImportFile::find($token, $this->dir . '/store'));
        \rmdir($this->dir . '/store');
    }

    /** @param array<int, string> $rows sheet XML rows; @param array<int, string> $strings shared string items */
    private function xlsx(array $rows, array $strings): string
    {
        $path = $this->dir . '/a.xlsx';
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/worksheets/sheet1.xml', "<worksheet {$ns}><sheetData>" . \implode('', $rows) . '</sheetData></worksheet>');
        $items = \array_map(static fn(string $s) => \str_starts_with($s, '<') ? "<si>{$s}</si>" : "<si><t>{$s}</t></si>", $strings);
        $zip->addFromString('xl/sharedStrings.xml', "<sst {$ns}>" . \implode('', $items) . '</sst>');
        $zip->close();

        return $path;
    }
}
