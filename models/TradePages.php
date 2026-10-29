<?php

declare(strict_types=1);

namespace Osmium\Services\TradeAccounts\Models;

use Osmium\Core\Library\AdminPaths;
use Osmium\Modules\Admin\Models\Page;
use Osmium\Modules\Admin\Services\AdminPages;

/**
 * Keeps the four public pages of Trade Accounts in step with the service, as the Shop does for its pages: created
 * when it is installed, put in the bin (never removed) when it is disabled or removed, restored when enabled again
 * with the same ids. The pages are ordinary Pages rows; TradeClaim answers for them.
 */
class TradePages
{
    /** url => [title, robots] */
    private const DEFINITIONS = [
        'trade' => ['Trade Account', 'noindex,nofollow'],
        'trade/login' => ['Trade Login', 'noindex,nofollow'],
        'trade/verify' => ['Continue Login', 'noindex,nofollow'],
        'trade/code' => ['Enter Your Code', 'noindex,nofollow'],
    ];

    public function __construct(private Page $page, private AdminPages $pages)
    {
    }

    /** @return string[] */
    public static function urls(): array
    {
        return \array_keys(self::DEFINITIONS);
    }

    /** pages.protected_urls: pages no page tool may delete or move */
    public static function protectedUrls(array $payload): array
    {
        return self::urls();
    }

    /** service.installed and service.enabled */
    public static function onEnabled(array $payload): void
    {
        self::forDatabase($payload['db'])->sync(true);
    }

    /** service.disabled and service.removing */
    public static function onDisabled(array $payload): void
    {
        self::forDatabase($payload['db'])->sync(false);
    }

    public function sync(bool $on): void
    {
        $existing = [];
        foreach ($this->page->getAll() as $row) {
            $existing[$row['url']] = $row;
        }

        $now = \date('Y-m-d H:i:s');
        foreach (self::urls() as $url) {
            $row = $existing[$url] ?? null;
            $isMissing = $row === null;
            $isInBin = !$isMissing && $row['deleted_at'] !== null;

            if ($on && $isMissing) $this->create($url);
            if ($on && $isInBin) $this->page->restore((int) $row['id']);
            if (!$on && !$isMissing && !$isInBin) $this->page->softDelete((int) $row['id'], $now);
        }

        $this->pages->regenerateRoutesFile();
    }

    private static function forDatabase(\Osmium\Core\Library\OsmiumPDO $db): self
    {
        require_once AdminPaths::path('services/AdminPages.php');
        $page = new Page($db, 'pages');

        return new self($page, new AdminPages($page));
    }

    private function create(string $url): void
    {
        [$title, $robots] = self::DEFINITIONS[$url];

        $this->page->insert(
            id: $this->page->getNextId(),
            parentPageId: null,
            url: $url,
            title: $title,
            description: null,
            robots: $robots,
            now: \date('Y-m-d H:i:s'),
        );
    }
}
