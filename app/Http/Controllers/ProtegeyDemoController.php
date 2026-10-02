<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Protegey\Sdk\Protegey;
use Protegey\Sdk\WebhookVerifier;

/** Minimal demo of protegey/sdk from a Laravel backend — transaction reporting, starting a KYC
 * session, and verifying an incoming webhook's signature. */
class ProtegeyDemoController extends Controller
{
    private function client(): Protegey
    {
        return new Protegey(
            config('services.protegey.api_key'),
            config('services.protegey.base_url'),
        );
    }

    /** A fresh id per browser session — real integrations pass the end user's own stable id instead. */
    private function customerId(): string
    {
        if (!session()->has('protegey_demo_customer_id')) {
            session(['protegey_demo_customer_id' => 'customer-' . bin2hex(random_bytes(3))]);
        }

        return session('protegey_demo_customer_id');
    }

    public function reportTransaction(): JsonResponse
    {
        $result = $this->client()->transactions->report([
            'externalTransactionId' => 'laravel-example-' . now()->timestamp,
            'externalCustomerId' => $this->customerId(),
            'direction' => 'DEBIT',
            'amount' => 5000,
            'currency' => 'XAF',
            'transactionType' => 'test',
        ]);

        return response()->json($result);
    }

    public function startKyc(): JsonResponse
    {
        $session = $this->client()->kyc->startSession($this->customerId());

        return response()->json($session);
    }

    /** Route a partner would point their Protegey webhook URL at. */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent(); // raw body — required, a re-encoded one won't match
        $timestamp = $request->header('X-Timestamp', '');
        $signature = $request->header('X-Signature', '');
        $secret = config('services.protegey.webhook_secret');

        if (!WebhookVerifier::verify($payload, $timestamp, $signature, $secret)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        // Handle $request->json() here — update the matching case/session in your own system.
        return response()->json(['received' => true]);
    }
}
