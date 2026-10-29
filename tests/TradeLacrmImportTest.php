<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeLacrmImport;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeStatus.php';
require_once __DIR__ . '/../models/TradeCustomerInput.php';
require_once __DIR__ . '/../models/TradeLacrmMapper.php';
require_once __DIR__ . '/../models/TradeLacrmImport.php';

final class TradeLacrmImportTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $announced = [];
    /** @var array<int, string> */
    private array $asked = [];
    /** @var array<string, array> LACRM contact id => customer, as the fake database holds them */
    private array $customers = [];

    /** @param array<string, mixed> $answers question => answer (a Throwable is thrown) */
    private function import(array $answers, array $customers = [], bool $saves = true, array $emailOwners = []): TradeLacrmImport
    {
        $this->announced = [];
        $this->asked = [];
        $this->customers = $customers;

        return new TradeLacrmImport(
            ask: function (string $question, array $payload) use ($answers): mixed {
                $this->asked[] = $question;
                $answer = $answers[$question] ?? null;
                if ($answer instanceof \Throwable) throw $answer;

                return $answer;
            },
            announce: function (string $event, array $payload) use ($saves): void {
                $this->announced[] = [$event, $payload];
                if ($saves) $this->customers[$payload['customer']['source_ref']] = $payload['customer'];
            },
            findByRef: fn(string $ref): ?array => $this->customers[$ref] ?? null,
            emailOwner: static fn(string $email): ?string => $emailOwners[$email] ?? null,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function won(string ...$ids): array
    {
        return \array_map(static fn(string $id): array => ['ContactId' => $id, 'ContactMetaData' => ['Name' => "Name {$id}"]], $ids);
    }

    private function company(string $name, string $email = 'x@y.test'): array
    {
        return ['Company Name' => $name, 'Email' => [['Text' => $email]]];
    }

    public function test_preview_lists_new_contacts_once_each_and_counts_existing_customers(): void
    {
        $import = $this->import(['lacrm.sales.won' => [...$this->won('1', '2', '2', '3'), ['ContactId' => '']]], ['3' => ['id' => 9]]);

        $this->assertSame(['new' => [['id' => '1', 'name' => 'Name 1'], ['id' => '2', 'name' => 'Name 2']], 'existing' => 1], $import->preview());
    }

    public function test_preview_is_null_when_lacrm_does_not_answer(): void
    {
        $this->assertNull($this->import([])->preview());
        $this->assertNull($this->import([])->run());
    }

    public function test_run_imports_new_contacts_as_approved_customers_and_leaves_existing_alone(): void
    {
        $import = $this->import([
            'lacrm.sales.won' => $this->won('1', '2'),
            'lacrm.contacts.get_many' => ['results' => ['2' => $this->company('Two Ltd', 'two@y.test')], 'errors' => []],
            'lacrm.contact.linked_people' => [['Name' => ['FirstName' => 'Sam', 'LastName' => 'Lee']]],
        ], ['1' => ['id' => 5]]);

        $result = $import->run();

        $this->assertSame(['imported' => 1, 'existing' => 1, 'skipped' => []], $result);
        $this->assertSame('trade.customer.approved', $this->announced[0][0]);
        $this->assertSame('Two Ltd', $this->announced[0][1]['customer']['company_name']);
        $this->assertSame('2', $this->announced[0][1]['customer']['source_ref']);
        $this->assertSame('Sam Lee', $this->announced[0][1]['customer']['contact_name']);
    }

    public function test_run_asks_for_only_the_new_contacts(): void
    {
        $answers = ['lacrm.sales.won' => $this->won('1', '2'), 'lacrm.contacts.get_many' => ['results' => [], 'errors' => []]];
        $asked = null;
        $import = new TradeLacrmImport(
            ask: function (string $question, array $payload) use ($answers, &$asked): mixed {
                if ($question === 'lacrm.contacts.get_many') $asked = $payload['contactIds'];

                return $answers[$question] ?? null;
            },
            announce: static fn(string $event, array $told) => null,
            findByRef: static fn(string $ref): ?array => $ref === '1' ? ['id' => 1] : null,
            emailOwner: static fn(string $email): ?string => null,
        );

        $import->run();

        $this->assertSame(['2'], $asked);
    }

    public function test_a_contact_that_could_not_be_fetched_is_skipped_with_the_reason(): void
    {
        $result = $this->import(['lacrm.sales.won' => $this->won('7'), 'lacrm.contacts.get_many' => ['results' => [], 'errors' => ['7' => 'rate limited']]])->run();

        $this->assertSame(0, $result['imported']);
        $this->assertSame(['Name 7: could not be fetched from LACRM (rate limited)'], $result['skipped']);
        $this->assertSame([], $this->announced);
    }

    public function test_a_contact_without_a_usable_email_is_skipped_with_the_reason(): void
    {
        $result = $this->import(['lacrm.sales.won' => $this->won('7'), 'lacrm.contacts.get_many' => ['results' => ['7' => ['Company Name' => 'No Email Ltd']], 'errors' => []]])->run();

        $this->assertSame(['Name 7: Primary email is required'], $result['skipped']);
        $this->assertSame([], $this->announced);
    }

    public function test_a_failed_linked_people_lookup_skips_that_contact_but_not_the_rest(): void
    {
        $import = $this->import([
            'lacrm.sales.won' => $this->won('1', '2'),
            'lacrm.contacts.get_many' => ['results' => ['1' => $this->company('One Ltd', 'one@y.test'), '2' => ['Name' => ['FirstName' => 'P', 'LastName' => 'Q'], 'Email' => [['Text' => 'two@y.test']], 'Company Name' => 'Two']], 'errors' => []],
            'lacrm.contact.linked_people' => new \RuntimeException('API down'),
        ]);

        $result = $import->run();

        $this->assertSame(1, $result['imported']);
        $this->assertSame(['Name 1: API down'], $result['skipped']);
    }

    public function test_a_contact_the_event_did_not_save_is_reported_not_counted(): void
    {
        $result = $this->import(['lacrm.sales.won' => $this->won('1'), 'lacrm.contacts.get_many' => ['results' => ['1' => $this->company('One Ltd')], 'errors' => []]], saves: false)->run();

        $this->assertSame(0, $result['imported']);
        $this->assertSame(['Name 1: was not saved'], $result['skipped']);
    }

    public function test_a_lacrm_error_listing_the_won_sales_reaches_the_caller(): void
    {
        $this->expectExceptionMessage('bad key');

        $this->import(['lacrm.sales.won' => new \RuntimeException('bad key')])->preview();
    }

    public function test_a_contact_whose_email_belongs_to_another_contacts_customer_is_skipped_with_the_owner_named(): void
    {
        $result = $this->import(
            ['lacrm.sales.won' => $this->won('1'), 'lacrm.contacts.get_many' => ['results' => ['1' => $this->company('One Ltd', 'Shared@Y.test')], 'errors' => []]],
            emailOwners: ['shared@y.test' => 'AG Campers'],
        )->run();

        $this->assertSame(0, $result['imported']);
        $this->assertSame(['Name 1: its email is already used by AG Campers, and an email can belong to only one customer'], $result['skipped']);
        $this->assertSame([], $this->announced);
    }
}
