# Outline Monitor – Product Overview & Sitemap

*Written for clients and partners evaluating the product. Everything listed here exists in the application today.*

## What the product does

Outline Monitor watches a client's websites around the clock and tells the right people the moment something breaks – and again when it is fixed. It checks that every page loads, that contact / enquiry forms really accept submissions, that SSL certificates stay valid, and that domains and hosting do not expire unnoticed. It also measures website traffic with a lightweight tracking script. Each client works in its own workspace; the platform team runs everything from a separate Super Admin console.

## How it works

| Area | How |
|---|---|
| **Website monitoring** | The homepage and every discovered page are requested from the server at the interval of the client's plan. Failures (timeouts, DNS errors, connection refused, HTTP 4xx/5xx, redirect problems, SSL errors, missing expected text, parking pages) are confirmed with automatic re-tries before an incident is opened. |
| **Page monitoring** | Pages are discovered from the sitemap, the WordPress sitemap and internal links (up to the plan's page limit) and checked individually; several pages failing in one scan produce one combined alert. |
| **Form monitoring** | Each form is filled in and submitted the way a visitor would (HTTP engine, or a real headless browser for popup / JavaScript forms) and the response is judged: working, submission failed, server error, timeout, configuration error. Forms protected by reCAPTCHA / hCaptcha / Turnstile are reported as **CAPTCHA Protected** – never faked, never marked broken. |
| **SSL monitoring** | Full TLS handshake with certificate validation, hostname match, expiry date and issuer; warnings before expiry, alert on failure, recovery when valid again. |
| **Domain & hosting** | Expiry dates and providers are recorded per domain and hosting account; reminders go out at the configured days-before thresholds and again when expired; a renewed date closes the reminder. |
| **Alerts** | One alert when a problem starts, silence while it continues, one recovery alert when it is resolved – for websites, pages, forms, SSL, domains, hosting and the platform's own mail server. Recipients: workspace admins, the assigned staff member and (opt-in) the client contact. Every alert is kept in the Alert History with notification count, downtime and the error. |
| **Analytics** | A one-line tracking script (`/analytics/SITE-XXXXXXXX.js`) sends page views, time on page and custom events to the platform. Reports show visitors, unique visitors, sessions, page views, page-level visitors, bounce rate, session duration, referrers, UTM campaigns, devices, browsers, operating systems, countries and regions (approximate, IP-based), landing pages and live visitors. Data is kept for the plan's retention period. |
| **Subscriptions** | Every workspace is on a plan. The plan sets the monitoring interval (websites, pages, forms, SSL), the number of websites, pages, forms, users and status pages, monthly page views, analytics depth and retention. Limits are enforced on the server. |
| **Execution** | Monitoring runs server-side inside the application's own execution engine (background worker plus a self-perpetuating scheduler chain). No cron job, open browser or logged-in user is needed. |

## Sitemap

```text
OUTLINE MONITOR
│
├── Public
│   ├── Landing page                       /
│   ├── Pricing                            /pricing
│   ├── Contact sales                      /contact-sales
│   ├── Terms · Privacy                    /terms · /privacy
│   ├── Public status pages                /status/{workspace}
│   └── Analytics tracker (CDN script)     /analytics/SITE-XXXXXXXX.js  → beacon endpoint /collect
│
├── Authentication
│   ├── Login · Logout                     /login · /logout
│   ├── Registration (creates a workspace) /register
│   ├── Email verification                 /verify-pending · /verify
│   ├── Password reset                     /forgot-password · /reset-password
│   ├── Team invitation                    /accept-invite
│   └── Profile & password                 /profile
│
├── Client workspace
│   ├── Overview dashboard                 /dashboard   (health, incidents, monitoring engine state, usage)
│   ├── Websites
│   │   ├── All websites · Add website     /websites · /websites/add
│   │   ├── Website detail                 /websites/{id}   (schedule, pages, forms, SSL history, uptime, activity)
│   │   ├── Website health · Uptime        /websites/health · /websites/uptime
│   │   ├── Page monitoring                /pages
│   │   └── Website analytics              /websites/{id}/analytics
│   ├── Forms
│   │   ├── Form monitoring                /forms/monitoring   (working · CAPTCHA protected · failed · config error)
│   │   ├── All forms · Test history       /forms · /forms/history
│   │   └── Form discovery                 /forms/discovery
│   ├── SSL certificates                   /ssl
│   ├── Domains · Hosting                  /domains · /hosting
│   ├── Analytics overview                 /analytics
│   ├── Clients & projects                 /clients/{id} · /projects · /projects/departments
│   ├── Incidents · Alert History          /incidents · /incidents/alerts
│   ├── Alerts inbox (in-app)              /alerts
│   ├── Reports (+ CSV export)             /reports
│   ├── Status pages                       /status-pages
│   ├── Activity log                       /activity
│   ├── Team & roles                       /team   (owner · admin · manager · viewer · notify-only)
│   ├── Email log                          /emails
│   ├── Billing & plan                     /billing
│   └── Workspace settings                 /settings   (API keys, webhooks, alert recipients, thresholds)
│
├── Integrations
│   ├── REST API v1 (API keys)             /api/v1/…
│   └── Webhooks (Business / Agency)       outgoing notifications
│
└── Super Admin console                    /platform
    ├── Platform dashboard                 /platform
    ├── Clients (workspaces)               /platform/customers · /platform/customers/{id}
    │       view · edit · suspend · restore · delete · plan · trial · subscription status · notes · act as
    ├── Websites (all workspaces)          /platform/websites
    ├── Users & access                     /platform/users
    ├── Plans & pricing (Owner)            /platform/plans
    ├── Subscriptions · Usage              /platform/subscriptions · /platform/usage
    ├── Analytics overview · Audit logs    /platform/analytics · /platform/audit
    ├── SMTP / Email · Email templates     /platform/emails · /platform/templates
    ├── System health (engine, queues)     /platform/system
    ├── Alert history (all workspaces)     /platform/alerts
    └── Platform settings                  /platform/settings   (general, monitoring, alerts, maintenance mode)
```

## Roles

| Role | Can |
|---|---|
| **Owner** (product creator) | Everything a Super Admin can, plus plans, pricing, plan limits, monitoring intervals, analytics retention, feature availability and product settings. |
| **Super Admin** | Clients / workspaces, subscriptions, usage, users, SMTP, templates, system health, alert history, audit logs. |
| **Workspace owner / admin** | Their own websites, forms, domains, hosting, analytics, alerts, reports, team, billing, settings. |
| **Manager** | Day-to-day monitoring work in their workspace (no team, billing or destructive settings). |
| **Viewer** | Read-only access to their workspace. |
| **Notify-only** | Receives alert emails, cannot log in. |

All permissions are enforced on the server for every page and API call, not only in the navigation.

## Plans (as configured in the product – editable by the Owner)

| | Free | Starter | Professional | Business | Agency |
|---|---|---|---|---|---|
| Websites · pages · forms | 2 · 100 · 20 | 10 · 500 · 100 | 30 · 2,000 · 500 | 100 · 10,000 · 2,000 | custom |
| Users · status pages | 1 · – | 3 · – | 10 · 1 | 25 · 5 | custom · 50 |
| Website / form / SSL check interval | 60 min | 30 min | 10 min | 5 / 5 / 10 min | 5 min |
| Monitoring history | 7 days | 30 days | 90 days | 365 days | 730 days |
| Analytics | basic, country | basic, region, live | full, city, live | full, city, live | full, city, live |
| Page views / month · events / month | 1,000 · – | 25,000 · 10,000 | 250,000 · 100,000 | 2,000,000 · 1,000,000 | unlimited |
| Analytics retention | 7 days | 30 days | 90 days | 180 days | 730 days |
| Form discovery · popup forms · AJAX forms · CAPTCHA detection | ✓ · – · ✓ · ✓ | ✓ · ✓ · ✓ · ✓ | ✓ · ✓ · ✓ · ✓ | ✓ · ✓ · ✓ · ✓ | ✓ · ✓ · ✓ · ✓ |
| Hosting tracking · reports | – · – | ✓ · basic | ✓ · standard | ✓ · advanced | ✓ · advanced |
| API · webhooks · white label · custom domain | – | – | basic API | advanced · ✓ · ✓ · ✓ | advanced · ✓ · ✓ · ✓ |
| Notification rules · maintenance windows · client contacts · team permissions | – | – | rules | ✓ | ✓ + priority support, client portal |

Prices are set in the Super Admin console (INR / month); the public pricing page reads the same table.

## Connecting a client website

1. Add the website in the workspace (URL, technology, contact form details or automatic form discovery).
2. Monitoring starts at the plan interval; the website page shows last check, next check, status and open alerts.
3. Open the website's Analytics page, copy the tracking code and place it before `</head>` on the site.
4. The Analytics page shows **Tracking active** only after the first real page view has arrived, with first / last event and last page.
