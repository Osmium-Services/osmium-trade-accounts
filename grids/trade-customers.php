<?php

declare(strict_types=1);

/**
 * Grid (card) layout for the active Trade Customers list, rendered by
 * Osmium\Modules\Admin\Core\ListViewGrid. Columns use the same vocabulary as lists/trade-customers.php
 * but nothing is shared with it. The actions are a copy of the list's for now.
 */
return [
    'columns' => 4,
    'imageHeight' => 100,
    'image' => ['key' => 'logoUrl', 'type' => 'image', 'altKey' => 'company_name'],
    'title' => ['key' => 'company_name', 'type' => 'text', 'strong' => true, 'sub' => ['key' => 'contact_name', 'style' => 'muted']],
    'meta' => [
        ['key' => 'statusLabel', 'type' => 'badge', 'text' => true, 'classKey' => 'statusClass'],
        [
            'key' => 'email',
            'type' => 'html',
            'small' => true,
            'render' => fn(array $row, array $context): string => '<a href="mailto:' . \htmlspecialchars((string) $row['email']) . '">' . \htmlspecialchars((string) $row['email']) . '</a>',
        ],
        ['key' => 'phone', 'type' => 'html', 'small' => true, 'render' => fn(array $row, array $context): string => \Osmium\Services\TradeAccounts\Models\TradePhone::html((string) $row['phone'])],
    ],
    'actionsAt' => 'bottom-end',
    'actions' => [
        ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'href' => 'trade/customers/edit/?id={id}'],
        [
            'label' => 'Log in as customer',
            'icon' => 'bx-log-in-circle',
            'event' => 'login-as',
            'visible' => static fn(array $row, array $context): bool => $row['status'] === 'active' && !empty($context['isAdmin']),
        ],
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
    ],
];
