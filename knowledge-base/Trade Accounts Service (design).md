# Trade Accounts Service (design)

Status (2026-10-29): **built and installed on dev, v0.7.0** (views are theme-supplied; the customer pages are not built yet). Extracts the
account half of SolarGo2's `trade` module as a service, id `osmium-trade-accounts`, category business. The ordering half is the
separate [[Trade Ordering Service (design)|Trade Ordering]] service, v0.7.2. Part of [[Osmium Architecture]];
fed by the Less Annoying CRM service (`osmium-lacrm`) when LACRM is the chosen source.

## Why and the split

SolarGo2's `modules/trade/` is B2B accounts, passwordless login, trade pricing, draft orders, invoices and documents. Only
the first two stand alone. The rest read shop products, so they wait for `osmium-shop`.

| Service | Contains | Needs |
|---|---|---|
| **Trade Accounts** (this note) | customers and their status, admin, passwordless login, source setting, source adapters | nothing |
| **Trade Ordering** (built) | trade pricing and discount, draft orders, order PDFs and emails, Trade Orders admin; customer pages wait for the theme | Trade Accounts, `osmium-shop` |
| **Trade Downloads** (built, [[Trade Downloads Service]]) | product documents and image ZIPs behind trade login | Trade Accounts, `osmium-shop` |

Trade Accounts must work on a site with no shop and no CRM.

## Source-agnostic by design

Where customers come from is a setting, like the active mail provider. With **None**, an admin adds and approves customers
by hand. Four neutral events describe everything a source can say:

| Neutral event | Meaning | Status set |
|---|---|---|
| `trade.application.received` | someone applied | pending |
| `trade.customer.approved` | accepted (or reactivated) | active |
| `trade.customer.suspended` | blocked for now | suspended |
| `trade.customer.updated` | details changed at the source | unchanged |

Payload: a normalised `customer` (email, company_name, contact_name, phone, address fields, `source`, `source_ref`) and
`dataSource`. Trade acts only on events whose `source` is the selected one. A source service may fire these directly,
which needs no change to Trade.

**Adapters live in Trade, not in the source service.** The source service (LACRM) stays a plain connection and never learns
Trade's vocabulary; Trade holds one small adapter class per known source. The LACRM adapter listens to `lacrm.form.submitted`,
`lacrm.sale.won`, `lacrm.sale.lost` and `lacrm.contact.updated`, maps fields (today's `TradeLacrmMapper`) and applies the rule
"sale won means approved, sale lost means suspended". Trade names LACRM's hooks but never calls its PHP and works without it
installed. If strictly zero coupling is ever wanted, an adapter can move into a tiny bridge service
(`osmium-trade-lacrm`) that depends on neither.

The settings page lists **None** plus each adapter whose source service is installed. A source may also answer
`trade.sources` (`{id, label, ready, settingsRoute}`) to appear without an adapter in Trade.

## What the service owns

- **Tables** (all `{PREFIX}`, names included): `trade_customers` (as SolarGo2 today, plus `source` and `source_ref` replacing
  `lacrm_contact_id`), `trade_magic_links`, `trade_otp_codes`, `trade_login_attempts`.
- **Status flow:** pending, active, suspended, closed. Only active customers can log in; refusals look like success so
  addresses cannot be probed.
- **Login:** emailed magic link (30 minutes), then a 6-digit code (5 minutes, 3 wrong codes abandon the login, resend once a
  minute). 1 link request per minute per email and 10 per 15 minutes per IP; 10 failed attempts lock for an hour. The OTP
  step is a setting. Mail goes through the core mailer.
- **Admin:** a Trade Customers list (standard ListView), customer edit, status actions, Login as customer (skips the code),
  and a settings page: Customer source, OTP on/off, lockout limits.
- **Bulk import:** the LACRM Import page is an adapter feature, not core Trade: "pull everyone at the Won status". It uses
  `lacrm.sales.won` and `lacrm.contacts.get_many`, and appears only when the LACRM adapter is selected.

## Hooks

Answers: `trade.application.received`, `trade.customer.approved`, `trade.customer.suspended`, `trade.customer.updated`,
`trade.customer.current` (handle, 0.7.0; returns the logged-in customer as `id`, `company_name`, `contact_name`, `status`, or null; how
other services ask "who is this?" without calling Trade's PHP; Trade Downloads uses it, Trade Ordering will). Fires: `trade.customer.status_changed` (dispatch, `customerId`, `from`, `to`) so later services can
react. Also built, beyond the first design: `trade.sources`, and the extra-customer-fields hooks (below). All are in [[Hooks Reference]].

## Public pages: two findings, decided 2026-10-29

Public pages (`/trade`, `/trade/login`, `/trade/otp`, `/trade/verify`) would be ordinary Pages rows, created on
`service.installed` and answered through `page.claim`, exactly as the Blog does. POSTs (login, OTP, logout) go through
`api/trade-*` routes. Checked in core on 2026-10-29:

1. **A claim cannot redirect.** `claimPageForService()` catches every `\Throwable`, and `Header::redirect()` throws
   `ResponseSentException` (an `\Error`), so a redirect inside the claim is swallowed and the visitor gets a 404. Plan: a guest
   asking for a protected page is given the login view in place of a redirect. (The alternative is a small core change to
   rethrow `ResponseSentException`; it ripples to every site, so avoid it.)
2. **Claimed views belong to the theme.** The claim names a view relative to the site's module path, and "the theme supplies
   the views" (the Blog works this way). A service cannot ship its own markup for a claimed page today. So login, code entry
   and the account dashboard markup would live in each theme, with the service handing over the data, and every new site's
   theme must write them. SolarGo2's views (`trade/views/login.phtml`, `otp.phtml`, `dashboard.phtml`) are the starting point.
   **Decided (Gavin): theme-supplied views, like the standard shop.** No core change; the service documents the data each view
   receives. The alternative, a core change letting a claim name a service-owned view, was rejected.

## Built beyond the first design

- **Extra customer fields:** another service adds fields to the customer edit form through `trade.customer.fields`, `.validate` and
  `.save` (field types text, number, textarea, select with an `options` map). Trade stores nothing for them. Trade Ordering uses it
  for the discount % and payment terms. A listener that throws is logged and skipped, so a broken validator lets the save through.
- **Login as customer** (admin only, skips the code), a Logo per customer, a bin with restore and purge, and a Recent Changes list.
- **LACRM adapter and import:** the import button is "Check for new customers" (it previews, then creates a customer for everyone
  at the Won status; existing customers are left alone and it can be run again). Lost suspends. See [[Less Annoying CRM Service]].
- **Email samples:** the login link and the verification code are in the Email settings test list (`trade.login_link`,
  `trade.login_code`), in the site's email styling with the logo.
- **Order of tables:** `trade_customers` also holds a `logo_filename`, soft delete (`deleted_at`) and lock columns for the login lockout.

## Not in this service

Pricing and discount, orders, invoices (Trade Ordering); documents and image downloads (Trade Downloads). Multi-currency (UK-only). A trusted-device
bypass (a core candidate).

## Build order (all five steps done, 2026-10-29)

1. Tables, model, status flow, admin list and edit, settings (source None).
2. Login: magic link, OTP, lockout, pages, theme view contract.
3. Neutral events and the source setting.
4. LACRM adapter and the bulk import.
5. Tests (pure logic first, as LACRM), Store install check on a real site.
