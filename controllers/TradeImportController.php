<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\TradeAccounts\Models\TradeLacrmHooks;
use Osmium\Services\TradeAccounts\Models\TradeLacrmImport;

/**
 * Bulk import of customers from the selected source (today only Less Annoying CRM).
 *
 * Routes:
 *   - index()  → /admin/trade/import/         (the page)
 *   - action() → /admin/trade/import/action/  (JSON: preview, run)
 */
class TradeImportController extends AdminController
{
    private const RECORD_TYPE = 'trade_customer';

    public function index(): void
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) $this->redirect('trade/customers/');

        $this->data['admin']['importAvailable'] = TradeLacrmHooks::importer($this->osmium->dataSource) !== null;
        $this->setView('trade-import/index.phtml');
    }

    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) $this->admin->jsonError('Invalid request token. Please refresh and try again.');

        $notAdmin = !$this->isAdmin();
        if ($notAdmin) $this->admin->jsonError('Only an administrator can import customers');

        $importer = TradeLacrmHooks::importer($this->osmium->dataSource);
        $noSource = $importer === null;
        if ($noSource) $this->admin->jsonError('Less Annoying CRM is not the selected customer source.');

        \set_time_limit(300); // LACRM is asked for each contact, a batch at a time

        try {
            $this->admin->jsonSuccess($this->run(importer: $importer, action: (string) ($input['action'] ?? '')));
        } catch (\Throwable $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function run(TradeLacrmImport $importer, string $action): array
    {
        $unavailable = 'Less Annoying CRM is not connected, or no Won status is set in its settings.';

        if ($action === 'preview') {
            $preview = $importer->preview();
            if ($preview === null) throw new \RuntimeException($unavailable);

            return ['success' => true] + $preview;
        }

        $isRun = $action === 'run';
        if (!$isRun) throw new \InvalidArgumentException('Unknown action');

        $result = $importer->run();
        if ($result === null) throw new \RuntimeException($unavailable);

        $this->admin->model->changelog->log(
            description: "Imported {$result['imported']} trade customers from Less Annoying CRM",
            recordType: self::RECORD_TYPE,
            recordId: 0,
        );

        return ['success' => true] + $result;
    }
}
