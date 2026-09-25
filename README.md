# Outline Media – Client Website Monitoring & Management CRM

A fast multi-tenant website-monitoring SaaS (v3.0) – self-service workspaces with plans and trials on top of the original CRM for managing all clients and their websites from one dashboard:
website uptime, **page-level monitoring of every page**, SSL, domain & hosting expiry, form testing, WordPress and
client login management, notifications, email alerts, reports and activity logs.

Built with Core PHP 8 (PDO), MySQL/MariaDB, Bootstrap 5, jQuery/AJAX, DataTables, SweetAlert2, Chart.js and PHPMailer.

---

## 1. Requirements

* PHP 8.0 or newer with extensions: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json` (all standard on cPanel). `imap` is optional (enables email-delivery verification for form tests).
* MySQL 5.7+ / MariaDB 10.3+
* Apache with `mod_rewrite` optional (not required) – `.htaccess` is used only for hardening.
* No cron job is needed – the application runs its own execution engine (section 4). Outbound HTTP to the server itself (loopback) and/or the ability to start a PHP CLI process make it faster; a plain shared host works without either.

## 2. Installation (local XAMPP or any server)

1. Copy the project folder to your web root (e.g. `htdocs/Client-Website` or `public_html/crm`).
2. Create a MySQL database and user.
3. Import `database/schema.sql` (phpMyAdmin → Import, or `mysql -u USER -p DBNAME < database/schema.sql`).
   **Upgrading an existing 1.4 database:** import `database/upgrade-1.5.sql` instead (removes the task module, adds page monitoring, credentials and WordPress login fields).
   **Then import the remaining upgrade files in order** – `upgrade-1.6.sql` … `upgrade-3.4.sql` (each is safe to run twice; `upgrade-3.4.sql` adds the job queue, workers and per-plan schedules – see 5k; `upgrade-3.2.sql` adds website analytics – see 5i; `upgrade-3.0.sql` adds the multi-tenant SaaS layer – see 5g, `upgrade-3.1.sql` the scheduler table and large-data indexes – see 5h). Fresh installs need them too: `schema.sql` carries the base tables, the upgrade files add the later form-testing, project, CAPTCHA and form-discovery columns.
4. Copy `config/database.sample.php` to `config/database.php` and enter your DB host, name, user and password.
5. Open `config/config.php` and change:
   * `APP_KEY` – any long random string (encrypts stored SMTP/IMAP passwords, WordPress passwords and client login credentials – **do not change it afterwards** or stored passwords cannot be decrypted).
   * `CRON_KEY` – secret used when cron scripts are called over HTTP.
   * `APP_ENV` – set to `production` on the live server.
   * `APP_URL` – leave empty to auto-detect, or set e.g. `https://crm.yourdomain.com`.
6. Make sure `logs/` and `uploads/` are writable by PHP (755 is usually fine on cPanel; 775/777 on some hosts).
7. Open the site in the browser and log in:

   | Field    | Value              |
   |----------|--------------------|
   | Email    | `admin@example.com`|
   | Password | `Admin@123`        |

8. **Immediately** go to *Team* and change the owner email/password, then *Platform → Settings → Email* to configure SMTP and send a test email.

## 3. cPanel deployment

1. cPanel → **File Manager** → upload the project ZIP into `public_html/crm` (or a subdomain's document root) and extract.
2. cPanel → **MySQL Database Wizard** → create database + user with ALL PRIVILEGES. Names will be prefixed (`cpuser_crm`, `cpuser_crmuser`).
3. cPanel → **phpMyAdmin** → select the database → **Import** → choose `database/schema.sql`.
4. Edit `config/database.php` with the prefixed DB name/user/password (File Manager → Edit).
5. Edit `config/config.php` → set `APP_KEY`, `CRON_KEY`, `APP_ENV = 'production'`.
6. cPanel → **Select PHP Version** → ensure PHP 8.x and the extensions `curl`, `openssl`, `mbstring`, `pdo_mysql` are ticked (`imap` optional).
7. Visit `https://yourdomain.com/crm/` and log in – the execution engine starts itself from this first request (no cron job to add).

The `.htaccess` in the project root blocks direct web access to `config/`, `includes/`, `vendor/`, `database/` and `logs/`.

## 4. Background processing – no cron job required

> **v3.9:** the application runs its own **execution engine**. After deployment you open the application once and monitoring
> runs from then on – per subscription plan interval, server-side, without a cPanel cron job, without a supervisor and without
> anybody keeping a dashboard open. Section 5p explains the three mechanisms (auto-spawned worker process, self-perpetuating
> request chain, inbound-request watchdog); Super Admin → **System Health** shows which one is active on your server, the
> environment facts behind it and a one-click loopback test.

All monitoring runs **server-side in the background**. It does not depend on anyone being logged in, on the browser being open or
on the dashboard being active. The engine:

* starts a detached worker process (`cron/worker.php`) whenever the web server may spawn processes, and restarts it when it dies;
* keeps a request chain alive: once a minute `cron/engine.php` runs one scheduler tick inside the web server and calls itself again
  before it exits (single chain, DB-locked, token-protected);
* restarts both from any inbound request – visitors of public pages, the analytics beacons your clients' websites send, API calls,
  uptime pingers or logged-in users – within ~1 ms at the end of that request.

**Optional extras (never required).** On a server you control you may additionally run `php cron/worker.php` under systemd /
supervisor, or point any external uptime monitor at `https://yourdomain.com/crm/cron/health-check.php?key=YOUR_CRON_KEY` (HTTP 200
while healthy, 503 when stalled – and every such request also restarts the engine). The scripts in `cron/` remain usable as external
triggers; locks and leases let every runner cooperate without double work.

The dashboard header shows **Monitoring live / paused**, every page shows a red **Monitoring System Warning** banner when the
scheduler has not ticked for longer than the configured limit (Settings → Monitoring, default 12 minutes), and the Alert History
records that as a system alert which the next successful tick closes.

**Local Windows / XAMPP:** nothing to do – the engine starts from the first page request (PHP CLI is detected under `xampp\php`).
The older Task Scheduler job (`cron\windows-task.bat`) is optional; remove it with `schtasks /Delete /TN "OutlineMediaCRM-Monitor" /F`.

## 5. How monitoring works

Every 5 minutes: **check website → check ALL website pages → check ALL configured forms → check SSL (when due) → store results →
detect any failure → send email alert → show the alert in the CRM.**

* **Uptime** – each active website is requested with cURL (follows redirects, verifies SSL). Status becomes
  `online`, `redirecting` (final host differs), `down` (4xx / connection refused / DNS), `server_error` (5xx),
  `timeout`, `ssl_error`, `parked` (the domain answers HTTP 200 but shows a parking/placeholder page such as a GoDaddy
  lander, "domain for sale", default Apache/nginx/cPanel page or suspended-account page – JavaScript redirects are followed)
  or `content_error` (the optional *Expected text on homepage* was not found). Failures are retried
  (Settings → Monitoring → Retry attempts) before an incident is opened.
  One *Down* email is sent when the outage starts and one *Recovered* email when it ends – never repeated in between.
* **Page-level monitoring (all pages, not just the homepage)** – `cron/check-pages.php` builds a list of the internal pages of every
  website (**robots.txt `Sitemap:` lines, `/sitemap.xml`, `/sitemap_index.xml`, WordPress `/wp-sitemap.xml`** – sitemap indexes are followed,
  image/video/tag/author sitemaps skipped – **plus the links on the homepage** and, without a sitemap, on the first pages found). Admin,
  login, feed, cart, tag/author archives, query-string variants and file downloads are skipped; extra patterns can be ignored in
  Settings → Monitoring; pages can also be added or removed manually on the website page (manual pages are always kept). The list is
  capped (default 50 pages, per-website override) with the homepage and the important pages first (about, contact, services, products,
  projects, gallery, blog, privacy, terms…) and re-discovered every 24 h. Every cycle **every page is requested concurrently**: HTTP status,
  connection, response time, timeout, server errors, SSL errors, redirects and placeholder pages are checked and stored per page. A page
  that stops working opens a *page incident* and sends **one "Website Page Error" email** (client + admin + assigned staff) naming the exact
  page, URL, HTTP status, reason, detected time and last successful check; while it stays down nothing is repeated; when it works again a
  **recovery email** is sent and the recovery time recorded. If more than N pages fail in the same scan (Settings, default 3) one combined
  email lists them all, and while the whole website is down the *Website Down* alert covers every page (no per-page emails). Each website
  shows **Website Health: GOOD / WARNING / FAILED**, "24 / 25 Pages Working – 1 Page Failed", last full scan and next scan; clicking the
  failed count lists the exact failed pages.
* **SSL** – the certificate is fetched over TLS every 12 hours, verified against the host name, and expiry, issuer and days-left are stored.
  Alerts fire at each configured threshold (default 30/10 days) and when expired/invalid – once per threshold per certificate. SSL
  handshake failures are also caught on every 5-minute check (`ssl_error`).
* **Failure reasons** – every failed check stores a short reason (DNS Resolution Failed, Connection Timeout, Connection Refused,
  SSL Certificate Error, Server Error / Service Unavailable, Page Not Found, Parking / Placeholder Page, Expected Content Missing…)
  plus the exact error text, HTTP code and response time.
* **Forms** – every 5 minutes (global interval, per-form override) each enabled form is tested: the page must load and contain a
  `<form>`; if a *test payload* is configured the form is submitted with test data (email = the configured test address, never a
  real customer), hidden nonce/CSRF fields are picked up from the page, and the response is validated against the expected HTTP
  code, expected redirect URL and expected success message. Emails go out only on WORKING → FAILED and FAILED → WORKING.
  Test submissions carry the header `X-CRM-Form-Test: 1` and a `CRMTEST-xxxx` token so client sites can filter them.
* **Expiry** – domain and hosting records alert (admin + client) at each configured threshold, by default 30 and 10 days before
  expiry, and again when expired. Each notification is sent once and tracked in `alert_log`.
* **Emails** – all alerts are queued in `email_queue` and delivered by `process-notifications.php` (and at the end of every other cron run).
  Recipients: the admin notification address(es) (or all active admins), the staff member assigned to the client, and the client's
  email (Settings → Email → "Allow client notifications", plus the per-client "Email client on alerts" switch, both on by default).

## 5a. Client control centre, WordPress logins and credentials

* **Client page** (`clients/view.php`) shows everything about a client in one place: client information, websites (status, technology,
  pages working / failed, SSL, forms, last scan), **WordPress Login** (URL, username, masked password), **Login Credentials** (hosting,
  domain, cPanel, FTP, email, database, other – admin only), domains (provider, expiry, auto-renewal, days remaining), hosting (provider,
  plan, login reference, expiry, days remaining), SSL per website, and a monitoring section with website health, page health (failed pages
  first), form health and monitoring history (page scans + incidents), plus forms, alerts and activity.
* **WordPress** – when a website's technology is *WordPress*, the WordPress login URL, username and password are **mandatory**; the website
  cannot be saved without them (validated in the browser and on the server). For other technologies the fields are optional.
* **Passwords** are AES-256 encrypted with `APP_KEY`, never included in page HTML, masked in the UI and revealed on demand (show/hide, copy)
  by **administrators only**; every reveal is written to the activity log.

## 5b. Client Care email system (rewritten in v3.6)

* **One central mailer** – `includes/Mailer.php` is the only code that sends email: PHPMailer over authenticated SMTP
  (SSL / STARTTLS / plain). PHP `mail()` is never used. Configuration lives in **Super Admin → SMTP / Email → SMTP settings**
  (host, port, encryption, username, password, certificate verification, timeouts, back-off, From / Reply-To, notification
  recipients, enable switch). The password is AES-encrypted with `APP_KEY`, never displayed, never logged.
* **Controlled timeouts** – TCP connect (`smtp_connect_timeout`, default 10 s) and every SMTP command / reply
  (`smtp_timeout`, default 20 s). PHPMailer's own per-command limit of 300 s is overridden and PHP's execution limit is
  raised for the duration of the SMTP conversation, so a stalled or blocked server fails within seconds with a real error
  instead of a white page / endless spinner.
* **Circuit breaker** – a connection / DNS / TLS / auth / timeout failure opens a back-off (`smtp_backoff_minutes`, default 5,
  growing with consecutive failures). While it is open the queue is not attempted (worker, cron, heartbeat *and* the inline
  flushes in the APIs all respect it), a running batch stops at the first transport failure, and failed rows are retried
  with exponential back-off (`email_queue.next_attempt_at`). Web requests flush the queue with a 12 s budget at most.
* **Real service state** – `email_service_state` (single row) holds last check / last successful and failed connection /
  last successful and failed email / error kind / server response / back-off. `Mailer::status()` derives
  🟢 *SMTP Connected* (a real connection or accepted email exists and nothing failed since) · 🔴 *SMTP Connection Failed* ·
  🟠 *SMTP Configuration Incomplete* · ⚪ *Not tested yet* / *Disabled*. Nothing is ever shown as connected without a real
  server response.
* **Test SMTP** – `Mailer::diagnose()` runs configuration → DNS → TCP → TLS/SSL → EHLO → STARTTLS → AUTH → a real test email →
  QUIT, each step timed with its own detail and server reply, classifies the failure (DNS / connection / timeout / TLS /
  auth / rejected) and gives the matching fix (blocked port, wrong port-encryption pair, App Password, self-signed
  certificate, From not allowed…). The button can test the *unsaved* form values and never hangs (client-side cap =
  3 × connect + 5 × command timeout + 25 s).
* **Sender identity** – every email is sent as the configured From (default `Outline Media Client Care
  <clientcare@outlinestudio.in>`) with matching Reply-To and envelope sender; clients are only To / CC / BCC. Optional DKIM.
  The diagnostics panel checks MX / SPF / DMARC of the sender domain.
* **Templates** – **Super Admin → Email Templates** (`platform/templates.php`): Website Down / Recovered, Page Error / Recovered,
  Form Failed / Recovered, SSL Expiry / Failed / Recovered, Domain Expiry, Hosting Expiry. Plain-text bodies with
  `{{variables}}`, preview with sample data, reset to default.
* **Queue + logs** – alerts go into `email_queue` (3 attempts, back-off) and are delivered by the worker / cron / web
  heartbeat. `email_logs` records every attempt with recipient, sender, subject, type, template, status, error kind, error,
  SMTP response, Message-ID, duration, queue id and attempt. **Super Admin → SMTP / Email → Email logs** is a server-side
  table (filter by status / failure kind / type / client / date) with a detail view (all attempts, message body) and
  re-send; the **Queue** tab shows pending / retrying / failed with retry-all, process-now and clear.
* **Deliverability** – publish SPF, enable DKIM at the provider and add DMARC for the sender domain.
## 5c. Performance & scalability

* **Server-side lists** – every large table (clients, websites, health, pages, uptime, forms, domains, hosting, activity, email logs, users)
  is served page by page from `api/datatables.php`; the browser receives only the visible rows.
* **Type-ahead selects**, **caching** (`includes/Cache.php`: APCu or files, 20–120 s, invalidated on writes), **indexes & full-text search**.
* **Bounded history** – raw uptime checks are kept for `retention_monitoring_days` (default 7) and rolled up daily; per-page history and scan
  summaries are kept for `retention_page_history_days` (default 30); page history rows are only written on status changes and failures.
* **Concurrent monitoring** – websites and pages are probed with `curl_multi` (Settings → *Parallel website checks* / *Parallel page requests*).
  `check-pages.php` processes 20 websites (≈ up to 1,000 pages) per concurrent pass; very large fleets can run several copies with
  `--shard=1/4 … --shard=4/4` and `--limit=N`.
* **Front end** – compression and one-year caching for versioned assets via `.htaccess`, preloaded fonts, notification polling only every 2 minutes.

### Production server settings

* PHP: copy the values from `config/php-production.ini` (OPcache on, `memory_limit 256M`); enable the `apcu` extension if offered.
* MySQL/MariaDB (`my.cnf`, VPS only): `innodb_buffer_pool_size` = 50–70 % of RAM, `innodb_flush_log_at_trx_commit = 2`.
* Apache: `mod_deflate` or `mod_brotli`, `mod_expires`, `mod_headers` (all standard on cPanel). Use PHP-FPM rather than suPHP/CGI.

## 5d. Website Projects (project management, v1.7)

**Website Projects** (sidebar → Website Projects) tracks every website you design/build or take over, separately from
monitoring but linked to it. Upgrade an existing install with `database/upgrade-1.7.sql` (fresh installs get it from `schema.sql`).

* **Add Website Project** – pick an *Existing Client* (type-ahead) or *Add New Client* (name, company, email, phone, WhatsApp).
  A new client is created and linked automatically; a client with the same email or name is reused, never duplicated.
* **New Website vs Existing Website** – new projects start at **Design**; an existing website defaults to **Live** and can
  be put under monitoring immediately (tick *Enable website monitoring now*).
* **Fields** – project name, website name, website URL, department/industry, website type, technology (HTML, PHP, WordPress,
  CodeIgniter, Laravel, React, Other), start / expected launch / actual launch dates, assigned person, notes.
* **Statuses** – Design → Development → Testing → Client Review → Changes Required → Ready for Launch → Live, plus On Hold and
  Cancelled. Change status from the row menu or the detail page (with an optional note); every change is stored in
  **Status History** (from, to, who, when, note). Moving to **Live** sets the actual launch date and offers to enable monitoring.
* **Monitoring link** – a project shares ONE record in `websites` with the monitoring module (matched by client + domain, so
  nothing is duplicated). Design/Development sites are **never** monitored automatically; use **Enable Monitoring** /
  **Disable Monitoring** (row action or detail page). Enabling runs the first uptime + SSL check straight away and the cron takes
  over (uptime, pages, SSL, forms). The row shows *Monitoring Active* / *Monitoring Disabled*. Deleting a project keeps the website.
* **Departments & Website Types** (admin, Website Projects → Departments & Types) – add / edit / activate / deactivate / delete
  industries (Education, Hospital, Healthcare, Real Estate, …) and website types, optionally tied to a department
  (Education → School, College, University…; Real Estate → Villas, Apartments…). Items in use cannot be deleted, deactivate them instead.
* **List page** – server-side DataTable (search, sort, paging, column visibility, print, CSV export via `api/projects.php?action=export`),
  clickable status cards, filters (status incl. *In progress*, new/existing, client, department, type, technology, assigned,
  start-date range, launch-date range, monitoring state) and **Clear Filters**. Quick actions: View, Edit, Change Status,
  Open Website, View Client, Enable/Disable Monitoring. Fully responsive (cards collapse, DataTables Responsive on phones).
* **Detail page** – project info, dates (days left / overdue), status timeline, live monitoring snapshot (website, SSL, pages, forms),
  status history and activity. **Dashboard** shows total / per-status counts (click to filter) plus department and website-type
  breakdowns; the **client page** has a Projects tab; **global search** finds projects.
* Roles: `admin`/`manager` manage projects, `staff` can view; only `admin` manages departments and types.

## 5e. Design / reference URLs and CAPTCHA-aware form testing (v1.9)

Upgrade existing installs with `database/upgrade-1.9.sql` (safe to run twice).

**Design & reference links per website** – Edit Website → *Design & References* tab: Figma Design URL, Adobe XD Design URL,
HTML / Static Demo URL, Other Reference URL (all optional, validated as URLs). The website page shows an
*Design & Reference Links* card with **Open Figma / Open Adobe XD / Open HTML Demo / Open Reference** buttons (new tab) and the
same links in the ⋯ menu.

**CAPTCHA / anti-bot handling in form monitoring** – the CRM never bypasses, defeats or solves a CAPTCHA.
* Before submitting, both engines detect Google reCAPTCHA v2/v3, hCaptcha, Cloudflare Turnstile, plain CAPTCHA / security-code
  fields and anti-bot plugins (CleanTalk, WP Armour, Antispam Bee…). A protected form is reported as
  **BLOCKED BY CAPTCHA** (form status *CAPTCHA Blocked*), the page/form availability is still verified, no submission is made.
* After a submission, responses such as `{"success":false,"errors":{"captcha":"The Captcha field cannot be blank."}}`,
  "please complete the captcha", "flagged as spam / anti-bot" are classified as **blocked**, not as a form failure.
* Every test gets one precise outcome: **WORKING**, **WORKING · EMAIL DELIVERY UNKNOWN** (accepted, mailbox not verified),
  **FAILED**, **TIMEOUT**, **JAVASCRIPT ERROR**, **NETWORK ERROR**, **BLOCKED BY CAPTCHA**, **BLOCKED BY THIRD-PARTY WIDGET**,
  **CONFIGURATION ERROR**. The Test-now dialog shows Mode, HTTP, response time, result, reason, CAPTCHA detected, success
  message / details, action, email received and last successful test, plus the step log; Test History shows the same.
* Alert logic: blocked and configuration-error tests create an in-app notice once and **never** send a Form Failure Alert
  email; HTTP 5xx / handler errors / timeouts / JS errors do. Blocked tests do not open incidents or count as failures.
* Chat widgets / cookie banners (Tawk.to, Crisp, Intercom, Tidio, HubSpot, WhatsApp…) are listed in the step log and ignored;
  their network requests never count as the submission. If a widget covers the submit button and nothing happens after the
  click, the test is reported as *Blocked by third-party widget*; if the form still works it stays *Working* with a warning step.
  JavaScript exceptions from the site's own scripts that stop the submission are reported as *JavaScript Error*.
* Form → Automated Testing → **CAPTCHA / Anti-Bot Protection**: type (None / reCAPTCHA / hCaptcha / Turnstile / Other) and the
  approved test method – *Report as blocked* (default), *Owner-approved test / staging page without CAPTCHA* (submission tests
  use that page) or *Owner-approved test parameter* (a `name=value` allow-listed by the site owner is added to the test
  submission; if the handler still rejects it the test is blocked, never bypassed).
* Form Monitoring has a *CAPTCHA Blocked* card / filter; the dashboard shows the count when it is non-zero;
  `cron/check-forms.php` reports blocked forms separately.

## 5f. Automatic form discovery & monitoring (v2.0)

Upgrade existing installs with `database/upgrade-2.0.sql` (safe to run twice). After that you only add the **website** –
the CRM builds and maintains the form inventory itself:

```
Add Website → discover pages (sitemap / links) → fetch every page → detect every form (normal, hidden popup/modal,
AJAX, WordPress plugin, same-origin iframe, third-party embed) → open popup triggers in headless Chrome (when available)
→ fingerprint (no duplicates) → register in `forms` → tested every 10 min → failure / recovery emails
```

* **Where** – Website page → *Form Monitoring* card (Total / Working / Failed / CAPTCHA Blocked, last + next scan) →
  **Form inventory** (`websites/forms.php?id=…`): every form with page, type (normal / popup / AJAX / WordPress),
  technology (Contact Form 7, WPForms, Elementor, Gravity, Fluent, Custom PHP, JotForm…), fields, CAPTCHA, status,
  last check, selector / popup trigger / action details, recent scans, and **Scan Website for Forms** / **Test All Forms**.
  Forms → **Form Discovery** lists every website with its counts, last / next scan and a scan button.
* **When** – a quick HTTP discovery runs when a website is saved (with *Run first check*); the cron (`run-all.php`, or
  `cron/discover-forms.php` standalone) completes the full scan and re-scans every website every N hours
  (Settings → Monitoring → *Automatic form discovery*: enable, re-scan interval, pages inspected per site, browser pages
  per site, websites per cron run). Discovered forms are tested by the existing `check-forms.php` cycle.
* **What is detected** – `<form>` elements incl. hidden modal / popup containers (Bootstrap modals, Elementor popups,
  Popup Maker, lightboxes…) with their trigger, WordPress plugin forms and their stable ids, AJAX forms, forms inside
  same-origin iframes, third-party embeds (JotForm, HubSpot, Typeform, Google Forms, Tally, Calendly… inventory only),
  CAPTCHA / anti-bot protection, field names / types / labels, submit button, page title, technology. Search, login,
  comment, cart and filter forms are ignored on purpose. With headless Chrome the scanner also finds JavaScript-rendered
  forms, popups that open a few seconds after load, and clicks up to 6 popup triggers per page (buttons / links whose text
  or attributes point to a modal) to register the forms inside.
* **Inventory rules** – one record per form (fingerprint = plugin form id / DOM id / action path / field names); a form
  present on many pages (footer newsletter) is one record with all pages linked (`form_pages`). A manually added form on the
  same page and action is reused, never duplicated. Changed selectors / technology are updated automatically for discovered
  forms; manual configuration is left alone. A form missing from two consecutive complete scans becomes
  **REMOVED / NOT FOUND** (history kept, no more tests); if it reappears it is re-activated. Every website keeps
  form counters (total, working, failed, CAPTCHA blocked, removed, normal / popup / AJAX) and each form keeps first / last
  discovered, status history (`form_status_history`), failures, successes and total downtime.
* **Alerts** – WORKING → FAILED sends the Form Failure Alert (client, website, form, type, technology, page, reason,
  HTTP / AJAX response, detection time, last successful test) from `clientcare@outlinestudio.in`; FAILED → WORKING sends
  the recovery email; a continuing failure sends nothing until the state changes. CAPTCHA blocked and third-party embeds
  never trigger failure alerts.
* **Clean URLs** – pages that end in `.php` are probed once; when the site serves the extension-less URL (rewriting), the
  CRM displays and links the clean URL (`website_pages.clean_url`) without touching the real monitored URL.
* Manual forms still work exactly as before (Add Form / Edit) – the `auto` / `manual` badge shows the origin.

## 5g. Multi-tenant SaaS platform (v3.0)

Version 3.0 turns the CRM into a self-service monitoring SaaS. Existing data is migrated into workspace #1
(*Outline Media*, Agency plan); the first admin becomes its **Owner** and the **Platform Administrator**.

**Install / upgrade:** import `database/upgrade-3.0.sql` after the earlier upgrade files (safe to run twice), then
re-run nothing else – cron jobs and the SMTP configuration are unchanged. Set `platform_name`, `registration_enabled`,
`trial_plan` / `trial_days` under *Platform → Settings → General*.

**Public site & self-registration** – `/` (landing), `/pricing`, `/register`, `/login`, `/terms`, `/privacy`,
`/contact-sales`. Registration needs name, company, a real e-mail (syntax + MX check outside development, disposable
domains blocked), a strong password and the terms checkbox. The account is created **pending**, a verification link
(48 h, single use, stored hashed in `email_verifications`) is e-mailed from `clientcare@outlinestudio.in`; nothing
works until it is clicked (`/verify-pending`, resend with throttling). Verification activates the workspace and starts
the trial of the chosen plan (default Professional, 14 days). No payment processing yet: trials expire to the Free
plan automatically (housekeeping), paid plans are activated by the platform admin ("Activate plan" with an
invoice note) and customers can *Request upgrade* from `/billing`, which e-mails the platform admins.

**Plans & limits** – `plans` table (Free ₹0 · Starter ₹499 · Professional ₹999 "Most popular" · Business ₹2,499 ·
Agency/Enterprise contact sales). Limits (`max_websites/pages/forms/users/status_pages`, check intervals, retention)
and JSON `features` are enforced server-side by `Tenant::canAdd()`, `Tenant::feature()` and
`Tenant::interval()` (plan interval or global setting, whichever is larger) – in the add-website wizard, page
discovery, form discovery (extra forms are counted but not registered: "plan limit reached"), team invites, API keys,
webhooks and status pages. Usage bars with 80 % / 100 % warnings appear on the dashboard, sidebar and `/billing`.
Edit plans under *Platform → Plans*.

**Tenant isolation** – every customer-owned table has `tenant_id`; every query is scoped with `Tenant::id()`
(`own_or_404()` / `load_*()` helpers), foreign IDs return a branded **404**, roles are checked server-side and the
front-end never decides ownership. `Tenant::act()` switches the context for cron and platform "view as".

**Clean URLs** – `index.php` is a front controller (`.htaccess` rewrites everything except `/assets`, `/uploads`,
`/cron`). No `.php` appears in user-facing URLs: `url()`, `redirect()` and `CRM.url()` in JavaScript map file paths to
routes (`websites/view.php?id=5` → `/websites/5`, `auth/login.php` → `/login`, `users/index.php` → `/team`,
`notifications/index.php` → `/alerts`, `api/x.php` → `/api/x`, …). Old `.php` links are 301-redirected. Errors render
`includes/layout/http-error.php` (404 / 403 / 429 / 500 / 503 maintenance) and never expose PHP warnings, SQL or stack
traces in production.

**Customer workspace** – sidebar: Overview, Websites, Pages, Forms, SSL, Domains, Hosting, Incidents, Alerts, Reports,
Status Pages, Team, Settings, Billing. New website flow `/websites/add`: URL → validation → SSL → automatic page
discovery → automatic form discovery with a live progress screen; monitoring starts immediately. `/incidents` unifies
website / page / form / SSL incidents with the detected root cause ("Unknown / Unable to determine" when unsure).
*Check now* buttons are rate-limited per object (`manual_check_cooldown_seconds`) and return HTTP 429.

**Team & roles** – Owner (billing, account, everything), Admin (everything except billing/ownership), Manager
(manage websites/forms/domains/hosting/projects), Viewer (read-only), Notify-only (receives alerts, cannot log in).
Invitations (`team_invitations`, hashed token, 7 days) via `/team`; ownership transfer; e-mail change re-verifies.
Legacy `staff` users map to Viewer.

**Workspace settings** (`/settings`) – name, timezone, billing e-mail, extra alert recipients, white-label sender /
brand name (Business+), per-workspace SSL/domain/hosting thresholds (`tenant_settings` overlay over the global
settings), **API keys** (Professional+: `om_xxxxxxx_<40 chars>`, shown once, stored as SHA-256; REST API
`/api/v1/status|websites|forms|incidents|ssl|domains|hosting`, `Authorization: Bearer <key>`, per-key rate limit with
`X-RateLimit-*` headers) and **webhooks** (Business+: signed JSON POSTs, `X-Webhook-Signature`, 3 attempts, delivered
by `process-notifications.php`).

**Status pages** (Professional+) – `/status-pages` manages public pages at `/status/{slug}` (components, 30-day
uptime bars, forms, SSL, incident history, theme colour, logo, footer, optional custom domain; branding removable
on white-label plans).

**Platform administration** (`/platform`, platform admins only, separate navigation) – overview (customers, trials,
paid, websites/pages/forms monitored, open incidents, failed jobs, e-mail delivery), customers list and detail
(set plan / trial, extend trial, suspend / activate / cancel, notes, members, websites, subscription history,
activity, **View as customer** with a banner and audit log, delete workspace), plans editor, system monitoring
(workers, locks, app + PHP logs, engine status), e-mail delivery (queue + logs across all workspaces), global
settings (`/platform/settings`) and e-mail templates.

**Workers & scalability** – cron layout unchanged (`run-all.php` every 5 min = websites, pages, SSL, forms, expiry,
notifications, discovery). Due queries only consider active workspaces and respect plan intervals; per-website and
per-form jobs take short DB locks (`monitor_locks`) so overlapping runs never double-check; housekeeping (daily)
expires trials, prunes tokens/locks/deliveries and keeps logs for `log_retention_days` (30).

## 5h. Premium UI, Super Admin analytics and the scheduler/worker layer (v3.1)

**Install / upgrade:** import `database/upgrade-3.1.sql` (idempotent) after `upgrade-3.0.sql`. It creates `scheduler_jobs`,
adds ~30 indexes for large datasets (tenant + time based listings, charts, platform analytics) and the
`web_heartbeat_enabled` / `maintenance_mode` settings. Bump nothing else – `APP_VERSION` (3.1.0) cache-busts the assets.

**Design system** – `assets/css/app.css` is a token-based glassmorphism design system (colours, radii, shadows, motion and
type scale on `:root`). Typography: 16 px body / 32 px page title on desktop and laptop, 14 px / 24 px on tablet
(< 1200 px), 14 px / 18 px on phones (< 768 px); buttons, inputs, labels, tables and navigation inherit the body size.
Reusable components: glass cards, `stat-card` KPIs with animated counters (`count-up`), `hero-panel`, `health-ring`,
`usage-ring`, `list-card` rows, `timeline`, `empty_state()` and `skeleton_block()` helpers, `chart-box` containers,
premium modals (fixed header/footer, internal scrolling, never taller than the viewport), form states, password strength
meter, toasts and `reveal` / `stagger` entrance animations. `prefers-reduced-motion` disables every animation.

**Responsive tables** – below 768 px every table becomes a stack of cards: `app.js` copies the header text into
`data-label` on each cell (also after every DataTables draw), CSS renders label/value rows, the first column becomes the
card title and row actions move to the bottom. Nothing scrolls horizontally. Add `table-keep` to a table to opt out.

**Shell** – collapsible sidebar (state remembered per browser, icon tooltips when collapsed), grouped navigation
(Monitoring · Operations · Business · Workspace · Super Admin), off-canvas drawer on phones, sticky glass top bar with
Ctrl+K search, live "Monitoring live / paused" indicator, notification drawer with skeleton loading.

**Dashboards** – the workspace overview is a command centre: health hero (ring + headline numbers + quick actions),
KPI grid, charts loaded lazily from `api/stats?action=charts` (uptime & response time, website health donut, incidents
per day, form health donut, plan usage), "needs attention" cards (only when something is wrong), incident and activity
timelines and the monitoring-engine status. Chart data comes from the daily rollup and incident tables (never raw
monitoring rows) and is cached 5 minutes per workspace.

**Super Admin (platform admin)** – `users.is_platform_admin` is the master role: it can see and manage every customer,
user, website, plan, subscription, job, e-mail and setting, impersonate any workspace, toggle maintenance mode
(customers get the branded 503 page, Super Admins keep access) and open/close self-registration. `/platform` is an
analytics console (registered / active / inactive / unverified users, customers by plan and subscription, trials, paid
customers and MRR, growth per day, logins per day, monitoring volume, e-mail delivery, failed jobs) fed by
`api/stats?action=platform`; `/platform/users` lists every user with filters and last login; `/platform/system` shows the
three runners, every job's status and lets you reset or pause a job.

**Background processing without a hard cron dependency** – `includes/Scheduler.php` keeps the job registry
(intervals, priorities, DB locks in `scheduler_jobs`, retries, run/fail counters). Any of these keeps monitoring alive:

| Runner | How | Notes |
|---|---|---|
| External trigger (optional since v3.9) | `*/5 * * * * php cron/run-all.php` | thin entry point: runs every due job once, each in its own PHP process |
| Worker | `php cron/worker.php` (systemd / supervisor / `nohup … &`) | long-running loop, ticks every 30 s, restarts itself every 6 h, several workers/servers allowed |
| Web heartbeat | automatic | when no runner has ticked for 7 min, the unread-count heartbeat that every open tab sends runs one short, time-boxed tick *after* the HTTP response was flushed – the visitor never waits |

Jobs are locked atomically in the database, so overlapping runners never execute the same job twice; a crashed job's
lock expires automatically and is retried on the next tick. The header shows "Monitoring live / paused" and Super Admins
get a banner plus the System page when nothing is running. Set `web_heartbeat_enabled = 0` to disable the fallback.

**Performance** – all lists stay server-side DataTables (debounced search, pagination), dashboard numbers are grouped and
cached, charts load after the first paint with skeletons, header counters are cached 20 s, chart/analytics aggregation
uses the indexed rollup tables, and heavy work (checks, discovery, form tests, reports) never runs inside a page request.

**Verification** – scratchpad `ui-check.php` drives headless Chrome through every page at 1920×1080, 1366×768, 768×1024,
390×844 and 320×568 and reports HTTP status, horizontal overflow, elements outside the viewport, JavaScript errors and
text below the typography floor.

## 5i. Compact UI and Website Analytics (v3.2)

**Install / upgrade:** import `database/upgrade-3.2.sql` after `upgrade-3.1.sql` (idempotent). It adds the analytics
columns on `websites`, `plans.max_pageviews_month`, the `analytics` / `analytics_retention` plan features, and the
analytics tables. No cron changes: pruning runs inside the daily housekeeping job.

**Compact design system** – `assets/css/app.css` was rewritten as a light, flat, small-scale system: 13 px body text,
12 px table / secondary text, 11 px metadata, 20 px page titles, 14 px card headings, 18 px KPI values, 32 px buttons
and inputs, 10 px card radius, 214 px dark sidebar with amber active item, white top bar. Tablet and phone keep 13 px
body text with 18 px titles. Charts are small (170–200 px) with side legends (`chart-split` + `legend-row`), donuts
show the key percentage in the centre, KPI cards are single-row with a chevron, usage is rendered as `usage-row`
progress rows, empty states are compact, and tables stack into cards below 768 px. The dashboard follows the
reference layout: greeting row → health banner → KPI grid → Uptime / Website health / Incidents → Form health, Plan
usage, Recent incidents, Recent activity + Monitoring engine.

**Website Analytics** – privacy-friendly visitor analytics for every client website:

* One snippet per website: `<script async src="https://your-crm/t.js" data-site="KEY"></script>` placed once in the
  site `<head>`. It is ~2 KB, loads async, never blocks rendering, batches nothing to the visitor's detriment, fails
  silently, and tracks every page automatically (including single-page-app navigations via `pushState`).
* Collection endpoint `/collect` (POST, `text/plain` JSON, CORS `*`, no cookies, no session, always 204). Validation:
  site key, plan level, bot filter, origin must be the website's own domain (or "Allow any domain"), per-IP flood limit,
  monthly plan quota. The visitor id is generated in the browser (localStorage) and hashed with the site key on the
  server – **every device is counted once**; no IP is stored with events (the IP is only cached hashed for geo lookup).
* Data model built for scale: `analytics_events` (raw, kept for `analytics_raw_retention_days`, default 90) plus daily
  rollups written on the fly – `analytics_daily` (views, visitors, new visitors, sessions), `analytics_daily_dims`
  (country, city/region, device, browser, OS, source, referrer, language, campaign/UTM, hour, screen size) and
  `analytics_daily_pages` (views, entries). Dashboards read only the rollups (cached 60 s); live view, recent visitors,
  exit pages and the weekday × hour heatmap use the indexed raw window.
* Location: Cloudflare country header when present, otherwise a cached ip-api.com lookup (rate-capped), otherwise the
  visitor's timezone → country. Cities/regions are approximate; nothing more precise is stored.
* Pages: `/analytics` (workspace overview: totals, quota, chart, countries/devices/sources, per-website status) and
  `/websites/{id}/analytics` (KPIs with trend vs previous period: visitors, page views, sessions, new, returning,
  active now; traffic trend; devices + screen sizes; sources + referrers + campaigns; countries + cities; top pages;
  browsers/OS; landing & exit pages; activity heatmap; live view polling every 30 s; recent visitors; setup panel with
  copy button, **Verify installation**, own-domain switch, key regeneration, pause). Installation status: Not
  installed → Waiting for data → Active → No recent data / Paused.
* Ranges: Today, Yesterday, 7 / 30 / 90 / 180 days, 12 months and a custom date range, always clamped to the plan's
  history. CSV export on Business and above.
* Plans (editable under Platform → Plans): Free – basic analytics, 1,000 views/month, 7-day history · Starter –
  25,000 / 30 days · Professional – full analytics (cities, referrers, campaigns, heatmap, live view, custom range),
  250,000 / 90 days · Business – 2,000,000 / 180 days + export · Agency – custom. Usage meter with 80 / 90 / 100 %
  notifications; at 100 % new views are not recorded until the reset date (shown to the customer).
* Super Admin: platform analytics block on `/platform` (tracked websites, views/visitors, events stored, top websites,
  usage by customer vs plan limit) and global controls on Platform → Settings (feature on/off, geo lookup, proxy
  headers, raw retention).
* Tests: scratchpad `analytics-test.sh` (63 checks: snippet, beacons, dedupe, sessions, sources/UTM, rejections,
  report/realtime/custom range/export APIs, quota enforcement, plan gating, pause/regenerate, Super Admin, pruning).

## 5j. Dark master design system (v3.3)

**No database changes.** The login page is the master design system: the whole CRM (dashboard, all list and detail
pages, settings, billing, Super Admin console, public pages, error pages) now shares its dark charcoal + gold identity.
Backend PHP, AJAX, APIs, queries, auth, routes and monitoring logic are untouched – the redesign lives in
`assets/css/app.css` (one file, every colour / size / radius / shadow is a token on `:root`), `includes/layout/header.php`
(sidebar + top bar markup), `includes/layout/public.php`, the two error layouts and the Chart.js palette in `assets/js/app.js`.

* **Theme:** Bootstrap 5.3 runs with `data-bs-theme="dark"` and its variables are mapped to the tokens, so stock
  components (modals, dropdowns, tables, forms, alerts, list groups) inherit the look automatically. Canvas `#0b0b0b`,
  surfaces `#141414`–`#212121`, thin `rgba(255,255,255,.08)` borders, 12 px card radius, soft shadows. Gold `#FCAF17` is
  used only for brand, the active sidebar item, primary actions and highlights. Status colours: green healthy, red down,
  orange warning, blue info, grey neutral (`--success/--danger/--warning/--info/--neutral` + `-text/-soft/-line` variants).
* **Typography:** Inter (self-hosted in `assets/fonts/inter-latin*.woff2`, preloaded). Responsive scale with `clamp()`:
  body 13→14 px, page title 18→22 px, KPI 18→22 px, small 12 px, metadata 11 px (floor).
* **Layout:** content width 95 % on desktop (2.5 % gutters), 90 % on tablet/mobile (≤ 1024 px, 5 % gutters) via the
  `--gutter` token; no page-level horizontal scroll, wide tables scroll inside their card, phones use stacked card rows.
* **Sidebar:** compact 224 px dark rail – Overview, Websites, Pages, Forms, SSL, Domains, Hosting, Analytics /
  Incidents, Alerts, Reports, Status Pages, Activity / Clients, Website Projects, Team, Email Logs, Billing & Plan,
  Settings, Help & Support (+ Super Admin). Active item = gold background, dark text. "Current plan" is a tiny chip
  (`.plan-mini`) pinned at the very bottom; the version moved to the page footer.
* **Header:** 54 px sticky glass bar with breadcrumb/title, global search, monitoring status pill, notifications,
  profile + account dropdown.
* **Components:** primary buttons gold/dark text (32 px, `btn-sm` 28 px), secondary buttons glass with thin border,
  inputs dark with gold focus ring and red/green validation states, dark glass modals with blurred backdrop,
  SweetAlert2 dialogs and toasts restyled, DataTables dark (gold active page). Bootstrap light-only utilities
  (`bg-light`, `bg-white`, `text-dark`, `table-light`, `alert-dark`, `badge.bg-dark`) are remapped so old markup stays readable.
* **Verification:** scratchpad `ui-check.php --shots --all` audits every page at 320 / 375 / 390 / 414 / 768 / 820 /
  1024 / 1280 / 1440 / 1920 px (HTTP status, horizontal overflow, JS errors, type floor) and saves screenshots;
  `shot-overlays.php` captures the modal, account menu, notifications, mobile drawer and tablet views. The public landing page (`public/landing.php`) follows the same identity: hero pill, gold headline accent, scan-demo card with live stats, dotted wave, four-column feature grid, inline "How it works" steps and the pricing strip.

## 5k. Production scale: queue-based scheduling, workers, cron minimised (v3.4)

**Install / upgrade:** import `database/upgrade-3.4.sql` (idempotent). It adds the job queue (`jobs`), worker registry
(`workers`), per-queue liveness / throughput (`queue_state`, `queue_stats`), shared `sessions` and `cache_store` tables, and
per-target schedule columns (`websites.next_check_at / next_ssl_at / next_scan_at / next_discovery_at`,
`forms.next_test_at`) with the indexes the dispatcher needs. Existing rows keep their cadence – no burst after upgrading.

### Architecture

```
websites.next_check_at <= NOW()        (indexed, LIMIT 2000 per tick, plan interval per tenant)
websites.next_ssl_at / next_scan_at / next_discovery_at, forms.next_test_at
            │
            ▼  Scheduler::dispatch()  – cheap, locked, once a minute, any runner
        jobs table  (queues: notification · website · ssl · page · form · analytics · discovery · maintenance · report)
            │        one row per check · priority · available_at · lease · attempts · dedupe_key (never two jobs per target)
            ▼  Queue::claim()  – atomic UPDATE … LIMIT with a claim token, safe for any number of workers / servers
        workers  (php cron/worker.php, N processes, N servers, optional --queues=…)
            │        Monitor::checkWebsitesBatch (50 sites, 25 parallel) · checkSsl · scanPagesBatch · testForm · FormDiscovery · Analytics::processPending · system jobs
            ▼
        results / incidents → email_queue → notification job → SMTP        (never inside a user request)
```

* **Plan-based cadence.** The dispatcher sets `next_*_at = NOW() + Tenant::interval(kind)` the moment it queues a target,
  so Customer A on a 5-minute plan and Customer B on a 60-minute plan are dispatched at their own interval, whether or not
  anybody is logged in. Per target you have `last_*_at`, `next_*_at`, `check_job_id`, `check_attempts`; the Scheduler Health
  page shows last / next run per customer.
* **Locking, leases, retries.** A claim leases the job (`lease_until`, per-queue 3–30 min). If a worker dies, `Queue::reap()`
  and `Scheduler::reapWorkers()` (heartbeats in `workers`) re-queue its jobs automatically. Failures retry with exponential
  back-off (30 s → 15 min, `job_max_attempts`, default 3); exhausted jobs are dead-lettered (visible, retry/discard buttons)
  and never block the target's next scheduled run. A down website is a *result*, not a job failure.
* **Browser pool.** Form tests and discovery take one of `browser_max_concurrent` (default 2) platform-wide DB slots before
  launching headless Chrome; when none is free the job is released for 20 s without counting an attempt.
* **Cron minimised.** `cron/run-all.php` is now a lightweight heartbeat: dispatch + system jobs, and it processes the queue
  inline **only when no worker is alive** (`inline_fallback_enabled`). The web heartbeat does the same, bounded to ~40 s, after
  the HTTP response has been sent. Legacy `cron/check-*.php` scripts still work for manual runs.
* **Analytics ingestion.** `/collect` appends one raw row (`processed = 0`) when a worker is alive; the `analytics` queue rolls
  events up in batches of 2,000 (`Analytics::processPending`). With no worker the roll-up happens synchronously after the
  204 response, as before. Dashboards only read the daily / dimension / page summary tables.
* **Bulk "Check now"** from the dashboard queues priority-0 jobs and returns immediately (inline pass only without workers).
* **Horizontal scaling.** `SESSION_DRIVER = 'database'` (shared login state) and `CACHE_DRIVER = 'database'` (shared cache,
  including the `data` version tag that invalidates lists) in `config/config.php` let several app servers sit behind a load
  balancer. Workers on any server share the same queue; nothing is kept in local memory or files.

### Running it

| Mode | Command | Notes |
|------|---------|-------|
| **Recommended – persistent workers** | `php cron/worker.php` (systemd / supervisor, `autorestart=true`) | Dispatches every 30 s and processes all queues. Add processes: `--queues=website,ssl,page` for HTTP work, `--queues=form,discovery` for browser work. Restarts itself every 6 h. |
| External trigger (optional since v3.9 – the engine replaces it) | `*/5 * * * * php cron/run-all.php` | Dispatch + system jobs; inline processing only when no worker is alive. |
| Shared hosting | nothing | The web heartbeat (every visitor, after the response) dispatches and processes a short batch. |
| External monitor | `cron/health-check.php?key=…` | HTTP 200 while healthy, 503 when the scheduler is stopped / delayed. |

systemd unit (`/etc/systemd/system/crm-worker@.service`, start with `systemctl enable --now crm-worker@{1..4}`):

```
[Service]
User=www-data
WorkingDirectory=/var/www/crm
ExecStart=/usr/bin/php cron/worker.php
Restart=always
RestartSec=5
```

### Scheduler Health page (Platform → Scheduler & Workers)

Status **Running / Connected / Delayed / Stopped / Failed / Not connected** is derived from real execution (worker heartbeats,
last dispatch, queue lag), never from configuration. It shows last run, next run, dispatch duration, jobs processed / failed
(1 h, 24 h), pending and due per queue with the oldest waiting time, workers (host, pid, queues, heartbeat, done / failed),
customer schedules (plan interval, last / next run, queued), dead-lettered jobs with retry / discard, system jobs, engine
facts and the exact commands. A stopped scheduler shows a red banner on every Super Admin page and emails the platform
admins once per hour (`Notifier::cronStale`).

### Settings (Platform → System settings / `settings` table)

`dispatch_batch_limit` 2000 · `queue_claim_batch` 50 · `worker_stale_seconds` 120 · `browser_max_concurrent` 2 ·
`analytics_async` 1 · `job_max_attempts` 3 · `inline_fallback_enabled` 1 · `web_heartbeat_enabled` 1 · `check_concurrency` 25.

### Capacity (measured with scratchpad `scale-test.php`, 20,000 websites / 40,000 forms)

Measured on the development laptop (MariaDB 10.4 on Windows, default InnoDB settings) with 20,000 due websites:

| Operation | Result |
|-----------|--------|
| Dispatcher tick (2,000 website + 2,000 SSL + 2,000 page + 2,000 form + 200 discovery targets → 8,200 jobs, provisional reschedule) | ~4.5 s (~1,800 jobs / s) |
| Claim of 50 jobs (index walk on `idx_jobs_claim`, no filesort) | ~20 ms |
| Job completion (job row + hourly stats + queue state) | ~6 ms |
| Reaper pass / `health()` (indexed counts only) | 3 ms / ~200 ms |
| Dashboard stats for the 20,000-site tenant | 570 ms cold, 0 ms cached (pre-warmed by the notification job) |
| List page at offset 5,000 | ~240 ms |

One worker completes a 50-site HTTP batch in ~1.5 s (~2,000 checks / min). 1 million websites on a 5-minute plan =
~3,300 checks / s ≈ 100 HTTP workers (fewer with a higher `check_concurrency`) on any number of servers; the dispatcher
locks per kind, so several runners dispatch different queues in parallel, and a production database (NVMe, tuned
buffer pool) is typically 3–5× faster than the laptop figures above. The `jobs` table stays small because done rows are
pruned after an hour; daily uptime roll-ups, analytics summaries and retention pruning keep the history tables bounded.

### SEO / performance (public pages)

Canonical + Open Graph + Twitter + JSON-LD (Organization, SoftwareApplication, WebSite) in `includes/layout/public.php`,
`/robots.txt` and `/sitemap.xml` (dynamic, clean routes), public pages cacheable for 2 min (`Cache-Control: public`,
CDN-friendly), app pages `noindex` + `no-store`, deferred JS, preloaded font, fixed image dimensions (no layout shift),
gzip/brotli and immutable asset caching in `.htaccess`.

### Production readiness checklist

1. `APP_ENV = 'production'`, unique `APP_KEY` / `CRON_KEY`, HTTPS, SMTP configured, backups scheduled (mysqldump nightly + binlog).
2. Import all upgrade files; run `php cron/worker.php --once` and open Platform → Scheduler & Workers: status must be **Running**.
3. Workers under systemd/supervisor (2+ processes), cron heartbeat every 5 min, external monitor on `health-check.php`.
4. Multi-server: `SESSION_DRIVER`/`CACHE_DRIVER = 'database'` (or APCu per server for read-mostly caches), shared `uploads/` (NFS/S3), same `APP_KEY` everywhere.
5. Database: InnoDB buffer pool ≥ 50 % RAM, `max_connections` ≥ workers + PHP-FPM children, slow query log on, replicas for reporting when reads dominate.
6. Test suites: scratchpad `scheduler-test.sh`, `saas-test.sh`, `analytics-test.sh`, `projects-test.sh`, `scale-test.php`.

## 5l. Instant navigation and scheduler status (v3.5)

**No database changes.** The portal now behaves like a single-page app on top of the same PHP pages.

* **AJAX navigation.** Every internal link (sidebar, cards, table rows, breadcrumbs) is intercepted by `CRM.navigate()` in
  `assets/js/app.js`: a 2 px gold progress bar starts, the current content fades, the target is fetched with the
  `X-PJAX: 1` header, and only `<main>` is replaced. The sidebar, header, vendor scripts, styles and session stay in
  memory. `includes/layout/header.php` / `footer.php` answer PJAX requests with just the fragment (title, breadcrumb,
  active nav item, page content, page scripts – 25–40 % smaller than a full page and no shell rendering).
* **Page scripts stay correct.** Each page's inline scripts run again after a swap; handlers they bind on
  `document`/`window` are auto-namespaced (`.page`) and their timers tracked, so the previous page's handlers, polling
  intervals, DataTables, Chart.js instances, modals and backdrops are torn down before the new page boots (`CRM.boot()`).
  Vendor scripts (Chart.js, DataTables Buttons) load once per session.
* **History and deep links.** `pushState` per navigation, back/forward re-fetch the fragment, refresh and direct URLs
  render the full page as before. Links to auth, cron, API, downloads and external sites use normal navigation; add
  `data-no-pjax` to opt out of any link.
* **Errors stay inline.** A failed fetch or 404 shows a card with *Retry* / *Open normally* inside the content area; the
  rest of the application keeps working. `CRM.reload()` re-renders the current page in place (used after saves).
* **Prefetch.** Sidebar items and KPI / list cards are prefetched on hover (desktop) or first touch (mobile) and reused
  for 20 s. Global search and server-side table search are debounced (300 / 400 ms).
* **Never blocking on background work.** Monitoring never runs inside a page request; the header no longer sends email
  inline when the scheduler is stale (the next tick / web heartbeat delivers it). Dashboard numbers come from cached,
  pre-warmed aggregates; charts and tables load asynchronously after the shell.
* **Scheduler status card** (Platform → Scheduler & Workers, and Platform → Settings → Scheduler tab): 🟢 *Connected &
  Running* / 🟠 *Running but delayed* / 🔴 *Not Connected / Not Running*, decided only from real execution (worker
  heartbeats, last dispatch, queue lag, missed executions). Shows last successful execution, next expected execution, last
  heartbeat, jobs processed (1 h / 24 h), failed, pending / due, worker status, average execution time and scheduler
  errors; refreshes every 30 s through `api/platform.php?action=scheduler_health`.

**Measured (local, headless Chrome):** content swap 60–175 ms per navigation; server render 15–100 ms per page (dashboard
93 ms with 49 queries cold, 15 cached); PJAX fragment 54 KB vs 70 KB full page. Development builds write a per-request
profile to `logs/perf.log` and `?__trace=1` dumps every query of a request to `logs/trace.log`.

**Verification:** scratchpad `pjax-test.php` (desktop + `--mobile`: shell marker survives every navigation, titles /
active nav update, tables and charts initialise, handlers do not pile up, back / forward / soft reload / inline 404 /
deep link) plus `ui-check.php` for JS errors and overflow on every page.

## 5m. SMTP reliability and the Super Admin rebuild (v3.6)

`database/upgrade-3.6.sql` (re-runnable): `email_logs.error_kind / duration_ms / queue_id / attempt / from_email`,
`email_queue.next_attempt_at`, table `email_service_state`, `activity_logs.is_platform / target / result`, settings
`smtp_enabled / smtp_verify_peer / smtp_connect_timeout / smtp_timeout / smtp_backoff_minutes`.

**Why emails stalled / failed on the live server.** (1) PHPMailer waits up to 300 s for every SMTP reply; with a wrong
port–encryption pair, a firewall that silently drops outbound 465/587, or a server that stalls, the request hit PHP's
`max_execution_time` and died without JSON – the spinner never stopped and no error was logged. (2) Every "check now",
form test and website save flushed the queue inline; with the server unreachable each queued message waited for the
connect timeout, so ordinary pages took minutes. (3) Nothing recorded the real transport state, so the UI could only say
"configured". Section 5b describes the fixes (bounded timeouts, circuit breaker, diagnostics, persisted state).

**Super Admin (`/platform`) was audited and rebuilt around platform management.** Navigation: *Platform* (Dashboard,
Clients, Websites, Users) · *Billing* (Plans & Pricing, Subscriptions, Usage) · *Insights* (Analytics, Audit Logs) ·
*System* (SMTP / Email, Email Templates, System Health, System Settings).

* **Dashboard** – compact KPI row (total / active clients, total / active websites, subscriptions, active plans, page views,
  system status), Email Service card polled from the real state (`api/email.php?action=status`), *Needs attention* list
  (pending clients, trials ending, sites down, SMTP failures, failed emails, dead jobs), recent Super Admin activity, newest clients.
* **Clients** (`platform/customers.php`, server-side table `platform_clients`) – search / filter (status, subscription, plan);
  view, **edit** (name, status, billing email, phone, country, timezone, alert recipients, white-label name, notes),
  **activate / deactivate**, view-as-client, **delete** (typed-slug confirmation; removes users, clients → websites, pages,
  forms, subscriptions, settings, keys, webhooks, status pages, pending queue rows). The detail page adds usage vs limits,
  websites (edit / enable / disable / delete), members, plan & subscription controls, subscription history and activity.
* **Websites** (`platform/websites.php`, table `platform_websites`) – every website of every client: search / filter (client,
  status, monitoring, tracking); **edit** (name, URL, end client, technology, monitoring / page monitoring / form discovery,
  max pages, expected text, notes), **enable / disable**, tracking ID with **pause / enable / regenerate**, views this month
  vs plan, analytics (opens the workspace as that client), **delete**.
* **Plans & Pricing** – unchanged editor + delete (only unused plans); pricing changes are audited as `platform_pricing_changed`.
* **Subscriptions** (`platform/subscriptions.php`) – plan, price, status, start, trial end / renewal, billing email;
  change plan (active / trial), set subscription status (active / trial / past due / expired / cancelled / free), history.
* **Usage** (`platform/usage.php`) – websites / pages / forms / users / page views vs plan limits, events stored, retention;
  filter "at / near a limit". One grouped query per resource, no per-row loops.
* **Analytics** (`platform/analytics.php`) – the former dashboard charts (growth, plan mix, users, logins, checks, email
  delivery, 7 / 30 / 90 days) and the website-analytics platform block, loaded lazily.
* **Audit Logs** (`platform/audit.php`, table `platform_audit`) – every Super Admin action with user, action, target, result
  (ok / failed / denied), IP and details: client created / edited / deleted / activated / deactivated, website edited /
  deleted / enabled / disabled, tracking key regenerated, plan / pricing / subscription changes, SMTP changes and tests,
  settings, Super Admin logins, impersonation and refused operations. No secrets are stored.
* **Security** – every platform action requires `is_platform_admin` + CSRF (`require_post()`), validates input server-side,
  uses prepared statements, escapes output, confirms destructive actions (typed slug for client deletion) and refuses to
  suspend / delete the platform owner workspace (the refusal is audited as *denied*).

**Removed / merged (verified unused first with a project-wide reference scan):** `settings/templates.php` → `platform/templates.php`;
the Email tab of `platform/settings.php` and the old `platform/emails.php` list → the SMTP / Email module; the dashboard
chart page → `platform/analytics.php`; `.htaccess.bak-2.0`. `api/settings.php` lost `save_email` / `test_email`
(now `api/email.php`: `status`, `save_smtp`, `test_smtp`, `check_smtp`, `process_queue`, `retry_email`, `resend_log`,
`retry_failed`, `delete_queued`, `clear_failed`, `email_details`).

**Verification:** scratchpad `smtp-cli-test.php` (23 checks against a local SMTP sink, a refused port, an unroutable host,
a bad hostname, SSL on a plain port, STARTTLS not offered, bad credentials, a silent "black hole" server, the circuit
breaker and retry scheduling – all bounded by the configured timeouts), `platform-test.sh` (94 HTTP checks: every Super
Admin page, all server-side tables, the SMTP API incl. timing bounds, client / website / plan / subscription CRUD with audit
rows, CSRF and non-admin refusals) and `ui-check.php` (overflow / JS errors on the Super Admin pages at ten viewports).
## 5n. Analytics engagement, CAPTCHA-protected form status, Owner role and plan tiers (v3.7)

`database/upgrade-3.7.sql` (re-runnable): `analytics_daily.bounces / duration_sum / duration_n / events`,
`analytics_daily_pages.visitors / bounces / duration_sum / duration_n`, `analytics_sessions_daily.pageviews / landing_hash`,
table `analytics_page_visitors_daily`, `analytics_events.event`, `websites.analytics_first_event_at`,
`forms.last_page_ok / last_form_found`, `users.is_platform_owner`, plan feature keys `realtime / geo / max_events_month`.

**Website analytics (per client website).**
* **Tracking property** – *Analytics → website → Create analytics property* generates a tracking ID (`SITE-XXXXXXXX`) and a
  per-site script URL `https://your-crm/analytics/SITE-XXXXXXXX.js`; one line before `</head>` (Copy tracking code) tracks every
  page automatically. The older `/t.js` + `data-site` snippet keeps working. `api/tracker.php`: async, ~2 KB, cache 1 h, SPA
  route changes (pushState / replaceState / popstate), `leave` beacon with time on page (pagehide / visibilitychange), custom
  events `omTrack('event', 'cta_click')`, offline queue in localStorage (max 20, 24 h) flushed on the next load / `online`,
  bot filtering, Do-Not-Track opt-in.
* **Visitor & session logic** – anonymous device id (localStorage) + session id (sessionStorage), hashed server-side with
  the site key; no cookies, no IP stored with events (geo is looked up once per hashed IP and cached). Home → About →
  Services → Contact = 1 visitor, 1 session, 4 page views.
* **Metrics** – visitors, unique visitors (distinct devices over the range), new / returning, sessions, page views,
  **bounce rate** (single-page sessions), **average engaged time per session / per page**, active now; per page: visitors,
  views, entries, average time, bounce rate; entry / exit pages; sources (search / social / direct / referral / campaign),
  referrers, **utm_source / medium / campaign / term / content** (plus gclid / fbclid), devices, browsers, systems,
  screen sizes, countries, **states / regions**, cities (approximate, IP-based – never presented as an exact location),
  weekday × hour heatmap, custom events (dimension `event`, monthly quota per plan).
* **Real-time** – `Analytics::realtime()`: active visitors in the last 5 minutes with current page, location, device,
  browser, source and last activity, pages being viewed, breakdowns; the page polls every 20 s (visible tab only).
* **Tracking status is real** – `Tracking Active` / `Tracking Not Detected` / paused come from received events;
  first activity, last event, last page received and *Verify installation* (fetches the home page) are shown in the setup card.
* **Plan tiers** – `analytics` none | basic | full, `analytics_retention`, `geo` country | region | city, `realtime`,
  `max_events_month`, `max_pageviews_month`, `reports`: edited on Plans & Pricing (dedicated inputs + JSON for the rest),
  enforced in `Analytics::level / geoLevel / realtimeAllowed / eventsQuota / quota`, shown on the public pricing table.

**Form monitoring: page availability ≠ submission availability.** Every test records whether the page loaded and the form
was found (`forms.last_page_ok / last_form_found`) separately from the submission verdict. Outcomes: 🟢 Working ·
Working (email not verified) · 🟠 **CAPTCHA Protected** (page available, form present, submission needs a human – never a
failure, never an alert, never bypassed) · 🟡 Needs Attention (widget overlap / configuration) · 🔴 Submission Failed ·
Server Error (5xx) · Timeout · Connection Failed · JavaScript Error · ⚫ Form Not Found. `form_outcome_explanation()` gives the
sentence shown in the UI. Owner-approved CAPTCHA-free test pages / parameters stay the only way to auto-submit protected forms.

**Roles.** `users.is_platform_owner` = **Owner / product creator** (implies Super Admin): plans & pricing, plan deletion,
product settings (platform name, trial plan / days, registration, analytics defaults) and platform-role grants are
`Auth::requirePlatformOwner()`; refused attempts are audited as *denied*. **Super Admin** keeps clients, websites,
subscriptions, usage, users (activate / deactivate, one-hour password-reset links – the password is never seen), SMTP,
system. Client users see only their workspace (`Tenant::id()` scoping, `Auth::can()`).

**Super Admin dashboard** adds suspended clients, expiring / overdue subscriptions, plan distribution and a platform-health
strip (monitoring runner + jobs + sites down, forms working / failed / CAPTCHA-protected, email service, analytics events
in 24 h and receiving sites, registration state).

**Monitoring frequency** is plan-based (unchanged since v3.4, verified again): the dispatcher runs every minute but each
website / page / form / SSL check is queued only when `next_*_at` is due, and the next run is set from
`Tenant::interval()` = max(plan interval, platform floor). Free 60 min, Starter 30, Professional 10, Business / Agency 5
(all editable per plan).

**Verification:** scratchpad `analytics37-test.php` (49 checks: rollups incl. bounce / time / per-page visitors / UTM /
events, report + realtime, rejections, tracker route + collect over HTTP, outcome classification, plan-interval dispatch),
`platform-test.sh` (117 checks incl. Owner gating, user access actions, public pages) and `ui-check.php` on the changed pages.
## 5o. Email template studio and immediate delivery (v3.8)

`database/upgrade-3.8.sql` (re-runnable): `email_templates.from_name / from_email / reply_to / preheader / cta_label /
cta_url / header_image / footer_text / status / updated_by / created_at`, `email_queue.from_name / from_email / reply_to /
template`, index `idx_email_logs_template_time`, alert-delivery job interval 1 min, setting `email_send_immediately`.

**Why emails took 2+ minutes.** The SMTP round trip itself takes well under a second (email_logs.duration_ms averaged
14 ms locally). `Mailer::queue()` only inserted a row; delivery waited for the scheduler – the "process-notifications"
system job (every 2 min) claimed by a worker, or the cron tick (every 5 min) / web heartbeat when no worker runs. On the
live server that dependency, plus the +15 s dispatcher guard and the 2/4-minute retry back-off, was the whole delay.

**Immediate delivery.** `Mailer::queue()` still writes the durable queue row (the retry record) but now delivers it in the
same process through one bounded SMTP session (`Mailer::deliverNow()`, 20 s budget in web requests, unlimited in CLI).
`Notifier::email()` wraps its recipients in `Mailer::collect()` so an alert to N people is one connection + one AUTH +
N messages. Rows are left for the scheduler only when SMTP is not configured, the circuit breaker is open, the transport
failed (retry after 1 / 2 / 4 min instead of 2 / 4 / 8) or `email_send_immediately` is off. The scheduler job is now a
per-minute safety net. No retry is attempted while the breaker is open, and a rejected / misconfigured message is final
after one attempt.

**Templates** (`Mailer`): `templateMeta()` (tone / kind / default CTA / purpose), `getTemplate()` returns the sender,
CTA, image, footer and status fields, `templateList()` (one query + 30-day delivery stats), `sampleVars()` (realistic
values – "John Smith", "Acme Digital"), `renderWith()` / `previewTemplate()` (unsaved editor values), `saveTemplate()`
(validation: subject, body, emails, https:// URLs), `setTemplateStatus()`, `resetTemplate()`, `sendTemplateTest()`
(real `send()` – category `test`, template recorded, never simulated). A disabled template makes `queue()` return 0
(nothing stored or sent; the in-app notification is still created). Per-template From / Reply-To are stored on the
queue row and applied per message (`applySender()`); the envelope sender always stays the authenticated SMTP account.

**Email HTML** (`Mailer::layout()` + `bodyToHtml()`): responsive table layout (600 px, stacks under 620 px), brand bar,
tone accent, kind pill, title, greeting, paragraphs, "Label:\nValue" pairs grouped into a details table (Status values
become coloured pills, URLs / addresses auto-linked), bulletproof CTA button, signature block, footer, preheader text,
optional header image. The generic `Mailer::template()` (verification, password reset, system errors) uses the same
layout, so every email the CRM sends shares one design. Template bodies stay plain text with `{{variables}}` – existing
customisations keep working unchanged.

**Studio** (`platform/templates.php`, `api/templates.php`, `assets/css/email-templates.css` loaded only there, no
editor library): status strip, search / type / status filters, one card per template with a lazily rendered thumbnail
of the real email (IntersectionObserver → `preview` → scaled sandboxed iframe), kind badge, rendered subject,
active/disabled switch, last update, last delivery, Preview / Edit / Send Test. Preview dialog = mail-client header
(from, reply-to, to, preheader) + Desktop / Tablet 640 / Mobile 375 device switcher + plain-text view. Editor = split
view with live preview (debounced, unsaved values), tabs Content (subject, body, variable chips inserted at the cursor,
preheader), Sender, Button & design (label, link, header image, footer), Status; Save / Reset / Send test with these
values. Send Test dialog shows 🟢 Test Email Sent Successfully / 🔴 Email Sending Failed with the real SMTP response,
sending time, Message-ID, error kind, suggestion and log links – the request is bounded and the page stays usable.
"Recent template deliveries" lists the latest `email_logs` rows with duration and response. Legacy actions in
`api/settings.php` (`save_template` / `reset_template` / `preview_template`) delegate to the same code.

**Logs:** the tenant Email Logs table gained a Sending time column and error kind / attempt, and both details dialogs
show from, attempt, sending time, error type and SMTP response.

**Verification:** scratchpad `engine-test.php` (88 checks: rendering, validation, immediate delivery, batching, sender
headers via the SMTP sink, breaker / deferred path, safety net, test send success + honest failure, legacy layout),
`web-test.sh` (32 HTTP checks: page, API actions, PJAX, tenant log, datatable), headless-Chrome screenshots of the email
at 900 / 375 px and of the studio dialogs.

## 5p. No-cron execution engine, plan-driven scheduling and the alert state machine (v3.9)

`database/upgrade-3.9.sql` (re-runnable): `engine_state` (runtime state of the engine), `alerts` (one row per problem),
settings `engine_enabled`, `engine_hop_seconds`, `worker_autostart`, `php_cli_path`, `monitor_expired_subscriptions`,
`monitoring_min_interval`.

**Nothing to configure on the server.** Deploy, open the application once, done. No cPanel cron job, no command, no
supervisor and no open dashboard are required. The mechanism is honest and documented on Super Admin → System Health:

1. **Auto-spawned worker process** – when the web server may run processes (exec / proc_open / popen not disabled and
   a PHP CLI binary is found: `php_cli_path` setting, `PHP_BINDIR`, cPanel `ea-php*` / `alt-php*`, `/usr/local/bin/php`,
   XAMPP), `Engine::spawnWorker()` starts `cron/worker.php` detached (`nohup … &` / `start /B`) and restarts it
   whenever it is gone (a worker recycles every 6 h).
2. **Self-perpetuating request chain** – `cron/engine.php` is a *hop*: it verifies the generated token, flushes a
   one-line response, takes the `engine:hop` DB lock, runs `Scheduler::tick()` (dispatch due targets per plan interval;
   process the queue inline when no worker is alive), keeps the worker alive, waits until the minute is over and fires
   an HTTP request to itself (`Engine::fireHop()`, fire-and-forget over `stream_socket_client`) before exiting. One
   chain only (lock); a hop fires its successor only while it still owns the lock, so the chain can never double.
   Bounded by the effective PHP time limit (`set_time_limit` when honoured, otherwise `max_execution_time − 8`).
3. **Inbound-request watchdog** – `init.php` registers `Engine::watchdog()` as a shutdown function for every web
   request (pages, the analytics beacons client websites send, the API, uptime pingers). It costs ~1 ms (30-second
   cached flag) and, when the last hop is older than 150 s, fires a new hop and respawns the worker (at most once per
   minute platform-wide). `api/collect.php` beacons – requests nobody waits for – additionally run a short inline
   tick (`Engine::inlineTickIfNeeded`) when neither a worker nor the chain is alive.

Honest limit: PHP cannot run when the web server never receives a request AND the chain was killed (reboot, process
limits); the next inbound request of any kind restarts everything. `cron/run-all.php`, `cron/worker.php` and the
health-check URL still exist as *optional* extra triggers; locks and leases make all runners cooperate.

**Plan-driven cadence.** `Tenant::interval()` now returns the subscription plan's `website/page/form/ssl_interval`
(global settings only apply when the plan has no value; `monitoring_min_interval` is a platform floor). The dispatcher
sets `next_*_at = NOW() + plan interval` per target; forms follow the plan (`forms.test_interval` can only make a form
slower). `Scheduler::reschedule($tenantId | null, $planId)` re-aligns every schedule (`next = max(now, last + new
interval)`) and is called from `Tenant::changePlan()`, `plan_save` (interval edits → every workspace on the plan),
`set_subscription_status`, `set_status` and `tenant_save`. `Scheduler::tenantActiveSql()` excludes suspended /
cancelled workspaces and expired / cancelled subscriptions (unless `monitor_expired_subscriptions` = 1); a renewal makes
overdue targets due at once. `withinPlanLimits()` monitors only the oldest N websites of a workspace that exceeds its
plan after a downgrade.

**Alert state machine** (`alerts` table, `Notifier::alertOpen / alertSeen / alertRecover / alertRecoverKind`):
working → failed opens one row and sends one notification; every further failing check only increments
`checks_while_failing` (silent); recovery closes the row with `downtime_seconds`, `recovered_at` and
`recovery_notified_at`. Wired into website, page (single + combined), form, SSL, domain / hosting expiry (a renewed
expiry date closes the earlier notice), scheduler-stale (closed by the next successful hop) and SMTP: `Mailer` reports
only the *transitions* connected → failed (in-app + alert row, no email possible) and failed → connected (one
"SMTP Connection Recovered" email to platform admins). `Notifier::markSent()` records `notified_at` /
`recovery_notified_at` / `notification_count` on the row. Pages: workspace **Incidents → Alert History**
(`incidents/alerts.php`, datatable `alerts`) and Super Admin **Alert History** (`platform/alerts.php`,
`platform_alerts`), filters by state / type / website (client) / period.

**Dashboards.** `websites/view.php` gained a *Monitoring schedule* card (plan, interval per kind, last / next check,
last success / failure, current error with failing-check count, notification state, last recovery, engine state); the
websites list shows the next check under *Last Check*; Super Admin → Websites shows plan interval, open alerts, last
failure and *Next check*. System Health has the *Execution engine* card (mechanism in use, environment facts, Test
loopback / Start worker / Start engine / Disable) backed by `api/platform.php` `engine_test / worker_start /
engine_start / engine_toggle`.

**Retry / transient errors** (unchanged, documented): website probes retry `retry_attempts` times 1.5 s apart before a
check counts as failed, pages retry once concurrently, SSL retries a pure connection error once, forms confirm a
failure with `form_confirm_retry` re-tests and never alert on CAPTCHA / configuration outcomes; queue jobs retry with
exponential back-off and dead-letter after `job_max_attempts`.

**Verification:** scratchpad `nocron-test.php` (32 checks: alert transitions + counts, silent repeats, recovery email,
SMTP transitions, plan intervals + reschedule on up/downgrade, form cadence, expired / suspended exclusion, renewal,
plan-limit dispatch, engine state + loopback), `nocron-web-test.sh` (HTTP: hop endpoint auth + flush, system page,
alert history pages + datatables, schedule columns, website card, top-bar state, hop freshness), plus the live
observation that the chain hops every minute and a worker was spawned from a 45 ms anonymous page request.

## 5q. Production launch audit (v4.0)

Full end-to-end audit before the first real client (four parallel module audits + one combined launch test). Everything
below was verified against the real backend (HTTP + database), not by opening pages.

**Fixed during the audit**

* Cross-tenant leaks: `api/datatables.php` `uptime` table had no tenant filter; `api/search.php` queries were unscoped
  (and crashed on `%%%`); the Emails report (`includes/Reports.php`) listed every tenant's mail; `remote_select()`
  resolved labels of foreign ids. All scoped to `Tenant::id()` now.
* `Tenant::lock()` (`monitor_locks`) extended an expired lock without re-owning it – a crashed process held
  `browser:slot:*`, `dispatch:*`, `engine:*` locks forever (25 form jobs were stuck 3 h locally). Assignment order fixed.
* Engine on Windows: a second worker could not start while the first held `logs/worker.log` open – one log per launch
  (`logs/worker-<timestamp>.log`, newest 5 kept). Workers inside a long browser job (discovery / popup test, minutes)
  are no longer declared dead: `Scheduler::workersAlive()` / `reapWorkers()` treat a live job lease as a heartbeat.
* Trial expiry (housekeeping) now moves the workspace to `subscription_status = free` + `Scheduler::reschedule()`
  (monitoring continues at Free-plan pace instead of stopping as "expired").
* `plans.retention_days` is now enforced: `Monitor::pruneHistoryByPlan()` (daily) trims uptime rollups, resolved
  incidents, form tests, SSL / page history and recovered alerts per workspace. Analytics: raw events and known
  devices never outlive the plan's analytics retention either.
* Security: `client_ip()` trusts proxy headers only with `analytics_trust_proxy = 1` (rate limits were spoofable);
  session cookie gets `Secure` behind an HTTPS proxy; a used verification link no longer signs the user in; reset
  password enforces the registration password policy; open-redirect guard on `intended`; constant-time
  forgot-password; SVG logo sanitising (event handlers, animation, data: hrefs); `Permissions-Policy`, CSP
  `frame-ancestors`, HSTS (on HTTPS) headers; `X-Powered-By` removed.
* Analytics: beacon / tracker never start a CRM session or set a cookie (`index.php` defines `SKIP_SESSION` for
  `/collect`, `/t.js`, `/analytics/KEY.js`); IP hashes are HMAC-SHA256 with `APP_KEY` (were plain md5); `CF-IPCountry`
  only honoured behind a trusted proxy; "New ID" resets the tracking status; install verifier is SSRF-safe (public
  http(s) hosts only, redirects re-validated); `database/upgrade-4.0.sql` adds ON DELETE CASCADE from every
  `analytics_*` table to `websites` (orphans removed first) and the `(website_id, dim, day)` index.
* Monitoring: config errors (selector / popup not found) → "Needs attention", no incident, no client email; SSL
  error text shows the real verify failure; renewed domain / hosting dates close their expiry alerts; tenant
  `ssl_alert_days` respected; manual checks move `next_*_at` like the dispatcher; page / form / uptime pages read the
  plan interval.
* Platform: `maintenance_toggle` API + 🟢 Live / 🟠 Maintenance card (dashboard + System Health) + banner; public
  pages and registration answer 503 during maintenance while Super Admins, beacons, tracker, engine hops and health
  check keep working; 503 page has an administrator sign-in link; every Super Admin action is audited (note,
  act_as_stop, process_now, job_*, worker_forget added); `platform_alerts` / `platform_audit` search no longer 500;
  Advanced-API plans can create read/write keys; Reports gated by the plan (`reports = none` → 403 with upgrade hint).
* Pricing page reflects the product only: invented rows (notification rules, maintenance windows, client portals,
  white-label dashboard, incident-history tiers, team-permission tiers) removed; popup / AJAX / CAPTCHA / hosting
  reminders included on every plan; "Monitoring history" row is the enforced `retention_days`; plan highlights
  rewritten (Free events / month 500; Business "website & form monitoring every 5 minutes (SSL every 10)").
* "Scheduler Jobs" tab with cPanel crontab instructions removed from Platform Settings (the engine replaces it).

**Honest statements** – what the product does *not* do: domain / hosting monitoring is expiry-date reminders on
recorded dates (no WHOIS / RDAP / DNS lookups); analytics has no true "returning visitors" (visitor-days minus new
devices) and session duration is engaged time from leave beacons; exit pages exist only on full-analytics plans within
the raw-event window; browser geolocation is never requested (IP-based, labelled approximate).

**Launch tooling**

* `includes/partials/launch-checklist.php` (System Health): live checks for `APP_ENV`, `APP_KEY`, `CRON_KEY`, HTTPS,
  SMTP verified, engine running, PHP extensions, writable folders, default admin account, leftover test data.
* `database/production-reset.php --confirm=RESET [--keep-user=…] [--keep-tenant=1] [--database=<copy>]`: mysqldump
  backup to `database/backups/`, then deletes every client, website, form, analytics, alert, log, job, session and
  every workspace / user except the kept Super Admin; keeps settings, plans, templates, taxonomy, scheduler rows;
  resets engine / queue / SMTP runtime state; verifies the result. `--database` rehearses on a copy.
* `docs/PRODUCT-SITEMAP.md`: client-facing product overview, sitemap, roles and plan matrix (matches the code).

**Verification:** scratchpad `e2e-launch.sh` (50 checks: register → immediate verification email → verify → login →
client + website → manual check → tracking id / script / install steps / waiting status → beacons → visitors, page
views, country → broken form: ONE alert email, two more failing tests silent, fixed: ONE recovery email, then silent →
plan interval 10 min and next = last + 10 → plan change to Business re-aligns to 5 min → analytics retention prune →
maintenance mode: public 503, beacon 204, tracker 200, hop 200, console 200 → Super Admin sees workspace, website and
alert), plus `nocron-test.php` 32, `nocron-web-test.sh` 27, `web-test.sh` 32, `engine-test.php` 88, `saas-test.sh`
137/140 (3 stale route expectations), UI harness at 1440 / 768 / 390 px on 25 pages, and the four module reports.

## 6. Roles

Platform roles (v3.7): `users.is_platform_owner` (Owner / product creator – plans, pricing, product settings, platform-role grants; implies Super Admin) and `users.is_platform_admin` (Super Admin – clients, websites, subscriptions, usage, users, SMTP, system, audit). Workspace roles (v3.0): `owner` (everything incl. billing, ownership transfer, workspace deletion), `admin` (everything except billing/ownership: team, settings, deletes, credentials, WordPress passwords, departments & website types), `manager` (manage clients/websites/forms/domains/hosting/reports/projects), `viewer` (read-only; legacy `staff` behaves the same) and `notify` (alert recipient only, cannot log in). Platform administrators (`users.is_platform_admin`) additionally get the `/platform` area. The role matrix lives in `includes/Auth.php::can()`.

## 7. Project structure

```
Client-Website/
├── platform/       Super Admin console – index (dashboard), customers/customer (clients), websites, users, plans, subscriptions, usage, analytics, audit, emails (SMTP / Email), templates, system, settings
├── api/            AJAX endpoints (JSON) – clients, websites (incl. pages, WordPress login), credentials, forms, users, settings, email (SMTP), platform (Super Admin), monitor, search…
├── assets/         css / js / images / fonts (brand assets)
├── auth/           login, logout, forgot & reset password
├── activity/       activity log
├── clients/        list + client control centre (view.php)
├── config/         config.php, database.php  (blocked from the web)
├── cron/           scheduled scripts (run-all, check-websites, check-pages, check-forms, discover-forms, check-ssl, check-expiry, housekeeping, process-notifications)
│                   + Windows wrapper (run-task.cmd, windows-task.bat, register-windows-task.ps1)
├── dashboard/
├── database/       schema.sql + upgrade-1.x.sql
├── projects/       website projects (index, view, departments) – api/projects.php, api/departments.php
├── domains/  hosting/  forms/  reports/  notifications/  users/  settings/  websites/ (index, view, health, pages, uptime)
├── includes/       init.php (bootstrap), Database.php, Auth.php, Monitor.php, Notifier.php, Mailer.php, Reports.php, Stats.php, layout/, partials/
├── logs/           app-YYYY-MM-DD.log, php-errors.log, cron.log, task-scheduler.log  (blocked from the web)
├── uploads/        uploaded logo
├── vendor/phpmailer
└── index.php
```

## 8. Troubleshooting

* **"Something went wrong" page** – look in `logs/app-*.log` and `logs/php-errors.log`. Set `APP_ENV = 'development'` to see details on screen.
* **Emails not sending / SMTP test slow or failing** – Super Admin → SMTP / Email → *Test SMTP*: the step list shows exactly where it stops (DNS, TCP,
  TLS, EHLO, AUTH, send) with the server's reply and a fix. A *timeout on the TCP step* on a live server means the host blocks outbound SMTP ports –
  use the host's local mail server or ask them to open 465 / 587. *TLS failed* = wrong port / encryption pair (465 = SSL, 587 = STARTTLS) or a
  self-signed certificate (disable verification). *Authentication failed* = re-enter the password (Gmail: App Password; Microsoft 365: enable SMTP AUTH).
  Queued alerts pause for a few minutes after a transport failure (back-off) – *Process queue now* forces a retry.
* **Sites show "Not Checked" / cron NOT RUNNING** – the cron job has not run. On cPanel check the cron line; on Windows check
  `logs\task-scheduler.log` (MySQL not running is the usual cause – the wrapper now starts it). Run `php cron/run-all.php --force` from a terminal to test.
* **No pages found for a website** – the site has no sitemap and no crawlable links on the homepage (e.g. a JavaScript-only menu). Add the
  pages manually on the website page (*Pages → Add Pages*).
* **SSL shows "Certificate not trusted" on localhost** – XAMPP's PHP may lack a CA bundle; on cPanel this works out of the box.
  If needed, point `openssl.cafile` in `php.ini` to a `cacert.pem`.
