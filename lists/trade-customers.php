<?php

declare(strict_types=1);

use Osmium\Modules\Admin\Core\ListView;

/**
 * Trade customer lists, rendered by Osmium\Modules\Admin\Core\ListView. Row fields (statusClass, statusLabel...) are
 * prepared by TradeCustomersController::formatCustomer() and formatRecent()/formatDeleted().
 */
require_once 'app/core/admin/services/ChangedByCell.php';

$changedByColumn = ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\ChangedByCell::html($row, (string)($context['avatarUrl'] ?? ''))];

$customerColumns = [
    ['key' => 'company_name', 'label' => 'Company', 'type' => 'text', 'strong' => true, 'sub' => ['key' => 'contact_name', 'style' => 'muted']],
    [
        'key' => 'email',
        'label' => 'Email',
        'type' => 'html',
        'small' => true,
        'fill' => true,
        'render' => fn(array $row, array $context): string => '<a href="mailto:' . \htmlspecialchars((string) $row['email']) . '">' . \htmlspecialchars((string) $row['email']) . '</a>',
    ],
    ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'small' => true, 'nowrap' => true, 'fallback' => '-'],
    ['key' => 'statusLabel', 'label' => 'Status', 'type' => 'badge', 'text' => true, 'classKey' => 'statusClass', 'nowrap' => true],
];

$customerActions = [
    ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'event' => 'edit'],
    [
        'label' => 'Activate',
        'icon' => 'bx-check-circle',
        'action' => 'activate',
        'visible' => static fn(array $row, array $context): bool => $row['status'] !== 'active',
    ],
    [
        'label' => 'Suspend',
        'icon' => 'bx-pause-circle',
        'action' => 'suspend',
        'confirm' => 'Suspend this customer? They will not be able to log in until reactivated.',
        'visible' => static fn(array $row, array $context): bool => $row['status'] === 'active',
    ],
    [
        'label' => 'Close account',
        'icon' => 'bx-x-circle',
        'action' => 'close',
        'confirm' => 'Close this account? They will not be able to log in. It can be reopened with Activate.',
        'visible' => static fn(array $row, array $context): bool => $row['status'] !== 'closed',
    ],
    [
        'label' => 'Delete',
        'icon' => 'bx-trash',
        'action' => 'delete',
        'confirm' => 'Delete this customer? It can be restored from Deleted Customers.',
        'danger' => true,
    ],
];

return [
    'active' => [
        'id' => 'trade-customers',
        'title' => 'Trade Customers',
        'noun' => ['customer', 'customers'],
        'endpoint' => 'trade/customers/action/',
        'note' => 'Pending customers are waiting for approval and cannot log in until activated.',
        'emptyText' => 'No trade customers yet - use Add Customer to create one.',
        'order' => [0, 'asc'],
        'columns' => $customerColumns,
        'actions' => $customerActions,
    ],

    'inactive' => [
        'id' => 'trade-customers-inactive',
        'title' => 'Suspended and Closed',
        'noun' => ['customer', 'customers'],
        'icon' => 'bx-pause-circle',
        'endpoint' => 'trade/customers/action/',
        'collapsible' => ['label' => 'Suspended and Closed ({count})'],
        'hideWhenEmpty' => true,
        'order' => [0, 'asc'],
        'columns' => $customerColumns,
        'actions' => $customerActions,
    ],

    'recent' => [
        'id' => 'trade-customers-recent',
        'title' => 'Recent Changes',
        'noun' => ['customer', 'customers'],
        'icon' => 'bx-time-five',
        'rowClass' => static fn(array $row): string => empty($row['deleted_at']) ? '' : 'table-danger',
        'endpoint' => 'trade/customers/action/',
        'collapsible' => ['label' => 'Recent Changes ({count})'],
        'hideWhenEmpty' => false,
        'columns' => [
            $changedByColumn,
            ['key' => 'company_name', 'label' => 'Company', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'updatedDate', 'label' => 'Updated', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
    ],

    'deleted' => ListView::deletedPreset([
        'id' => 'trade-customers-deleted',
        'title' => 'Deleted Customers',
        'noun' => ['customer', 'customers'],
        'endpoint' => 'trade/customers/action/',
        'hideWhenEmpty' => false,
        'headerButton' => [
            'label' => 'Purge All',
            'icon' => 'bx-trash',
            'class' => 'btn-outline-danger',
            'action' => 'purge_deleted',
            'confirm' => 'Permanently delete all deleted customers? This cannot be undone!',
            'visible' => fn(array $rows, array $context): bool => (bool) $context['isAdmin'],
        ],
        'columns' => [
            $changedByColumn,
            ['key' => 'company_name', 'label' => 'Company', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'deletedDate', 'label' => 'Deleted', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
    ]),
];
