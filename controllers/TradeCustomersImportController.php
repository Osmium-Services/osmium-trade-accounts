<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\TradeAccounts\Models\TradeCustomer;
use Osmium\Services\TradeAccounts\Models\TradeImportFields;
use Osmium\Services\TradeAccounts\Models\TradeImportFile;
use Osmium\Services\TradeAccounts\Models\TradeImportPlan;
use Osmium\Services\TradeAccounts\Models\TradeSpreadsheet;
use Osmium\Services\TradeAccounts\Models\TradeStatus;

/**
 * Bulk import of trade customers from a CSV or Excel file: upload, match the columns to the customer fields, check a
 * sample, then import. This is the default import when no customer source with its own import is selected.
 *
 * Routes:
 *   - index()  → /admin/trade/customers/import/         (the page and wizard)
 *   - action() → /admin/trade/customers/import/action/  (JSON: upload, preview, run)
 */
class TradeCustomersImportController extends AdminController
{
    private const RECORD_TYPE = 'trade_customer';
    private const SAMPLE_ROWS = 5;
    private const PREVIEW_ROWS = 10;
    private const PROBLEM_ROWS = 25;
    private const STATUSES = [TradeStatus::ACTIVE, TradeStatus::PENDING];

    public function index(): void
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) $this->redirect('trade/customers/');

        TradeImportFile::purgeExpired();

        $this->data['admin']['importConfig'] = [
            'fields' => TradeImportFields::describe(),
            'maxRows' => TradeSpreadsheet::MAX_ROWS,
        ];
        $this->setView('trade-customers/import.phtml');
    }

    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $isUpload = isset($_FILES['file']);
        $input = $isUpload ? $_POST : $this->admin->auth->getJsonInput();

        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) $this->admin->jsonError('Invalid request token. Please refresh and try again.');

        $notAdmin = !$this->isAdmin();
        if ($notAdmin) $this->admin->jsonError('Only an administrator can import customers');

        \set_time_limit(300);

        // The response is sent outside the try: jsonSuccess() ends the request by throwing, and a catch here would turn that into an error
        try {
            $result = $isUpload ? $this->upload($_FILES['file']) : $this->run($input);
        } catch (\Throwable $e) {
            $this->admin->jsonError($e->getMessage());
        }

        $this->admin->jsonSuccess($result);
    }

    /** @return array<string, mixed> */
    private function run(array $input): array
    {
        $action = (string) ($input['action'] ?? '');
        $isKnown = \in_array($action, ['preview', 'run', 'discard'], true);
        if (!$isKnown) throw new \InvalidArgumentException('Unknown action');

        $discard = $action === 'discard'; // the wizard was closed: the uploaded copy is not needed
        if ($discard) {
            TradeImportFile::delete((string) ($input['token'] ?? ''));
            return ['success' => true];
        }

        [$token, $rows, $matching, $status] = $this->loadRequest($input);
        $plan = TradeImportPlan::build(rows: $rows, matching: $matching, status: $status, findByEmail: $this->findByEmail(...));

        return $action === 'preview' ? $this->preview($plan) : $this->import(token: $token, plan: $plan);
    }

    /**
     * @param array<string, mixed> $file One entry of $_FILES
     * @return array<string, mixed>
     */
    private function upload(array $file): array
    {
        $tooBig = (int) $file['size'] > TradeSpreadsheet::MAX_BYTES || $file['error'] === UPLOAD_ERR_INI_SIZE;
        if ($tooBig) throw new \InvalidArgumentException('The file is larger than ' . (TradeSpreadsheet::MAX_BYTES / 1048576) . ' MB. Split it into smaller files');

        $failed = $file['error'] !== UPLOAD_ERR_OK;
        if ($failed) throw new \InvalidArgumentException('The file did not upload. Please try again');

        $extension = TradeSpreadsheet::extension((string) $file['name']);
        $notSupported = $extension === null;
        if ($notSupported) throw new \InvalidArgumentException('Please upload a CSV or Excel (.xlsx) file. An old .xls file needs saving as .xlsx or CSV first');

        $rows = TradeSpreadsheet::read(path: $file['tmp_name'], extension: $extension);
        $token = TradeImportFile::store(uploadedPath: $file['tmp_name'], extension: $extension);
        $headings = $rows[0];

        return [
            'success' => true,
            'token' => $token,
            'filename' => \basename((string) $file['name']),
            'headings' => $headings,
            'sample' => \array_slice($rows, 1, self::SAMPLE_ROWS),
            'rowCount' => \count($rows) - 1,
            'guess' => TradeImportFields::guess($headings),
        ];
    }

    /** @return array{0: string, 1: array<int, array<int, string>>, 2: array<string, int|null>, 3: string} token, data rows, the matching, the status */
    private function loadRequest(array $input): array
    {
        $token = (string) ($input['token'] ?? '');
        $stored = TradeImportFile::find($token);
        $gone = $stored === null;
        if ($gone) throw new \InvalidArgumentException('That upload has expired. Please upload the file again');

        $rows = TradeSpreadsheet::read(path: $stored['path'], extension: $stored['extension']);
        $matching = TradeImportFields::clean(matching: (array) ($input['map'] ?? []), columnCount: \count($rows[0]));
        $problem = TradeImportFields::problem($matching);
        if ($problem !== null) throw new \InvalidArgumentException($problem);

        $status = (string) ($input['status'] ?? TradeStatus::ACTIVE);
        $badStatus = !\in_array($status, self::STATUSES, true);
        if ($badStatus) throw new \InvalidArgumentException('Choose whether to import as active or pending');

        return [$token, \array_slice($rows, 1), $matching, $status];
    }

    /**
     * @param array<int, array{line: int, result: string, reason: string, data: ?array<string, mixed>}> $plan
     * @return array<string, mixed>
     */
    private function preview(array $plan): array
    {
        $shown = static fn(array $entry) => [
            'line' => $entry['line'],
            'result' => $entry['result'],
            'reason' => $entry['reason'],
            'fixes' => $entry['fixes'],
            'company_name' => (string) $entry['data']['company_name'],
            'contact_name' => (string) $entry['data']['contact_name'],
            'email' => (string) $entry['data']['email'],
            'phone' => (string) $entry['data']['phone'],
            'address' => \array_values(\array_filter([$entry['data']['address_line_1'], $entry['data']['address_line_2'], $entry['data']['city'], $entry['data']['county']])),
            'postcode' => (string) $entry['data']['postcode'],
        ];

        $skipped = \array_filter($plan, static fn(array $entry) => $entry['result'] !== TradeImportPlan::NEW);
        $defaulted = \array_filter($plan, static fn(array $entry) => $entry['result'] === TradeImportPlan::NEW && $entry['fixes'] !== []);

        return [
            'success' => true,
            'counts' => TradeImportPlan::counts($plan),
            'rows' => \array_map($shown, \array_slice($plan, 0, self::PREVIEW_ROWS)),
            'problems' => \array_map($shown, \array_slice(\array_values($skipped), 0, self::PROBLEM_ROWS)),
            'defaulted' => \array_map($shown, \array_slice(\array_values($defaulted), 0, self::PROBLEM_ROWS)),
        ];
    }

    /**
     * @param array<int, array{line: int, result: string, reason: string, data: ?array<string, mixed>}> $plan
     * @return array<string, mixed>
     */
    private function import(string $token, array $plan): array
    {
        $model = new TradeCustomer($this->osmium->dataSource);
        $imported = 0;
        $failed = 0;

        foreach ($plan as $entry) {
            $skip = $entry['result'] !== TradeImportPlan::NEW;
            if ($skip) continue;

            try {
                $model->create(data: $entry['data'], createdBy: $this->getCurrentUserId() ?: null);
                $imported++;
            } catch (\Throwable) {
                $failed++; // e.g. the same email added by someone else since the check
            }
        }

        TradeImportFile::delete($token);
        $this->admin->model->changelog->log(description: "Imported {$imported} trade customers from a spreadsheet", recordType: self::RECORD_TYPE, recordId: 0);

        $counts = TradeImportPlan::counts($plan);

        return ['success' => true, 'imported' => $imported, 'defaulted' => $counts['fixed'], 'failed' => $failed, 'skipped' => $counts[TradeImportPlan::EXISTS] + $counts[TradeImportPlan::INVALID]];
    }

    /** @return array<string, mixed>|null the live customer using this email as primary or secondary */
    private function findByEmail(string $email): ?array
    {
        return (new TradeCustomer($this->osmium->dataSource))->getByEmail($email);
    }
}
