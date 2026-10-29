(function () {
    'use strict';

    const actionUrl = window.TradeImportConfig.basePath + 'trade/import/action/';

    function plural(count, noun) {
        return count + ' ' + noun + (count === 1 ? '' : 's');
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

    /**
     * One list-and-confirm block: a "check" button asks for a preview and lists who is affected, then a "run" button
     * does it. ids: the block's element ids; calls: the server actions; describe: turns a preview or result into text.
     */
    function block(ids, calls, describe) {
        const box = document.getElementById(ids.result);
        const list = document.getElementById(ids.list);
        const items = document.getElementById(ids.items);

        function show(message, kind) {
            box.className = 'alert alert-' + kind + ' mt-3';
            box.style.whiteSpace = 'pre-line';
            box.textContent = message;
        }

        async function check() {
            const button = document.getElementById(ids.check);
            button.disabled = true;
            list.classList.add('d-none');
            show('Asking Less Annoying CRM...', 'info');

            try {
                const result = await call(calls.preview);
                const rows = result[calls.rows];
                items.replaceChildren();
                rows.forEach(function (row) {
                    const li = document.createElement('li');
                    li.className = 'list-group-item';
                    li.textContent = row.name;
                    items.appendChild(li);
                });
                show(describe.preview(result), 'info');
                list.classList.toggle('d-none', rows.length === 0);
            } catch (error) {
                show(error.message, 'danger');
            } finally {
                button.disabled = false;
            }
        }

        async function run() {
            const button = document.getElementById(ids.run);
            button.disabled = true;
            show('Working. This can take a minute...', 'info');

            try {
                const result = await call(calls.run);
                list.classList.add('d-none');
                const lines = describe.result(result);
                const problems = lines.length > 1;
                show(lines.join('\n'), problems ? 'warning' : 'success');
            } catch (error) {
                show(error.message, 'danger');
            } finally {
                button.disabled = false;
            }
        }

        document.getElementById(ids.check).addEventListener('click', check);
        document.getElementById(ids.run).addEventListener('click', run);
    }

    document.addEventListener('DOMContentLoaded', function () {
        block(
            { check: 'importPreviewButton', run: 'importRunButton', result: 'importResult', list: 'importNewList', items: 'importNewItems' },
            { preview: 'preview', run: 'run', rows: 'new' },
            {
                preview: function (r) {
                    const already = plural(r.existing, 'person') + ' already a customer.';
                    return r.new.length === 0 ? 'Nothing new to import. ' + already : plural(r.new.length, 'new customer') + ' to import. ' + already;
                },
                result: function (r) {
                    const lines = [plural(r.imported, 'customer') + ' imported, ' + r.existing + ' already customers.'];
                    r.skipped.forEach(function (line) { lines.push('Skipped - ' + line); });
                    return lines;
                }
            }
        );

        block(
            { check: 'lostPreviewButton', run: 'lostRunButton', result: 'lostResult', list: 'lostList', items: 'lostItems' },
            { preview: 'lost_preview', run: 'lost_run', rows: 'suspend' },
            {
                preview: function (r) {
                    const left = r.ignored + ' not affected (not customers, or already suspended or closed).';
                    return r.suspend.length === 0 ? 'Nobody to suspend. ' + left : plural(r.suspend.length, 'customer') + ' to suspend. ' + left;
                },
                result: function (r) {
                    const lines = [plural(r.suspended, 'customer') + ' suspended.'];
                    r.failed.forEach(function (line) { lines.push('Problem - ' + line); });
                    return lines;
                }
            }
        );
    });
})();
