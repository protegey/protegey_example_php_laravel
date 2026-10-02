# Protegey — Laravel example

Minimal Laravel app demonstrating `protegey/sdk` (PHP) from a real backend: transaction reporting,
starting a KYC session, and verifying an incoming webhook's HMAC signature.

## Run it

```bash
composer install
cp .env.example .env # fill in PROTEGEY_API_KEY, PROTEGEY_BASE_URL, PROTEGEY_WEBHOOK_SECRET
php artisan key:generate
php artisan serve
```

Open `http://localhost:8000` — two buttons call the backend directly (no JS SDK involved, this is
entirely server-to-server).

## What it does

- `app/Http/Controllers/ProtegeyDemoController.php`:
  - `reportTransaction()` — `protegey->transactions->report()`.
  - `startKyc()` — `protegey->kyc->startSession()`, returns the hosted URL to the page, which opens
    it in a new tab.
  - `webhook()` — verifies `X-Signature`/`X-Timestamp` via `WebhookVerifier::verify()` before
    trusting the payload. Point your Protegey webhook URL at `/protegey/webhook` to see this in
    action; it's CSRF-exempt (see `bootstrap/app.php`) since Protegey's server calls it directly,
    not a browser session.
- `config/services.php` — reads `PROTEGEY_*` env vars.
