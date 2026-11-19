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

    /** A cash deposit taken at a branch — stored by Protegey as a monitored cash CREDIT, so it runs
     * through the same rules (structuring, large cash, ...) and raises the same alerts. */
    public function reportCashDeposit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        // Returns { cashDepositId, externalTransactionId, decision: CLEAR|REVIEW|BLOCKED, riskScore, alerts[] }.
        // externalTransactionId makes retries safe: reusing it gets a 409.
        return $this->call(fn () => $this->protegey->cashDeposits->record([
            'externalTransactionId' => 'laravel-deposit-'.now()->timestamp.'-'.bin2hex(random_bytes(2)),
            'externalCustomerId' => $this->customerId(),
            'amount' => (float) ($data['amount'] ?? 2500000),
            'currency' => $data['currency'] ?? 'XOF',
            'channel' => 'BRANCH',
            'branchId' => 'DKR-PLATEAU',
            'depositorName' => 'Awa Diallo',
            'metadata' => ['tellerId' => 'T-04'],
        ]));
    }

    public function listCashDeposits(): JsonResponse
    {
        // amount is a decimal string in each row, e.g. "2500000.00".
        return $this->call(fn () => $this->protegey->cashDeposits->list([
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

    /** Exact lookup on your own customer id (defaults to this browser's demo customer). Shows
     * Protegey's `recommendation` next to the final `status`, and the pre-signed photo links —
     * valid 1 hour and re-issued on every call, so never store them. */
    public function listKycSessions(Request $request): JsonResponse
    {
        $externalUserId = (string) $request->query('externalUserId', $this->customerId());

        return $this->call(function () use ($externalUserId) {
            $page = $this->protegey->kyc->findByExternalUserId($externalUserId, ['limit' => 10]);

            return [
                'externalUserId' => $externalUserId,
                'total' => $page['total'],
                'sessions' => array_map(fn (array $s) => [
                    'sessionId' => $s['sessionId'],
                    'status' => $s['status'],                 // final decision
                    'recommendation' => $s['recommendation'] ?? null, // APPROVE | REVIEW | REJECT | null
                    'outcome' => $s['outcome'] ?? null,
                    'images' => $s['images'] ?? null,         // {documentFront, documentBack, selfie, documentFaceCrop}: {url, expiresAt}|null
                ], $page['data']),
            ];
        });
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
            // Fires on an automatic result and on a manual status change; sessionId is the id
            // startSession() returned. status is the final decision, recommendation Protegey's verdict.
            WebhookVerifier::EVENT_KYC_STATUS_CHANGED => Log::info('KYC status', [$event['sessionId'] ?? null, $event['externalUserId'] ?? null, $event['status'] ?? null, $event['recommendation'] ?? null]),
            // submissionStatus is UPPER_SNAKE_CASE like status (e.g. APPROVED).
            WebhookVerifier::EVENT_KYB_STATUS_CHANGED => Log::info('KYB status', [$event['clientId'] ?? null, $event['status'] ?? null, $event['submissionStatus'] ?? null]),
            WebhookVerifier::EVENT_ALERT_CREATED,
            WebhookVerifier::EVENT_ALERT_STATUS_CHANGED => Log::info('Alert', [$event['alertId'] ?? null, $event['status'] ?? null]),
            WebhookVerifier::EVENT_TRANSACTION_BLOCKED => Log::warning('Transaction blocked', [$event['externalTransactionId'] ?? null]),
            WebhookVerifier::EVENT_WEBHOOK_TEST => Log::info('Protegey webhook test received'),
            default => Log::notice('Unknown Protegey webhook type', [$event['type'] ?? null]),
        };

        return response()->json(['received' => true]);
    }
}
