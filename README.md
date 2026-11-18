# Protegey — Laravel example

Minimal Laravel app demonstrating `protegey/sdk` (PHP, 0.7.0+) from a real backend: transaction
reporting and listing, alerts, KYC sessions, KYB invitations, sanctions screening, the shared fraud
signal network, and verifying an incoming webhook's HMAC signature.

## Run it

```bash
composer install
cp .env.example .env # fill in PROTEGEY_API_KEY, PROTEGEY_BASE_URL, PROTEGEY_WEBHOOK_SECRET
php artisan key:generate
php artisan migrate   # sessions + cache tables (webhook eventId de-duplication uses the cache)
php artisan serve
```

Use your **sandbox** API key while trying it out — sandbox vs live is decided purely by which key
you use. Open `http://localhost:8000`; every button calls this backend, which calls Protegey
server-to-server (no JS SDK involved).

The SDK is pulled from its GitHub repository (`"protegey/sdk": "@dev"`, VCS repository in
`composer.json`). Run `composer update protegey/sdk` to pick up the latest `main`.

## What it does

- `app/Providers/AppServiceProvider.php` — binds one `Protegey` instance built from
  `config/services.php` (`PROTEGEY_*` env vars); tests swap it for a mocked one.
- `app/Http/Controllers/ProtegeyDemoController.php`:
  - `reportTransaction()` / `listTransactions()` — `transactions->report()` / `transactions->list()`.
  - `listAlerts()` — `alerts->list(['status' => 'OPEN'])` (the SDK sends the lowercase filter the
    API requires).
  - `startKyc()` / `listKycSessions()` — `kyc->startSession()` (the page opens the hosted URL in a
    new tab) / `kyc->listSessions()`.
  - `inviteKyb()` — `kyb->invite()` with an email and/or a phone number; the response's
    `applicationUrl` is the link to send yourself when you only have a phone number.
  - `screenSanctions()` — `sanctions->screen()`.
  - `checkSharedSignal()` — `sharedSignal->check()`; matches only carry `category` and
    `reportedDaysAgo`.
  - `webhook()` — verifies `X-Signature`/`X-Timestamp` via `WebhookVerifier::verify()` before
    trusting the payload, de-duplicates retried deliveries on `eventId`, then branches on `type`
    (`kyc.status_changed`, `kyb.status_changed`, `alert.created`, `alert.status_changed`,
    `transaction.blocked`, `webhook.test`). Point your Protegey webhook URL at `/protegey/webhook`;
    it's CSRF-exempt (see `bootstrap/app.php`) since Protegey's server calls it directly, not a
    browser session.
  - API errors are returned as `{ error, errorCode }` with Protegey's HTTP status (e.g. 403
    `PARTNER_CONTRACT_REQUIRED`).

## Tests

```bash
php artisan test
```

`tests/Feature/ProtegeyDemoTest.php` exercises every route against a mocked Protegey API — no
network calls and no real API key needed.
