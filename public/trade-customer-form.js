(function () {
    'use strict';

    const actionUrl = adminBasePath + 'trade/customers/action/';
    const form = document.getElementById('customerForm');
    if (!form) return;

    function showError(message) {
        const box = document.getElementById('customerResult');
        box.textContent = message;
        box.classList.remove('d-none');
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function buildForm() {
        const id = document.getElementById('customerId').value;
        const data = new FormData();
        data.append('csrf_token', window.csrfToken);
        data.append('action', id ? 'update' : 'create');
        data.append('id', id);

        form.querySelectorAll('[data-field]').forEach(function (input) {
            data.append(input.dataset.field, input.value);
        });
        form.querySelectorAll('[data-ext-name]').forEach(function (input) {
            data.append('ext_' + input.dataset.extName, input.value);
        });

        data.append('remove_logo', document.getElementById('remove_logo').checked ? '1' : '');
        const logo = document.getElementById('logo').files[0];
        if (logo) data.append('logo', logo);

        return data;
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const button = document.getElementById('customerSaveButton');
        button.disabled = true;

        try {
            const response = await fetch(actionUrl, { method: 'POST', body: buildForm() });
            const result = await response.json();

            if (result.success) {
                window.location.href = form.dataset.listUrl;
                return;
            }

            showError(result.error || 'Failed to save');
        } catch (error) {
            showError('An error occurred while saving');
        } finally {
            button.disabled = false;
        }
    });
})();
