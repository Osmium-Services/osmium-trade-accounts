<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\TradeAccounts\Models\TradeCustomer;
use Osmium\Services\TradeAccounts\Models\TradeCustomerInput;
use Osmium\Services\TradeAccounts\Models\TradeStatus;

/**
 * Trade customers admin.
 *
 * Routes:
 *   - index()  → /admin/trade/customers/
 *   - action() → /admin/trade/customers/action/   (AJAX CRUD and status changes)
 */
class TradeCustomersController extends AdminController
{
    private const RECORD_TYPE = 'trade_customer';
    private const STATUS_ACTIONS = ['activate' => TradeStatus::ACTIVE, 'suspend' => TradeStatus::SUSPENDED, 'close' => TradeStatus::CLOSED];
    private const STATUS_VERBS = ['activate' => 'Activated', 'suspend' => 'Suspended', 'close' => 'Closed'];

    public function index(): void
    {
        $model = new TradeCustomer($this->osmium->dataSource);
        $active = $model->listByStatus([TradeStatus::PENDING, TradeStatus::ACTIVE]);
        $inactive = $model->listByStatus([TradeStatus::SUSPENDED, TradeStatus::CLOSED]);

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/trade-customers.php';
        $this->data['admin']['customers'] = \array_map($this->formatCustomer(...), $active);
        $this->data['admin']['inactiveCustomers'] = \array_map($this->formatCustomer(...), $inactive);
        $this->data['admin']['recentCustomers'] = \array_map($this->formatRecent(...), $model->listRecent());
        $this->data['admin']['deletedCustomers'] = \array_map($this->formatDeleted(...), $model->listDeleted());

        $this->setView('trade-customers/index.phtml');
    }

    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = (string) ($input['action'] ?? '');
        $id = (int) ($input['id'] ?? 0);

        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) $this->admin->jsonError('Invalid request token. Please refresh and try again.');

        $needsId = !\in_array($action, ['create', 'purge_deleted'], true);
        $missingParams = $action === '' || ($needsId && !$id);
        if ($missingParams) $this->admin->jsonError('Missing parameters');

        try {
            $this->admin->jsonSuccess($this->run(action: $action, id: $id, input: $input));
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function run(string $action, int $id, array $input): array
    {
        $isStatusChange = isset(self::STATUS_ACTIONS[$action]);
        if ($isStatusChange) return $this->changeStatus(id: $id, action: $action);

        return match ($action) {
            'create' => $this->create($input),
            'update' => $this->update(id: $id, input: $input),
            'delete' => $this->delete($id),
            'restore' => $this->restore($id),
            'permanently_delete' => $this->permanentlyDelete($id),
            'purge_deleted' => $this->purgeDeleted(),
            default => throw new \InvalidArgumentException('Unknown action'),
        };
    }

    private function create(array $input): array
    {
        $data = $this->details($input);
        $this->refuseDuplicateEmail(data: $data, excludeId: 0);

        $id = (new TradeCustomer($this->osmium->dataSource))->create($data);
        $this->logChange(action: 'Created', id: $id, name: $data['company_name']);

        return ['success' => true, 'id' => $id];
    }

    private function update(int $id, array $input): array
    {
        $model = new TradeCustomer($this->osmium->dataSource);
        $existing = $model->getById($id);
        if (!$existing) throw new \InvalidArgumentException('Customer not found');

        $data = $this->details($input);
        $this->refuseDuplicateEmail(data: $data, excludeId: $id);

        $model->update(id: $id, data: $data);
        $this->logChange(action: 'Updated', id: $id, name: $data['company_name']);

        return ['success' => true, 'id' => $id];
    }

    private function changeStatus(int $id, string $action): array
    {
        $model = new TradeCustomer($this->osmium->dataSource);
        $customer = $model->getById($id);
        if (!$customer) throw new \InvalidArgumentException('Customer not found');

        $model->setStatus(id: $id, status: self::STATUS_ACTIONS[$action]);
        $this->logChange(action: self::STATUS_VERBS[$action], id: $id, name: $customer['company_name']);

        return ['success' => true, 'id' => $id];
    }

    private function delete(int $id): array
    {
        $model = new TradeCustomer($this->osmium->dataSource);
        $customer = $model->getById($id);
        if (!$customer) throw new \InvalidArgumentException('Customer not found');

        $model->softDelete($id);
        $this->logChange(action: 'Deleted', id: $id, name: $customer['company_name']);

        return ['success' => true, 'id' => $id];
    }

    private function restore(int $id): array
    {
        $model = new TradeCustomer($this->osmium->dataSource);
        $customer = $model->getAnyById($id);
        if (!$customer) throw new \InvalidArgumentException('Customer not found');

        $emailTaken = $model->emailInUse(email: $customer['email'], excludeId: $id);
        if ($emailTaken) throw new \InvalidArgumentException('Another customer now uses this email, so this one cannot be restored');

        $model->restore($id);
        $this->logChange(action: 'Restored', id: $id, name: $customer['company_name']);

        return ['success' => true, 'id' => $id];
    }

    private function permanentlyDelete(int $id): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $model = new TradeCustomer($this->osmium->dataSource);
        $customer = $model->getAnyById($id);
        if (!$customer) throw new \InvalidArgumentException('Customer not found');

        $this->logChange(action: 'Permanently deleted', id: $id, name: $customer['company_name']);
        $model->hardDelete($id);

        return ['success' => true, 'id' => $id];
    }

    private function purgeDeleted(): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $count = (new TradeCustomer($this->osmium->dataSource))->purgeDeleted();
        $this->admin->model->changelog->log(description: "Purged {$count} deleted customer(s)", recordType: self::RECORD_TYPE, recordId: 0);

        return ['success' => true, 'count' => $count];
    }

    /** @return array<string, mixed> The submitted details, cleaned; throws with the first problem if they are not usable */
    private function details(array $input): array
    {
        $data = TradeCustomerInput::parse($input);
        $problem = TradeCustomerInput::validate($data);
        if ($problem !== null) throw new \InvalidArgumentException($problem);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function refuseDuplicateEmail(array $data, int $excludeId): void
    {
        $model = new TradeCustomer($this->osmium->dataSource);
        $emails = \array_filter([$data['email'], $data['email2']]);

        foreach ($emails as $email) {
            $taken = $model->emailInUse(email: $email, excludeId: $excludeId);
            if ($taken) throw new \InvalidArgumentException("A customer with the email {$email} already exists");
        }
    }

    /** @return array<string, mixed> */
    private function formatCustomer(array $row): array
    {
        $status = (string) $row['status'];

        return [
            'id' => (int) $row['id'],
            'status' => $status,
            'statusLabel' => TradeStatus::label($status),
            'statusClass' => 'bg-label-' . $this->statusColour($status),
            'email' => (string) $row['email'],
            'email2' => (string) ($row['email2'] ?? ''),
            'company_name' => (string) $row['company_name'],
            'contact_name' => (string) ($row['contact_name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'address_line_1' => (string) ($row['address_line_1'] ?? ''),
            'address_line_2' => (string) ($row['address_line_2'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'county' => (string) ($row['county'] ?? ''),
            'postcode' => (string) ($row['postcode'] ?? ''),
            'country' => (string) $row['country'],
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    }

    private function statusColour(string $status): string
    {
        return match ($status) {
            TradeStatus::ACTIVE => 'success',
            TradeStatus::PENDING => 'warning',
            TradeStatus::SUSPENDED => 'danger',
            default => 'secondary',
        };
    }

    /** @return array<string, mixed> */
    private function formatRecent(array $row): array
    {
        $changed = \max((string) ($row['updated_at'] ?? ''), (string) ($row['deleted_at'] ?? ''), (string) $row['created_at']);

        return [
            'id' => (int) $row['id'],
            'deleted_at' => $row['deleted_at'] ?? null,
            'company_name' => (string) $row['company_name'],
            'updatedDate' => \date('j M Y, g:ia', \strtotime($changed)),
            'changed_by' => $row['changed_by'] ?? null,
            'changed_by_avatar' => $row['changed_by_avatar'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function formatDeleted(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'company_name' => (string) $row['company_name'],
            'deletedDate' => \date('d M Y', \strtotime((string) $row['deleted_at'])),
            'changed_by' => $row['changed_by'] ?? null,
            'changed_by_avatar' => $row['changed_by_avatar'] ?? null,
        ];
    }

    private function logChange(string $action, int $id, string $name): void
    {
        $this->admin->model->changelog->log(
            description: $action . ': ' . $name,
            recordType: self::RECORD_TYPE,
            recordId: $id,
        );
    }
}
