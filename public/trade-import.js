document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const config = window.TradeImportConfig;
    const actionUrl = config.basePath + 'trade/customers/import/action/';
    const modal = new bootstrap.Modal(document.getElementById('wizardModal'));
    const totalSteps = 3;
    let currentStep = 1;
    let upload = null; // what the server said about the file: token, headings, sample, guess
    let counts = null;
    let importDone = false;

    const back = document.getElementById('wizard-back');
    const next = document.getElementById('wizard-next');
    const submit = document.getElementById('wizard-submit');
    const errorBox = document.getElementById('wizard-error');

    document.getElementById('start-wizard').addEventListener('click', function () { modal.show(); });

    // Closing the wizard without importing: ask the server to delete the uploaded copy of the file
    document.getElementById('wizardModal').addEventListener('hidden.bs.modal', function () {
        if (!upload || importDone) return;
        fetch(actionUrl, {
            method: 'POST',
            keepalive: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'discard', token: upload.token, csrf_token: window.csrfToken })
        });
        upload = null;
        go(1);
    });
    back.addEventListener('click', function () { go(currentStep - 1); });
    next.addEventListener('click', onNext);
    submit.addEventListener('click', onImport);

    function plural(count, noun) {
        return count + ' ' + noun + (count === 1 ? '' : 's');
    }

    function showError(message) {
        errorBox.textContent = message || '';
        errorBox.classList.toggle('d-none', !message);
    }

    function el(tag, text, className) {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    }

    function go(step) {
        currentStep = step;
        showError('');

        document.querySelectorAll('.wizard-pane').forEach(function (pane) {
            pane.classList.toggle('active', parseInt(pane.dataset.step, 10) === currentStep);
        });
        document.querySelectorAll('.wizard-step').forEach(function (indicator) {
            const number = parseInt(indicator.dataset.step, 10);
            indicator.classList.toggle('active', number === currentStep);
            indicator.classList.toggle('completed', number < currentStep);
        });

        back.style.display = currentStep > 1 ? '' : 'none';
        next.style.display = currentStep < totalSteps ? '' : 'none';
        submit.style.display = currentStep === totalSteps ? '' : 'none';
        document.getElementById('import-status-choice').style.display = currentStep === totalSteps ? 'flex' : 'none';
    }

    async function post(body) {
        const isForm = body instanceof FormData;
        const response = await fetch(actionUrl, {
            method: 'POST',
            headers: isForm ? {} : { 'Content-Type': 'application/json' },
            body: isForm ? body : JSON.stringify(Object.assign({ csrf_token: window.csrfToken }, body))
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.error || 'Something went wrong');
        return result;
    }

    async function busy(button, work) {
        button.disabled = true;
        showError('');
        try {
            await work();
        } catch (error) {
            showError(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function onNext() {
        busy(next, currentStep === 1 ? uploadFile : checkMapping);
    }

    async function uploadFile() {
        const file = document.getElementById('import-file').files[0];
        if (!file) throw new Error('Choose a file first');

        const form = new FormData();
        form.append('file', file);
        form.append('csrf_token', window.csrfToken);

        upload = await post(form);
        buildMapping();
        go(2);
    }

    function columnName(index) {
        const heading = upload.headings[index];
        const letter = String.fromCharCode(65 + (index % 26));
        return heading ? heading : 'Column ' + letter;
    }

    function buildMapping() {
        document.getElementById('import-file-summary').textContent =
            upload.filename + ': ' + plural(upload.rowCount, 'customer row') + ' found';

        const body = document.getElementById('import-map-body');
        body.replaceChildren();

        config.fields.forEach(function (field) {
            const select = el('select', undefined, 'form-select');
            select.dataset.field = field.field;
            select.appendChild(new Option(field.required ? 'Choose a column...' : 'Not in my file', ''));
            upload.headings.forEach(function (heading, index) {
                select.appendChild(new Option(columnName(index), String(index)));
            });

            const guessed = upload.guess[field.field];
            select.value = guessed === null || guessed === undefined ? '' : String(guessed);

            const sample = el('td', '', 'text-muted small import-sample');
            select.addEventListener('change', function () { showSample(select, sample); });
            showSample(select, sample);

            const label = el('td', field.label);
            if (field.required) label.appendChild(el('span', ' *', 'text-danger'));

            const cell = el('td');
            cell.appendChild(select);
            const row = el('tr');
            row.append(label, cell, sample);
            body.appendChild(row);
        });
    }

    function showSample(select, cell) {
        const column = select.value;
        cell.textContent = column === ''
            ? ''
            : upload.sample.map(function (row) { return row[parseInt(column, 10)] || '-'; }).slice(0, 3).join('  |  ');
    }

    function currentMap() {
        const map = {};
        document.querySelectorAll('#import-map-body select').forEach(function (select) {
            map[select.dataset.field] = select.value;
        });
        return map;
    }

    function currentStatus() {
        return document.querySelector('input[name="import_status"]:checked').value;
    }

    async function checkMapping() {
        const result = await post({ action: 'preview', token: upload.token, map: currentMap(), status: currentStatus() });
        counts = result.counts;
        showPreview(result);
        go(3);
    }

    function showPreview(result) {
        const parts = [plural(counts.new, 'customer') + ' to add'];
        if (counts.fixed) parts.push(counts.fixed + ' with a default filled in');
        if (counts.exists + counts.invalid) parts.push(plural(counts.exists + counts.invalid, 'row') + ' skipped');
        document.getElementById('import-summary').textContent = parts.join(' · ');

        const body = document.getElementById('import-preview-body');
        body.replaceChildren();
        result.rows.forEach(function (row) {
            const tr = el('tr');
            tr.appendChild(el('td', String(row.line), 'text-muted'));
            [row.company_name, row.contact_name].forEach(function (value) {
                tr.appendChild(el('td', value === '' ? '-' : value));
            });

            const placeholder = row.email.endsWith('@placeholder.invalid');
            const email = el('td', placeholder ? 'No email' : row.email, placeholder ? 'text-muted fst-italic' : '');
            if (placeholder) email.title = row.email;
            tr.appendChild(email);

            tr.appendChild(el('td', row.phone === '' ? '-' : row.phone));

            const address = el('td', undefined, 'small');
            if (row.address.length === 0) address.textContent = '-';
            row.address.forEach(function (line) { address.appendChild(el('div', line)); });
            tr.appendChild(address);

            tr.appendChild(el('td', row.postcode === '' ? '-' : row.postcode));

            tr.appendChild(flag(row));
            body.appendChild(tr);
        });

        const hidden = counts.new + counts.exists + counts.invalid - result.rows.length;
        document.getElementById('import-more').textContent = hidden > 0 ? '...and ' + plural(hidden, 'more row') + ' not shown' : '';

        document.getElementById('wizard-submit-label').textContent = 'Import ' + plural(counts.new, 'customer');
        submit.disabled = counts.new === 0;
    }

    /** The row's flag: nothing for a plain add; a badge for a default or a skip, with the reason on hover. */
    function flag(row) {
        const cell = el('td', undefined, 'text-end');
        const added = row.result === 'new';
        const note = added ? row.fixes.join('. ') : row.reason;
        if (added && row.fixes.length === 0) return cell;

        const badge = el('span', added ? 'Default' : 'Skipped', 'badge ' + (added ? 'bg-label-warning' : 'bg-label-danger'));
        badge.title = note;
        cell.appendChild(badge);
        return cell;
    }

    function onImport() {
        busy(submit, async function () {
            const result = await post({ action: 'run', token: upload.token, map: currentMap(), status: currentStatus() });

            let text = plural(result.imported, 'customer') + ' added.';
            if (result.defaulted) text += ' ' + plural(result.defaulted, 'customer') + ' had a default filled in (see their notes).';
            if (result.skipped) text += ' ' + plural(result.skipped, 'row') + ' skipped.';
            if (result.failed) text += ' ' + plural(result.failed, 'row') + ' could not be added.';
            importDone = true;
            document.getElementById('import-done-text').textContent = text;
            document.getElementById('import-landing').classList.add('d-none');
            document.getElementById('import-done').classList.remove('d-none');
            modal.hide();
        });
    }
});
