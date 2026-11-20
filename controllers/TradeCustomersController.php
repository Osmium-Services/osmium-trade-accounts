<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Controllers;

use Osmium\Core\Library\ImageUpload;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Services\TradeAccounts\Models\TradeAuthFactory;
use Osmium\Services\TradeAccounts\Models\TradeCustomer;
use Osmium\Services\TradeAccounts\Models\TradeConfig;
use Osmium\Services\TradeAccounts\Models\TradeCustomerExtras;
use Osmium\Services\TradeAccounts\Models\TradeCustomerInput;
use Osmium\Services\TradeAccounts\Models\TradeLogo;
use Osmium\Services\TradeAccounts\Models\TradeSources;
use Osmium\Services\TradeAccounts\Models\TradeStatus;
use Osmium\Services\TradeAccounts\Models\TradeStatusChange;

/**
 * Trade customers admin.
 *
 * Routes:
 *   - index()  → /admin/trade/customers/
 *   - add()    → /admin/trade/customers/add/
 *   - edit()   → /admin/trade/customers/edit/?id=
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
        $this->data['admin']['importLink'] = $this->isAdmin() ? $this->importLink() : null;
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/trade-customers.php';
        $this->data['admin']['customers'] = \array_map($this->formatCustomer(...), $active);
        $this->data['admin']['inactiveCustomers'] = \array_map($this->formatCustomer(...), $inactive);
        $this->data['admin']['recentCustomers'] = \array_map($this->formatRecent(...), $model->listRecent());
        $this->data['admin']['deletedCustomers'] = \array_map($this->formatDeleted(...), $model->listDeleted());

        $this->setView('trade-customers/index.phtml');
    }

    public function add(): void
    {
        $this->osmium->clearStashedQuerystring();
        $this->prepareForm(customerId: 0, customer: null);
        $this->setView('trade-customers/add.phtml');
    }

    public function edit(): void
    {
        $customerId = (int) $this->osmium->getStashedParam(key: 'id', default: 0);
        $this->osmium->clearStashedQuerystring();

        $row = $customerId ? (new TradeCustomer($this->osmium->dataSource))->getById($customerId) : null;
        $notFound = !$row;
        if ($notFound) $this->redirect('trade/customers/');

        $this->prepareForm(customerId: $customerId, customer: $this->formatCustomer($row));
        $this->setView('trade-customers/edit.phtml');
    }

    /** @param array<string, mixed>|null $customer */
    private function prepareForm(int $customerId, ?array $customer): void
    {
        $extras = TradeCustomerExtras::load(db: $this->osmium->dataSource, customerIds: $customerId ? [$customerId] : []);

        $this->data['admin']['customerId'] = $customerId;
        $this->data['admin']['customer'] = $customer ?? $this->emptyCustomer();
        $this->data['admin']['extraFields'] = $extras['fields'];
        $this->data['admin']['extraValues'] = $extras['values'][$customerId] ?? [];
        $this->data['admin']['pageTitle'] = $customer ? \htmlspecialchars($customer['company_name']) : 'Add Customer';
    }

    /** @return array<string, mixed> */
    private function emptyCustomer(): array
    {
        return [
            'company_name' => '', 'contact_name' => '', 'email' => '', 'email2' => '', 'phone' => '',
            'address_line_1' => '', 'address_line_2' => '', 'city' => '', 'county' => '', 'postcode' => '',
            'country' => 'United Kingdom', 'notes' => '', 'hasLogo' => false, 'logoUrl' => '',
        ];
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
    /** @return array{label: string, path: string}|null the selected source's own import page, when it has one */
    private function importLink(): ?array
    {
        $selected = (string) TradeConfig::get()['source'];
        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));
        $answers = $hooks->collectAll('trade.sources', ['osmium' => $this->osmium, 'dataSource' => $this->osmium->dataSource]);

        return TradeSources::importLink(options: TradeSources::options(answers: $answers, saved: $selected), selected: $selected);
    }

    private function run(string $action, int $id, array $input): array
    {
        $isStatusChange = isset(self::STATUS_ACTIONS[$action]);
        if ($isStatusChange) return $this->changeStatus(id: $id, action: $action);

        return match ($action) {
            'create' => $this->create($input),
            'update' => $this->update(id: $id, input: $input),
            'delete' => $this->delete($id),
            'restore' => $this->restore($id),
            'login_as' => $this->loginAs($id),
            'permanently_delete' => $this->permanentlyDelete($id),
            'purge_deleted' => $this->purgeDeleted(),
            default => throw new \InvalidArgumentException('Unknown action'),
        };
    }

    private function create(array $input): array
    {
        $data = $this->details($input);
        $this->refuseDuplicateEmail(data: $data, excludeId: 0);
        $logo = $this->validateLogo();
        $extras = $this->extraValues(input: $input, customerId: 0);

        $model = new TradeCustomer($this->osmium->dataSource);
        $id = $model->create($data);
        TradeCustomerExtras::save(db: $this->osmium->dataSource, customerId: $id, values: $extras);

        $hasLogo = $logo !== null;
        if ($hasLogo) $model->setLogo(id: $id, logoFilename: $this->saveLogo($logo));

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

        $logo = $this->validateLogo();
        $extras = $this->extraValues(input: $input, customerId: $id);

        $model->update(id: $id, data: $data);
        TradeCustomerExtras::save(db: $this->osmium->dataSource, customerId: $id, values: $extras);
        $this->applyLogoChange(model: $model, existing: $existing, logo: $logo, remove: !empty($input['remove_logo']));
        $this->logChange(action: 'Updated', id: $id, name: $data['company_name']);

        return ['success' => true, 'id' => $id];
    }

    private function changeStatus(int $id, string $action): array
    {
        $model = new TradeCustomer($this->osmium->dataSource);
        $customer = $model->getById($id);
        if (!$customer) throw new \InvalidArgumentException('Customer not found');

        TradeStatusChange::apply(db: $this->osmium->dataSource, model: $model, customer: $customer, to: self::STATUS_ACTIONS[$action]);
        $this->logChange(action: self::STATUS_VERBS[$action], id: $id, name: $customer['company_name']);

        return ['success' => true, 'id' => $id];
    }

    /** An admin sees the customer's account exactly as they do; no link or code, and the customer is not told. */
    private function loginAs(int $id): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \InvalidArgumentException('Only an administrator can log in as a customer');

        $customer = (new TradeCustomer($this->osmium->dataSource))->getById($id);
        if (!$customer) throw new \InvalidArgumentException('Customer not found');

        $cannotLogIn = !TradeStatus::canLogin($customer['status']);
        if ($cannotLogIn) throw new \InvalidArgumentException('This customer is not active. Activate them first.');

        TradeAuthFactory::make($this->osmium)->loginAs(customer: $customer, newSessionId: false);
        $this->logChange(action: 'Logged in as', id: $id, name: $customer['company_name']);

        $slash = ($this->osmium->config->site->urlTrailingSlash ?? false) ? '/' : '';

        return ['success' => true, 'id' => $id, 'url' => '/trade' . $slash];
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
        $this->removeLogoFile($customer['logo_filename'] ?? null);
        $model->hardDelete($id);

        return ['success' => true, 'id' => $id];
    }

    private function purgeDeleted(): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $model = new TradeCustomer($this->osmium->dataSource);
        foreach ($model->listDeleted() as $customer) {
            $this->removeLogoFile($customer['logo_filename'] ?? null);
        }

        $count = $model->purgeDeleted();
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

    /**
     * The extra fields other services added to the form, checked by them before anything is saved.
     *
     * @return array<string, string>
     */
    private function extraValues(array $input, int $customerId): array
    {
        $db = $this->osmium->dataSource;
        $fields = TradeCustomerExtras::load(db: $db, customerIds: [])['fields'];
        $values = TradeCustomerExtras::submitted(input: $input, fields: $fields);

        $problems = TradeCustomerExtras::validate(db: $db, customerId: $customerId, values: $values);
        if ($problems !== []) throw new \InvalidArgumentException($problems[0]);

        return $values;
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
            'hasLogo' => !empty($row['logo_filename']),
            'logoUrl' => TradeLogo::url($row['logo_filename'] ?? null),
            'logoHtml' => $this->logoHtml(filename: $row['logo_filename'] ?? null, company: (string) $row['company_name']),
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

    /**
     * The uploaded logo checked and ready to save, or null when none was sent
     *
     * @return array<string, mixed>|null
     */
    private function validateLogo(): ?array
    {
        $file = $_FILES['logo'] ?? null;
        $noFileSent = !ImageUpload::wasUploaded($file);
        if ($noFileSent) return null;

        $maxUpload = OsmiumAdmin::getMaxUploadSize();
        $problem = $this->logoUpload($maxUpload['bytes'])->errorFor($file);
        if ($problem !== null) throw new \InvalidArgumentException($problem);

        return $file;
    }

    /** Saves the checked upload under a new random name and returns that name. */
    private function saveLogo(array $logo): string
    {
        $maxUpload = OsmiumAdmin::getMaxUploadSize();

        return $this->logoUpload($maxUpload['bytes'])->store($logo['tmp_name'], $_SERVER['DOCUMENT_ROOT'] . TradeLogo::DIR, \bin2hex(\random_bytes(8)));
    }

    private function logoUpload(int $maxBytes): ImageUpload
    {
        return new ImageUpload(TradeLogo::TYPES, $maxBytes);
    }

    /** @param array<string, mixed> $existing */
    private function applyLogoChange(TradeCustomer $model, array $existing, ?array $logo, bool $remove): void
    {
        $hasNewLogo = $logo !== null;
        if ($hasNewLogo) {
            $model->setLogo(id: (int) $existing['id'], logoFilename: $this->saveLogo($logo));
            $this->removeLogoFile($existing['logo_filename'] ?? null);
            return;
        }

        $shouldRemove = $remove && !empty($existing['logo_filename']);
        if (!$shouldRemove) return;

        $model->setLogo(id: (int) $existing['id'], logoFilename: null);
        $this->removeLogoFile($existing['logo_filename']);
    }

    private function removeLogoFile(?string $filename): void
    {
        ImageUpload::delete($_SERVER['DOCUMENT_ROOT'] . TradeLogo::DIR, $filename);
    }

    /** The logo, or the company's first letter when there is none. */
    private function logoHtml(?string $filename, string $company): string
    {
        $url = TradeLogo::url($filename);
        $hasLogo = $url !== '';
        if ($hasLogo) return '<img src="' . \htmlspecialchars($url) . '" alt="" height="28" style="max-width: 80px; object-fit: contain;">';

        $initial = \htmlspecialchars(\strtoupper(\mb_substr($company, 0, 1)));

        return '<span class="rounded bg-label-secondary d-inline-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.8rem;">' . $initial . '</span>';
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
