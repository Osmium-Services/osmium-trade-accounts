(function () {
    'use strict';

    const actionUrl = window.TradeImportConfig.basePath + 'trade/import/action/';

    function show(message, kind) {
        const box = document.getElementById('importResult');
        box.className = 'alert alert-' + kind + ' mt-3';
        box.textContent = message;
    }

    async function call(action) {
        const response = await fetch(actionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, csrf_token: window.csrfToken })
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Failed');
        return result;
    }

    function plural(count, noun) {
        return count + ' ' + noun + (count === 1 ? '' : 's');
    }

    async function preview() {
        const button = document.getElementById('importPreviewButton');
        const list = document.getElementById('importNewList');
        const items = document.getElementById('importNewItems');
        button.disabled = true;
        list.classList.add('d-none');
        show('Asking Less Annoying CRM...', 'info');

        try {
            const result = await call('preview');
            items.replaceChildren();
            result.new.forEach(function (contact) {
                const li = document.createElement('li');
                li.className = 'list-group-item';
                li.textContent = contact.name;
                items.appendChild(li);
            });

            const already = plural(result.existing, 'person') + ' already a customer.';
            show(result.new.length === 0 ? 'Nothing new to import. ' + already : plural(result.new.length, 'new customer') + ' to import. ' + already, 'info');
            list.classList.toggle('d-none', result.new.length === 0);
        } catch (error) {
            show(error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    }

    async function run() {
        const button = document.getElementById('importRunButton');
        button.disabled = true;
        show('Importing. This can take a minute...', 'info');

        try {
            const result = await call('run');
            document.getElementById('importNewList').classList.add('d-none');

            const lines = [plural(result.imported, 'customer') + ' imported, ' + result.existing + ' already customers.'];
            result.skipped.forEach(function (line) { lines.push('Skipped - ' + line); });
            show(lines.join('\n'), result.skipped.length === 0 ? 'success' : 'warning');
            document.getElementById('importResult').style.whiteSpace = 'pre-line';
        } catch (error) {
            show(error.message, 'danger');
        } finally {
            button.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('importPreviewButton').addEventListener('click', preview);
        document.getElementById('importRunButton').addEventListener('click', run);
    });
})();
