# Trade Accounts

Trade customers for an Osmium site: a customer list with a status workflow, an admin to manage it, and passwordless login (an emailed link, then an optional emailed code). Where customers come from is a setting, so the service does not assume any one CRM.

Pricing, orders, invoices and downloads are not here. They belong to a later Trade Ordering service, which needs the Shop.

## What it does

- **Customers** in Admin > Trade Customers: add, edit, status (pending, active, suspended, closed), a Logo per customer, delete and restore, and a bin. An admin can use *Log in as customer* on an active customer to see their account.
- **Login** for customers at `/trade/login`: they enter their email, get a link, press Continue, then enter a 6-digit code (turn the code off under Settings > Trade Accounts). Only active customers can log in; the same screen is shown for an unknown email, so addresses cannot be probed.
- **Spreadsheet import** from a CSV or Excel (.xlsx) file, with no CRM needed (see below).
- **Customer source** under Settings > Trade Accounts: *None* (you add and approve customers yourself) or any installed source, such as the Trade LACRM Sync service. The customer table is the one source of truth: everything else in the trade area (login, orders, documents) works from it and never from a CRM.

## Pages and theme views

The service creates four pages (`trade`, `trade/login`, `trade/verify`, `trade/code`) and answers core's `page.claim` hook for them. **The theme supplies the views**, under the site's `trade/views/`. Every form needs a hidden field `token` holding the site's usual `$template['formToken']`.

| View | Data | Notes |
|---|---|---|
| `login.phtml` | `$tradeLogin`: `action`, `honeypot`, `error`, `sent`, `email`, `protection` | Field `email`. The honeypot is a hidden field that must stay empty. Echo `protection` inside the form: it is any installed bot-protection widget, empty when none |
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

Trade announces `trade.customer.status_changed` (`customerId`, `from`, `to`, `dataSource`) whenever a status changes, and collects `trade.sources` answers (`id`, `label`, `ready`, `settingsRoute`, and optionally `importRoute`, the admin route of the source's own import page, which becomes the customers page's *Import from ...* button) to fill the source setting.

So a source service never needs Trade's PHP, Trade answers three questions (`handle`; each payload carries `dataSource`):

| Hook | Payload | Answer |
|---|---|---|
| `trade.customer.lookup` | `source` and `sourceRef` (the source's own id for the customer), or `email` | `id`, `status`, `company_name`, `email`, `source`, `source_ref` of the live customer, or null |
| `trade.source.selected` | none | the source id chosen in settings, or an empty string when customers are added by hand |
| `trade.customer.check` | `customer` (as an event carries it) | `valid` and `problem`: whether the details pass the same rules as the admin form and the events |

## Extra customer fields

Another service can add its own fields to the customer edit form and keep the values itself. Trade shows the fields, checks them with the service, and passes the values on. Trade stores nothing for them.

| Hook | Kind | Payload | Answer |
|---|---|---|---|
| `trade.customer.fields` | collect | `customerIds` (the customers on the page), `dataSource` | `['fields' => [['name', 'label', 'type' (text, number, textarea, select), 'help', 'default', 'min', 'max', 'step', 'options' (value => label, for a select)]], 'values' => [customerId => [name => value]]]`. Field names are lower-case letters, digits and underscores, so prefix yours; the first service to claim a name keeps it |
| `trade.customer.fields.validate` | collect | `customerId` (0 for a new customer), `values` (name => text), `dataSource` | an error message, a list of them, or nothing. The first message stops the save |
| `trade.customer.fields.save` | dispatch | `customerId`, `values`, `dataSource` | none; fired after the customer is saved, also for a new customer |

A listener that throws is logged and skipped, so a broken validator lets the save through.

## Spreadsheet import

With no customer source selected, the Trade Customers page shows **Import from spreadsheet**. Choosing a source that has its own import (such as Trade LACRM Sync) replaces that button with the source's own.

It is a three-step wizard (File, Columns, Check) and nothing is saved until the final **Import** click:

1. **File.** A `.csv` or `.xlsx` file with headings in the first row (first sheet only; up to 5,000 rows and 10 MB; split bigger files). The upload is kept in `app/cache/trade-import/` under a random name and is deleted when the wizard closes or the import finishes, and anything older than 30 minutes is swept away.
2. **Columns.** Each customer field is matched to a column of the file. Headings are guessed. Company name and email must be matched, and a column can be used once. A single *Full address* column goes whole into address line 1, with a UK postcode at the end moved to the postcode field and a non-UK country name at the end (from the `intl` extension's country list) moved to the country field.
3. **Check.** A sample of the rows as they will be saved, with a count and an Active or Pending choice.

No customer is dropped for being incomplete or odd:

- A missing company name uses the contact name, or "Unknown company".
- A missing or unusable email, or one already used by a different customer, gets a placeholder `no-email-<hash>@placeholder.invalid`, and the notes record the original. Phone number is the lead for sorting it out afterwards.
- A bad second email is left out; a value longer than its field is cut and its full text goes in the notes; a bare 10-digit number loses its leading 0 in Excel, so the 0 is put back.
- A row is skipped only as a true duplicate: the same email and the same phone, or the same email and the same company, as an existing customer or an earlier row. So importing a file twice adds nothing the second time.

Every default shows as a badge on the row in the Check step, with the reason on hover. The routes are `admin/trade/customers/import` and `admin/trade/customers/import/action`. Nothing here is specific to any CRM.

## Customer sources

Trade Accounts holds no CRM code. A source is a separate service that fires the four events above and answers `trade.sources` (with an optional `importRoute`, the admin route of its own import page, which replaces the spreadsheet import button). For Less Annoying CRM that is **Trade LACRM Sync** (`osmium-trade-lacrm`), which also adds the import page. A new source, such as HubSpot, is another service of the same shape.

## Not covered yet

Trade Ordering (prices, orders, invoices, documents) and other sources such as HubSpot. A new source needs only to answer `trade.sources` and fire the events above, as its own service.

## Tests

`php vendor/phpunit.phar --no-configuration tests` from this folder, using any PHPUnit 10 (no Composer).
