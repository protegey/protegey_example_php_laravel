<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Protegey\Sdk\Protegey;
use Protegey\Sdk\ProtegeyApiException;
use Protegey\Sdk\WebhookVerifier;

/** Minimal demo of protegey/sdk from a Laravel backend — one action per partner API area, plus
 * verifying an incoming webhook's signature. */
class ProtegeyDemoController extends Controller
{
    public function __construct(private readonly Protegey $protegey) {}

    /** A fresh id per browser session — real integrations pass the end user's own stable id instead. */
    private function customerId(): string
    {
        if (! session()->has('protegey_demo_customer_id')) {
            session(['protegey_demo_customer_id' => 'customer-'.bin2hex(random_bytes(3))]);
        }

        return session('protegey_demo_customer_id');
    }

    /** Runs one SDK call and turns a ProtegeyApiException into a JSON error the page can show. */
    private function call(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (ProtegeyApiException $e) {
            $status = $e->status >= 400 ? $e->status : 502;

            return response()->json(['error' => $e->getMessage(), 'errorCode' => $e->errorCode], $status);
        }
    }

    public function reportTransaction(): JsonResponse
    {
        // Returns { transactionId, decision: CLEAR|REVIEW|BLOCKED, riskScore, deviceAction, alerts[] }
        return $this->call(fn () => $this->protegey->transactions->report([
            'externalTransactionId' => 'laravel-example-'.now()->timestamp.'-'.bin2hex(random_bytes(2)),
            'externalCustomerId' => $this->customerId(),
            'direction' => 'DEBIT',
            'amount' => 5000,
            'currency' => 'XAF',
            'transactionType' => 'test',
            'channel' => 'mobile_app',
        ]));
    }

    public function listTransactions(): JsonResponse
    {
        return $this->call(fn () => $this->protegey->transactions->list([
            'externalCustomerId' => $this->customerId(),
            'limit' => 10,
        ]));
    }

    public function listAlerts(): JsonResponse
    {
        // The SDK lowercases status/disposition filters, which this endpoint requires.
        return $this->call(fn () => $this->protegey->alerts->list(['status' => 'OPEN', 'limit' => 10]));
    }

    public function startKyc(): JsonResponse
    {
        return $this->call(fn () => $this->protegey->kyc->startSession($this->customerId()));
    }

    public function listKycSessions(): JsonResponse
    {
        return $this->call(fn () => $this->protegey->kyc->listSessions(['search' => $this->customerId(), 'limit' => 10]));
    }

    /** KYB invite by email and/or phone — the response carries applicationUrl to deliver yourself
     * when you only have a phone number. */
    public function inviteKyb(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contactName' => ['required', 'string'],
            'contactEmail' => ['nullable', 'email', 'required_without:contactPhone'],
            'contactPhone' => ['nullable', 'string', 'required_without:contactEmail'],
        ]);

        return $this->call(fn () => $this->protegey->kyb->invite(
            $data['contactName'],
            $data['contactEmail'] ?? null,
            $data['contactPhone'] ?? null,
        ));
    }

    public function screenSanctions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'type' => ['nullable', 'in:person,business'],
        ]);

        return $this->call(fn () => $this->protegey->sanctions->screen($data['name'], $data['type'] ?? 'person'));
    }

    public function checkSharedSignal(Request $request): JsonResponse
    {
        $data = $request->validate(['phoneNumber' => ['required', 'string']]);

        // Returns { flagged, category, reportedDaysAgo, flagConfidence, matchCount, matches: [{category, reportedDaysAgo}] }
        return $this->call(fn () => $this->protegey->sharedSignal->check([
            'phoneNumber' => $data['phoneNumber'],
            'externalCustomerId' => $this->customerId(),
        ]));
    }

    /** Route a partner would point their Protegey webhook URL at. */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent(); // raw body — required, a re-encoded one won't match
        $timestamp = (string) $request->header(WebhookVerifier::TIMESTAMP_HEADER, '');
        $signature = (string) $request->header(WebhookVerifier::SIGNATURE_HEADER, '');
        $secret = (string) config('services.protegey.webhook_secret');

        if (! WebhookVerifier::verify($payload, $timestamp, $signature, $secret)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $event = json_decode($payload, true) ?? [];

        // Deliveries are retried (up to 5 attempts over ~3 h) — deduplicate on eventId.
        if (! Cache::add('protegey-webhook:'.($event['eventId'] ?? ''), true, now()->addDay())) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        match ($event['type'] ?? null) {
            // No usable session id in this payload — correlate on externalUserId.
            WebhookVerifier::EVENT_KYC_STATUS_CHANGED => Log::info('KYC status', [$event['externalUserId'] ?? null, $event['status'] ?? null]),
            WebhookVerifier::EVENT_KYB_STATUS_CHANGED => Log::info('KYB status', [$event['clientId'] ?? null, $event['status'] ?? null]),
            WebhookVerifier::EVENT_ALERT_CREATED,
            WebhookVerifier::EVENT_ALERT_STATUS_CHANGED => Log::info('Alert', [$event['alertId'] ?? null, $event['status'] ?? null]),
            WebhookVerifier::EVENT_TRANSACTION_BLOCKED => Log::warning('Transaction blocked', [$event['externalTransactionId'] ?? null]),
            WebhookVerifier::EVENT_WEBHOOK_TEST => Log::info('Protegey webhook test received'),
            default => Log::notice('Unknown Protegey webhook type', [$event['type'] ?? null]),
        };

        return response()->json(['received' => true]);
    }
}
