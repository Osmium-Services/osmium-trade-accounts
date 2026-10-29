# Trade Accounts

Trade customers for an Osmium site: a customer list with a status workflow, an admin to manage it, and passwordless login (an emailed link, then an optional emailed code). Where customers come from is a setting, so the service does not assume any one CRM.

Pricing, orders, invoices and downloads are not here. They belong to a later Trade Ordering service, which needs the Shop.

## What it does

- **Customers** in Admin > Trade Customers: add, edit, status (pending, active, suspended, closed), a Logo per customer, delete and restore, and a bin. An admin can use *Log in as customer* on an active customer to see their account.
- **Login** for customers at `/trade/login`: they enter their email, get a link, press Continue, then enter a 6-digit code (turn the code off under Settings > Trade Accounts). Only active customers can log in; the same screen is shown for an unknown email, so addresses cannot be probed.
- **Customer source** under Settings > Trade Accounts: *None* (you add and approve customers yourself) or any installed source. Today the only source is Less Annoying CRM, whose adapter lives inside this service, so the LACRM service stays a plain connection.

## Pages and theme views

The service creates four pages (`trade`, `trade/login`, `trade/verify`, `trade/code`) and answers core's `page.claim` hook for them. **The theme supplies the views**, under the site's `trade/views/`. Every form needs a hidden field `token` holding the site's usual `$template['formToken']`.

| View | Data | Notes |
|---|---|---|
| `login.phtml` | `$tradeLogin`: `action`, `honeypot`, `error`, `sent`, `email` | Field `email`. The honeypot is a hidden field that must stay empty |
| `verify.phtml` | `$tradeVerify`: `action`, `token`, `tokenOk` | Post the token in a hidden field `t`. This Continue page exists so an email scanner opening the link cannot use it up |
| `code.phtml` | `$tradeCode`: `action`, `email`, `error`, `notice` | Field `code`. A second form posts `type=resend` to the same action |
| `account.phtml` | `$tradeAccount`: `customer`, `logoutAction` | The logged-in view. `customer` is the customer row |

A page claim cannot redirect, so a visitor who is not logged in is shown the login view in place. Forms post to `api/trade-login`, `api/trade-verify`, `api/trade-code` and `api/trade-logout`, which redirect.

## Login rules

A link lasts 30 minutes and works once; a code lasts 5 minutes, with 3 wrong tries. One link per minute per email, 10 requests per 15 minutes per visitor address. Ten failures lock an account for an hour. A customer who is suspended or closed is signed out on their next request, and a session expires after 2 idle hours. Link tokens are stored hashed.

Both login emails (the link and the code) are in the Template test list under Settings > Email, built from made-up data and sent only to the address you type.

## Events (hooks)

Trade acts only on events whose `customer.source` is the source selected in settings. A source service (or an adapter in this service) fires these with `customer` (`email`, `company_name`, `contact_name`, `phone`, `address_line_1`, `address_line_2`, `city`, `county`, `postcode`, `country`, `notes`, `source`, `source_ref`) and `dataSource`:

| Hook | Effect |
|---|---|
| `trade.application.received` | A new pending customer, or ignored when the customer exists |
| `trade.customer.approved` | Activates the customer, or creates one already active |
| `trade.customer.suspended` | Suspends the customer if they exist |
| `trade.customer.updated` | Changes details only, never the status |

Customers are found by source and `source_ref`, else by email. Events that write details need a complete, valid customer; others are logged and ignored.

Trade answers `trade.customer.current` (`handle`; payload `osmium`): the logged-in trade customer as `id`, `company_name`, `contact_name` and `status`, or null when nobody is logged in (or the customer has since been suspended or closed). It lets another service, such as Trade Ordering or Trade Downloads, serve trade customers only without calling this service's code, and it shares no contact details.

Trade announces `trade.customer.status_changed` (`customerId`, `from`, `to`, `dataSource`) whenever a status changes, and collects `trade.sources` answers (`id`, `label`, `ready`, `settingsRoute`) to fill the source setting.

## Extra customer fields

Another service can add its own fields to the customer edit form and keep the values itself. Trade shows the fields, checks them with the service, and passes the values on. Trade stores nothing for them.

| Hook | Kind | Payload | Answer |
|---|---|---|---|
| `trade.customer.fields` | collect | `customerIds` (the customers on the page), `dataSource` | `['fields' => [['name', 'label', 'type' (text, number, textarea, select), 'help', 'default', 'min', 'max', 'step', 'options' (value => label, for a select)]], 'values' => [customerId => [name => value]]]`. Field names are lower-case letters, digits and underscores, so prefix yours; the first service to claim a name keeps it |
| `trade.customer.fields.validate` | collect | `customerId` (0 for a new customer), `values` (name => text), `dataSource` | an error message, a list of them, or nothing. The first message stops the save |
| `trade.customer.fields.save` | dispatch | `customerId`, `values`, `dataSource` | none; fired after the customer is saved, also for a new customer |

A listener that throws is logged and skipped, so a broken validator lets the save through.

## Less Annoying CRM adapter

Needs the **Less Annoying CRM** service. With it chosen as the source:

- a form submission becomes a pending application;
- a sale at the Won status approves the contact as an active customer; a sale at the Lost status suspends them;
- a contact update changes the details of a contact who is already a customer;
- *Import from Less Annoying CRM* on the customers page creates a customer for everyone at the Won status. It leaves existing customers alone and can be run again.

How LACRM fields map to customer columns (form labels such as `company name`, `main company phone`, `delivery address (if different to the above)`) was written for one site's form and is in `models/TradeLacrmMapper.php`. Check it against your own form. Trade names LACRM's hooks but never calls its code, and works with it not installed.

## Not covered yet

Trade Ordering (prices, orders, invoices, documents) and other sources such as HubSpot. A new source needs only to answer `trade.sources` and fire the events above, or be added as an adapter here.

## Tests

`php vendor/phpunit.phar --no-configuration tests` from this folder, using any PHPUnit 10 (no Composer).
