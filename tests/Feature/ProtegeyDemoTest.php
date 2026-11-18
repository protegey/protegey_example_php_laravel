<?php

namespace Tests\Feature;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Protegey\Sdk\Protegey;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/** Exercises each demo route against a mocked Protegey API — no network calls, no real API key. */
class ProtegeyDemoTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    private function fakeProtegey(array $responseBody, int $status = 200): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response($status, [], json_encode($responseBody))]));
        $stack->push(Middleware::history($this->history));
        $this->app->instance(Protegey::class, new Protegey('test-key', 'https://api.protegey.test', new GuzzleClient(['handler' => $stack])));
    }

    private function sent(): RequestInterface
    {
        return $this->history[0]['request'];
    }

    public function test_report_transaction(): void
    {
        $this->fakeProtegey(['transactionId' => 't1', 'decision' => 'CLEAR', 'riskScore' => 0, 'deviceAction' => null, 'alerts' => []], 201);

        $this->postJson('/protegey/report-transaction')->assertOk()->assertJson(['decision' => 'CLEAR']);

        $this->assertSame('/partner-api/transactions', $this->sent()->getUri()->getPath());
        $this->assertSame('test-key', $this->sent()->getHeaderLine('x-api-key'));
        $this->assertSame('DEBIT', json_decode((string) $this->sent()->getBody(), true)['direction']);
    }

    public function test_list_alerts_sends_a_lowercase_status_filter(): void
    {
        $this->fakeProtegey(['data' => [], 'total' => 0, 'page' => 1, 'limit' => 10, 'totalPages' => 1]);

        $this->getJson('/protegey/alerts')->assertOk()->assertJson(['totalPages' => 1]);

        $this->assertSame('/partner-api/alerts', $this->sent()->getUri()->getPath());
        $this->assertStringContainsString('status=open', $this->sent()->getUri()->getQuery());
    }

    public function test_kyb_invite_by_phone_returns_the_application_url(): void
    {
        $this->fakeProtegey(['id' => 'c1', 'status' => 'INVITED', 'contactEmail' => null, 'contactPhone' => '+237690000000', 'applicationUrl' => 'https://partner.test/client-application?token=t'], 201);

        $this->postJson('/protegey/kyb-invite', ['contactName' => 'Jean', 'contactPhone' => '+237690000000'])
            ->assertOk()
            ->assertJson(['applicationUrl' => 'https://partner.test/client-application?token=t']);

        $this->assertSame(['contactName' => 'Jean', 'contactPhone' => '+237690000000'], json_decode((string) $this->sent()->getBody(), true));
    }

    public function test_kyb_invite_requires_an_email_or_a_phone(): void
    {
        $this->fakeProtegey([]);

        $this->postJson('/protegey/kyb-invite', ['contactName' => 'Jean'])->assertUnprocessable();
    }

    public function test_shared_signal_check(): void
    {
        $this->fakeProtegey(['flagged' => true, 'category' => 'CONFIRMED_FRAUD', 'reportedDaysAgo' => 3, 'flagConfidence' => 37, 'matchCount' => 1, 'matches' => [['category' => 'CONFIRMED_FRAUD', 'reportedDaysAgo' => 3]]], 201);

        $this->postJson('/protegey/shared-signal-check', ['phoneNumber' => '+221771234567'])
            ->assertOk()
            ->assertJsonPath('matches.0', ['category' => 'CONFIRMED_FRAUD', 'reportedDaysAgo' => 3]);
    }

    public function test_api_errors_are_surfaced_with_their_status_and_error_code(): void
    {
        $this->fakeProtegey(['errorCode' => 'PARTNER_SUSPENDED', 'message' => 'Account suspended'], 403);

        $this->postJson('/protegey/start-kyc')->assertForbidden()->assertJson(['errorCode' => 'PARTNER_SUSPENDED']);
    }

    public function test_webhook_accepts_a_valid_signature_and_deduplicates_on_event_id(): void
    {
        config(['services.protegey.webhook_secret' => 'secret']);
        $body = '{"type":"alert.created","eventId":"e1","alertId":"a1","status":"OPEN"}';
        $ts = (string) time();
        $headers = ['X-Timestamp' => $ts, 'X-Signature' => hash_hmac('sha256', "{$ts}.{$body}", 'secret'), 'Content-Type' => 'application/json'];

        $this->call('POST', '/protegey/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body)
            ->assertOk()->assertExactJson(['received' => true]);
        $this->call('POST', '/protegey/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body)
            ->assertOk()->assertJson(['duplicate' => true]);
    }

    public function test_webhook_rejects_a_bad_signature(): void
    {
        config(['services.protegey.webhook_secret' => 'secret']);
        $headers = ['X-Timestamp' => '1700000000', 'X-Signature' => 'deadbeef', 'Content-Type' => 'application/json'];

        $this->call('POST', '/protegey/webhook', [], [], [], $this->transformHeadersToServerVars($headers), '{"type":"webhook.test"}')
            ->assertUnauthorized();
    }
}
