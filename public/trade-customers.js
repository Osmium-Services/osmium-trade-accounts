(function () {
    'use strict';

    const config = window.TradeCustomersConfig || {};
    const actionUrl = config.basePath + 'trade/customers/action/';

    const FIELDS = {
        company_name: 'customerCompany',
        contact_name: 'customerContact',
        email: 'customerEmail',
        email2: 'customerEmail2',
        phone: 'customerPhone',
        address_line_1: 'customerAddress1',
        address_line_2: 'customerAddress2',
        city: 'customerCity',
        county: 'customerCounty',
        postcode: 'customerPostcode',
        country: 'customerCountry',
        notes: 'customerNotes'
    };

    function showError(message) {
        const resultDiv = document.getElementById('customerResult');
        resultDiv.className = 'alert alert-danger';
        resultDiv.textContent = message;
        resultDiv.classList.remove('d-none');
    }

    function clearResult() {
        const resultDiv = document.getElementById('customerResult');
        resultDiv.className = 'alert d-none';
        resultDiv.textContent = '';
    }

    function openModal(customer) {
        clearResult();

        const isEdit = Boolean(customer);
        document.getElementById('customerModalTitle').textContent = isEdit ? 'Edit Customer' : 'Add Customer';
        document.getElementById('customerId').value = isEdit ? customer.id : '';
        document.getElementById('customerStatusWrap').classList.toggle('d-none', isEdit);
        document.getElementById('customerStatus').value = 'pending';

        Object.keys(FIELDS).forEach(function (field) {
            const fallback = field === 'country' ? 'United Kingdom' : '';
            document.getElementById(FIELDS[field]).value = isEdit ? (customer[field] || fallback) : fallback;
        });

        new bootstrap.Modal(document.getElementById('customerModal')).show();
    }

    function buildForm() {
        const id = document.getElementById('customerId').value;
        const form = new FormData();
        form.append('csrf_token', window.csrfToken);
        form.append('action', id ? 'update' : 'create');
        form.append('id', id);
        form.append('status', document.getElementById('customerStatus').value);

        Object.keys(FIELDS).forEach(function (field) {
            form.append(field, document.getElementById(FIELDS[field]).value);
        });

        return form;
    }

    async function handleSubmit(e) {
        e.preventDefault();
        clearResult();

        const btn = document.getElementById('customerSaveButton');
        btn.disabled = true;

        try {
            const response = await fetch(actionUrl, { method: 'POST', body: buildForm() });
            const result = await response.json();

            if (result.success) {
                window.location.reload();
                return;
            }

            showError(result.error || 'Failed to save');
        } catch (error) {
            showError('An error occurred while saving');
        } finally {
            btn.disabled = false;
        }
    }

    function init() {
        document.getElementById('addCustomerButton').addEventListener('click', function () {
            openModal(null);
        });

        // Row actions come from the ListView; Edit fires list:action with the row's id
        document.addEventListener('list:action', function (e) {
            if (e.detail.action !== 'edit') return;
            openModal(config.customers[e.detail.id]);
        });

        document.getElementById('customerForm').addEventListener('submit', handleSubmit);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
