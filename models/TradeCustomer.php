<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * Trade customers. A customer is soft-deleted (deleted_at) and only the bin
 * removes one for good; a customer who is inactive (suspended or closed) is
 * still a live row.
 */
class TradeCustomer extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    private const DETAIL_FIELDS = [
        'email', 'email2', 'company_name', 'contact_name', 'phone', 'address_line_1', 'address_line_2',
        'city', 'county', 'postcode', 'country', 'notes',
    ];

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'trade_customers');
    }

    /** @return array<string, mixed>|null */
    public function getById(int $id): ?array
    {
        return $this->one(file: 'customer-get-by-id.sql', bindings: [':id' => $id]);
    }

    /** Like getById() but also finds a deleted customer, for restore */
    public function getAnyById(int $id): ?array
    {
        return $this->one(file: 'customer-get-any.sql', bindings: [':id' => $id]);
    }

    /** By primary or secondary email, case-insensitively (the column collation). */
    public function getByEmail(string $email): ?array
    {
        return $this->one(file: 'customer-get-by-email.sql', bindings: [':email' => \strtolower(\trim($email))]);
    }

    /** The customer a source created, by that source's own id for them. */
    public function getBySource(string $source, string $sourceRef): ?array
    {
        return $this->one(file: 'customer-get-by-source.sql', bindings: [':source' => $source, ':source_ref' => $sourceRef]);
    }

    public function emailInUse(string $email, int $excludeId = 0): bool
    {
        $bindings = [':email' => \strtolower(\trim($email)), ':exclude_id' => $excludeId];

        return $this->one(file: 'customer-email-exists.sql', bindings: $bindings) !== null;
    }

    /**
     * @param string[] $statuses
     * @return array<int, array<string, mixed>> live customers in those statuses, by company name
     */
    public function listByStatus(array $statuses): array
    {
        $valid = \array_values(\array_filter($statuses, TradeStatus::isValid(...)));
        $none = $valid === [];
        if ($none) return [];

        $sql = $this->loadSqlFile('customer-list-by-status.sql');

        return $this->database->prepare(sql: $sql, bindings: [':statuses' => \implode(',', $valid)])->resultset();
    }

    /**
     * @param array<string, mixed> $data As TradeCustomerInput::parse() gives it
     * @return int the new customer's id
     */
    public function create(array $data, ?int $createdBy = null): int
    {
        $bindings = [];
        foreach (self::DETAIL_FIELDS as $field) {
            $bindings[':' . $field] = $data[$field] ?? null;
        }
        $bindings[':status'] = $data['status'] ?? TradeStatus::PENDING;
        $bindings[':source'] = $data['source'] ?? null;
        $bindings[':source_ref'] = $data['source_ref'] ?? null;
        $bindings[':created_by'] = $createdBy;

        $this->database->prepare(sql: $this->loadSqlFile('customer-insert.sql'), bindings: $bindings)->execute();

        return (int) $this->database->lastInsertId();
    }

    /** @param array<string, mixed> $data Details only; status and source are not changed here */
    public function update(int $id, array $data): void
    {
        $bindings = [':id' => $id];
        foreach (self::DETAIL_FIELDS as $field) {
            $bindings[':' . $field] = $data[$field] ?? null;
        }

        $this->database->prepare(sql: $this->loadSqlFile('customer-update.sql'), bindings: $bindings)->execute();
    }

    public function setStatus(int $id, string $status): void
    {
        $invalid = !TradeStatus::isValid($status);
        if ($invalid) throw new \InvalidArgumentException("Unknown trade status '{$status}'");

        $this->database->prepare(sql: $this->loadSqlFile('customer-set-status.sql'), bindings: [':id' => $id, ':status' => $status])->execute();
    }

    public function softDelete(int $id): void
    {
        $this->database->prepare(sql: $this->loadSqlFile('customer-soft-delete.sql'), bindings: [':id' => $id])->execute();
    }

    public function restore(int $id): void
    {
        $this->database->prepare(sql: $this->loadSqlFile('customer-restore.sql'), bindings: [':id' => $id])->execute();
    }

    /** Removes a customer for good, but only one already in the bin. */
    public function hardDelete(int $id): void
    {
        $this->database->prepare(sql: $this->loadSqlFile('customer-hard-delete.sql'), bindings: [':id' => $id])->execute();
    }

    /** @return int How many customers were removed from the bin */
    public function purgeDeleted(): int
    {
        $statement = $this->database->prepare(sql: $this->loadSqlFile('customer-purge-deleted.sql'), bindings: []);
        $statement->execute();

        return $statement->rowCount();
    }

    /** @return array<int, array<string, mixed>> Created, changed or deleted in the last 24 hours, newest first, capped at 10 */
    public function listRecent(): array
    {
        return $this->database->prepare(sql: $this->loadSqlFile('customer-list-recent.sql'), bindings: [])->resultset();
    }

    /** @return array<int, array<string, mixed>> The bin, most recently deleted first */
    public function listDeleted(): array
    {
        return $this->database->prepare(sql: $this->loadSqlFile('customer-list-deleted.sql'), bindings: [])->resultset();
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    private function one(string $file, array $bindings): ?array
    {
        $row = $this->database->prepare(sql: $this->loadSqlFile($file), bindings: $bindings)->single();

        return $row ?: null;
    }
}
