(function () {
    'use strict';

    const config = window.TradeCustomersConfig || {};
    const actionUrl = config.basePath + 'trade/customers/action/';

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
        // Row actions come from the ListView; Log in as fires list:action with the row's id
        document.addEventListener('list:action', function (e) {
            if (e.detail.action === 'login-as') loginAs(e.detail.id);
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
