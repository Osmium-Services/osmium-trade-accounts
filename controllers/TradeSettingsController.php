<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Controllers;

use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\TradeAccounts\Models\TradeConfig;
use Osmium\Services\TradeAccounts\Models\TradeSources;

/**
 * Trade Accounts settings: a full-page form POST/redirect flow.
 *
 * Routes:
 *   - index() → /admin/settings/trade/  (GET shows the page; POST saves)
 */
class TradeSettingsController extends AdminController
{
    private const FLASH_KEYS = ['trade_saved', 'trade_error'];

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->save();

        $config = TradeConfig::get();

        $this->data['admin']['config']['trade'] = $config;
        $this->data['admin']['tradeSources'] = TradeSources::options(answers: $this->sourceAnswers(), saved: (string) $config['source']);
        $this->data['admin']['settingsSaved'] = $_SESSION['trade_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['trade_error'] ?? false;
        foreach (self::FLASH_KEYS as $key) {
            unset($_SESSION[$key]);
        }

        $this->setView('trade-settings/index.phtml');
    }

    private function save(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->fail('Invalid form submission. Please try again.');

        $source = \trim((string) ($_POST['source'] ?? ''));
        $options = TradeSources::options(answers: $this->sourceAnswers());
        $unknown = $source !== '' && !isset($options[$source]);
        if ($unknown) $this->fail('Choose a source from the list.');

        TradeConfig::save(['source' => $source, 'codeRequired' => isset($_POST['code_required'])]);
        $this->admin->model->changelog->log(description: 'Updated Trade Accounts settings', recordType: 'settings');

        $_SESSION['trade_saved'] = true;
        $this->redirect('settings/trade/');
    }

    /** @return array<int, mixed> */
    private function sourceAnswers(): array
    {
        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));

        return $hooks->collectAll('trade.sources', ['osmium' => $this->osmium, 'dataSource' => $this->osmium->dataSource]);
    }

    private function fail(string $message): never
    {
        $_SESSION['trade_error'] = $message;
        $this->redirect('settings/trade/');
    }
}
