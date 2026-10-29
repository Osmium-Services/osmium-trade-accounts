<?php

declare(strict_types=1);

namespace Tests\Services\TradeAccounts;

use Osmium\Services\TradeAccounts\Models\TradeLacrmAdapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/TradeLacrmMapper.php';
require_once __DIR__ . '/../models/TradeLacrmAdapter.php';

final class TradeLacrmAdapterTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $announced = [];
    /** @var array<int, array{0: string, 1: array}> */
    private array $asked = [];
    /** @var string[] */
    private array $logged = [];

    /**
     * @param array<string, mixed> $answers question => what the fake LACRM answers (a Throwable is thrown)
     * @param array<string, array> $customers LACRM contact id => the live customer
     */
    private function adapter(string $selected = 'lacrm', array $answers = [], array $customers = []): TradeLacrmAdapter
    {
        $this->announced = [];
        $this->asked = [];
        $this->logged = [];

        return new TradeLacrmAdapter(
            selectedSource: $selected,
            ask: function (string $question, array $payload) use ($answers): mixed {
                $this->asked[] = [$question, $payload];
                $answer = $answers[$question] ?? null;
                if ($answer instanceof \Throwable) throw $answer;

                return $answer;
            },
            announce: function (string $event, array $payload): void {
                $this->announced[] = [$event, $payload];
            },
            findByRef: static fn(string $ref): ?array => $customers[$ref] ?? null,
            log: function (string $message): void {
                $this->logged[] = $message;
            },
        );
    }

    public function test_nothing_happens_unless_lacrm_is_the_selected_source(): void
    {
        foreach (['', 'hubspot'] as $selected) {
            $adapter = $this->adapter(selected: $selected, answers: ['lacrm.contact.get' => ['ContactId' => '1']], customers: ['1' => ['id' => 1]]);
            $adapter->formSubmitted(['fields' => [['Label' => 'Email', 'Value' => 'a@b.test']]]);
            $adapter->saleWon(['contactId' => '1']);
            $adapter->saleLost(['contactId' => '1']);
            $adapter->contactUpdated(['contacts' => [['ContactId' => '1', 'Phone' => [['Text' => '1']]]]]);

            $this->assertSame([], $this->announced, "selected '{$selected}'");
            $this->assertSame([], $this->asked, "selected '{$selected}' must not ask LACRM anything");
        }
    }

    public function test_a_form_submission_announces_an_application(): void
    {
        $this->adapter()->formSubmitted(['fields' => [['Label' => 'Email', 'Value' => 'a@b.test'], ['Label' => 'Company Name', 'Value' => 'Firm']]]);

        $this->assertCount(1, $this->announced);
        [$event, $payload] = $this->announced[0];
        $this->assertSame('trade.application.received', $event);
        $this->assertSame('a@b.test', $payload['customer']['email']);
        $this->assertSame('lacrm', $payload['customer']['source']);
    }

    public function test_a_form_with_no_fields_announces_nothing(): void
    {
        $adapter = $this->adapter();
        $adapter->formSubmitted(['fields' => []]);
        $adapter->formSubmitted([]);

        $this->assertSame([], $this->announced);
    }

    public function test_a_won_sale_looks_up_the_contact_and_announces_an_approval(): void
    {
        $contact = ['Company Name' => 'Acme', 'Email' => [['Text' => 'a@acme.test']]];
        $adapter = $this->adapter(answers: ['lacrm.contact.get' => $contact, 'lacrm.contact.linked_people' => [['Name' => ['FirstName' => 'Sam', 'LastName' => 'Lee']]]]);

        $adapter->saleWon(['contactId' => '55']);

        $this->assertSame(['lacrm.contact.get', 'lacrm.contact.linked_people'], \array_column($this->asked, 0));
        $this->assertSame(['companyId' => '55', 'companyName' => 'Acme'], $this->asked[1][1]);
        [$event, $payload] = $this->announced[0];
        $this->assertSame('trade.customer.approved', $event);
        $this->assertSame('55', $payload['customer']['source_ref']); // the id the sale named, whatever the contact says
        $this->assertSame('Sam Lee', $payload['customer']['contact_name']);
        $this->assertSame('Acme', $payload['customer']['company_name']);
    }

    public function test_a_person_contact_does_not_ask_for_linked_people(): void
    {
        $this->adapter(answers: ['lacrm.contact.get' => ['Name' => ['FirstName' => 'A', 'LastName' => 'B'], 'Email' => [['Text' => 'a@b.test']]]])->saleWon(['contactId' => '5']);

        $this->assertSame(['lacrm.contact.get'], \array_column($this->asked, 0));
        $this->assertCount(1, $this->announced);
    }

    public function test_a_won_sale_when_lacrm_does_not_answer_announces_nothing(): void
    {
        $this->adapter()->saleWon(['contactId' => '55']);

        $this->assertSame([], $this->announced);
    }

    public function test_a_failing_lookup_is_logged_and_announces_nothing(): void
    {
        $adapter = $this->adapter(answers: ['lacrm.contact.get' => new \RuntimeException('API down')]);

        $adapter->saleWon(['contactId' => '55']);

        $this->assertSame([], $this->announced);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('API down', $this->logged[0]);
    }

    public function test_a_failing_linked_people_lookup_still_approves_without_a_contact_name(): void
    {
        $adapter = $this->adapter(answers: ['lacrm.contact.get' => ['Company Name' => 'Acme'], 'lacrm.contact.linked_people' => new \RuntimeException('x')]);

        $adapter->saleWon(['contactId' => '9']);

        $this->assertSame('trade.customer.approved', $this->announced[0][0]);
        $this->assertSame('', $this->announced[0][1]['customer']['contact_name']);
    }

    public function test_a_lost_sale_announces_a_suspension_by_contact_id_without_asking_lacrm(): void
    {
        $this->adapter()->saleLost(['contactId' => '77']);

        $this->assertSame([['trade.customer.suspended', ['customer' => ['source' => 'lacrm', 'source_ref' => '77']]]], $this->announced);
        $this->assertSame([], $this->asked);
    }

    public function test_a_sale_with_no_contact_id_does_nothing(): void
    {
        $adapter = $this->adapter(answers: ['lacrm.contact.get' => ['ContactId' => '1']]);
        $adapter->saleWon(['contactId' => '']);
        $adapter->saleLost([]);

        $this->assertSame([], $this->announced);
    }

    public function test_a_contact_update_for_a_customer_announces_the_complete_updated_customer(): void
    {
        $existing = ['id' => 3, 'email' => 'old@x.test', 'company_name' => 'Old', 'phone' => '1', 'source_ref' => '12'];
        $adapter = $this->adapter(customers: ['12' => $existing]);

        $adapter->contactUpdated(['contacts' => [['ContactId' => '12', 'Company Name' => 'New', 'Phone' => [['Text' => '2']]]]]);

        [$event, $payload] = $this->announced[0];
        $this->assertSame('trade.customer.updated', $event);
        $this->assertSame('New', $payload['customer']['company_name']);
        $this->assertSame('2', $payload['customer']['phone']);
        $this->assertSame('old@x.test', $payload['customer']['email']); // untouched details kept
        $this->assertSame('12', $payload['customer']['source_ref']);
        $this->assertSame([], $this->asked); // no API call: the webhook already says what changed
    }

    public function test_contact_updates_for_non_customers_empty_changes_and_junk_are_skipped(): void
    {
        $adapter = $this->adapter(customers: ['12' => ['id' => 3, 'source_ref' => '12']]);

        $adapter->contactUpdated(['contacts' => [
            ['ContactId' => '999', 'Phone' => [['Text' => '2']]], // not one of ours
            ['ContactId' => '12'], // nothing changed that we track
            ['Phone' => [['Text' => '2']]], // no id
            'junk',
        ]]);

        $this->assertSame([], $this->announced);
    }

    public function test_several_updated_contacts_announce_one_event_each(): void
    {
        $adapter = $this->adapter(customers: ['1' => ['id' => 1, 'source_ref' => '1'], '2' => ['id' => 2, 'source_ref' => '2']]);

        $adapter->contactUpdated(['contacts' => [['ContactId' => '1', 'Phone' => [['Text' => 'a']]], ['ContactId' => '2', 'Phone' => [['Text' => 'b']]]]]);

        $this->assertCount(2, $this->announced);
    }
}
