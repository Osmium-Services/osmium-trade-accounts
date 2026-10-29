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

        document.getElementById('customerLogo').value = '';
        document.getElementById('customerRemoveLogo').checked = false;
        document.getElementById('customerRemoveLogoWrap').classList.toggle('d-none', !(isEdit && customer.hasLogo));

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

        form.append('remove_logo', document.getElementById('customerRemoveLogo').checked ? '1' : '');

        const logo = document.getElementById('customerLogo').files[0];
        if (logo) form.append('logo', logo);

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

    // Opens the customer's account in a new tab. The tab is opened first, inside the click, so a pop-up blocker allows it.
    async function loginAs(id) {
        const tab = window.open('', '_blank');

        try {
            const response = await fetch(actionUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, action: 'login_as', csrf_token: window.csrfToken })
            });
            const result = await response.json();

            if (result.success) {
                tab.location = result.url;
                return;
            }

            tab.close();
            adminAlert(result.error || 'Could not log in as this customer');
        } catch (error) {
            tab.close();
            adminAlert('An error occurred');
        }
    }

    function init() {
        document.getElementById('addCustomerButton').addEventListener('click', function () {
            openModal(null);
        });

        // Row actions come from the ListView; Edit fires list:action with the row's id
        document.addEventListener('list:action', function (e) {
            if (e.detail.action === 'login-as') loginAs(e.detail.id);
            if (e.detail.action === 'edit') openModal(config.customers[e.detail.id]);
        });

        document.getElementById('customerForm').addEventListener('submit', handleSubmit);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
