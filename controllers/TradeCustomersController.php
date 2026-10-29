<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Services\TradeAccounts\Models\TradeAuthFactory;
use Osmium\Services\TradeAccounts\Models\TradeCustomer;
use Osmium\Services\TradeAccounts\Models\TradeCustomerExtras;
use Osmium\Services\TradeAccounts\Models\TradeCustomerInput;
use Osmium\Services\TradeAccounts\Models\TradeLacrmHooks;
use Osmium\Services\TradeAccounts\Models\TradeLogo;
use Osmium\Services\TradeAccounts\Models\TradeStatus;
use Osmium\Services\TradeAccounts\Models\TradeStatusChange;

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
        $this->data['admin']['canImport'] = $this->isAdmin() && TradeLacrmHooks::importer($this->osmium->dataSource) !== null;
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/trade-customers.php';
        $extras = TradeCustomerExtras::load(db: $this->osmium->dataSource, customerIds: \array_map(static fn(array $row): int => (int) $row['id'], \array_merge($active, $inactive)));
        $this->data['admin']['extraFields'] = $extras['fields'];
        $this->data['admin']['customers'] = \array_map(fn(array $row): array => $this->formatCustomer($row) + ['extra' => $extras['values'][(int) $row['id']] ?? []], $active);
        $this->data['admin']['inactiveCustomers'] = \array_map(fn(array $row): array => $this->formatCustomer($row) + ['extra' => $extras['values'][(int) $row['id']] ?? []], $inactive);
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
     * @return array{tmp_name: string, extension: string, mimeType: string}|null
     */
    private function validateLogo(): ?array
    {
        $file = $_FILES['logo'] ?? null;
        $noFileSent = $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
        if ($noFileSent) return null;

        $uploadFailed = $file['error'] !== UPLOAD_ERR_OK;
        if ($uploadFailed) throw new \InvalidArgumentException('The logo did not upload');

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $typeNotAllowed = !TradeLogo::isAllowedType($mimeType);
        if ($typeNotAllowed) throw new \InvalidArgumentException('Logo must be a JPG, PNG, GIF, WebP or AVIF image');

        $maxUpload = OsmiumAdmin::getMaxUploadSize();
        $tooLarge = $file['size'] > $maxUpload['bytes'];
        if ($tooLarge) throw new \InvalidArgumentException('Logo is too large. Maximum ' . $maxUpload['formatted']);

        return ['tmp_name' => $file['tmp_name'], 'extension' => TradeLogo::TYPES[$mimeType], 'mimeType' => $mimeType];
    }

    /**
     * Saves the checked upload under a new random name and returns that name. A logo with a side over
     * TradeLogo::MAX_DIMENSION is scaled down to fit (never up); a smaller one is moved as uploaded.
     */
    private function saveLogo(array $logo): string
    {
        $dir = $_SERVER['DOCUMENT_ROOT'] . TradeLogo::DIR;
        $dirMissing = !\is_dir($dir);
        if ($dirMissing) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $filename = \bin2hex(\random_bytes(8)) . '.' . $logo['extension'];
        $size = \getimagesize($logo['tmp_name']);
        $isImage = $size !== false;
        if (!$isImage) throw new \Exception('Failed to read the logo');

        $needsResize = TradeLogo::scale(\max($size[0], $size[1])) < 1.0;
        $saved = $needsResize
            ? $this->saveResized(logo: $logo, size: $size, path: $dir . $filename)
            : \move_uploaded_file(from: $logo['tmp_name'], to: $dir . $filename);
        if (!$saved) throw new \Exception('Failed to save the logo');

        return $filename;
    }

    /** @param array{0: int, 1: int} $size Width and height of the upload */
    private function saveResized(array $logo, array $size, string $path): bool
    {
        $gdName = $logo['extension'] === 'jpg' ? 'jpeg' : $logo['extension']; // GD's name for the format
        $decoder = 'imagecreatefrom' . $gdName;
        $encoder = 'image' . $gdName;
        $unsupported = !\function_exists($decoder) || !\function_exists($encoder);
        if ($unsupported) throw new \Exception("This server can't resize {$logo['extension']} images. Try JPG or PNG instead.");

        $source = $decoder($logo['tmp_name']);
        if (!$source) throw new \Exception('Failed to process the logo');

        $scale = TradeLogo::scale(\max($size[0], $size[1]));
        $width = (int) \round($size[0] * $scale);
        $height = (int) \round($size[1] * $scale);

        $resized = \imagecreatetruecolor(width: $width, height: $height);
        \imagealphablending(image: $resized, enable: false);
        \imagesavealpha(image: $resized, enable: true);
        \imagecopyresampled(
            dst_image: $resized,
            src_image: $source,
            dst_x: 0,
            dst_y: 0,
            src_x: 0,
            src_y: 0,
            dst_width: $width,
            dst_height: $height,
            src_width: $size[0],
            src_height: $size[1],
        );

        return $encoder($resized, $path);
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
        $hasFile = !empty($filename);
        if (!$hasFile) return;

        $path = $_SERVER['DOCUMENT_ROOT'] . TradeLogo::DIR . \basename($filename);
        $exists = \file_exists($path);
        if ($exists) \unlink($path);
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
