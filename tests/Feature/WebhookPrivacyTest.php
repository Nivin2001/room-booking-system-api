<?php

namespace Tests\Feature;

use App\Models\WebhookLog;
use App\Services\stripe\StripeWebhookService;
use Tests\TestCase;

class WebhookPrivacyTest extends TestCase
{
    public function test_unknown_webhook_is_logged_with_only_allowlisted_event_metadata(): void
    {
        $event = (object) [
            'id' => 'evt_privacy_unknown_1',
            'type' => 'customer.created',
            'created' => 1794000000,
            'livemode' => false,
            'api_version' => '2026-09-30.clover',
        ];
        $payload = [
            'id' => $event->id,
            'type' => $event->type,
            'created' => $event->created,
            'livemode' => false,
            'data' => [
                'object' => [
                    'id' => 'cus_private_1',
                    'object' => 'customer',
                    'email' => 'private.customer@example.test',
                    'name' => 'Private Customer Name',
                    'phone' => '+15555550123',
                    'address' => ['line1' => '123 Private Street'],
                    'metadata' => ['private_note' => 'should not persist'],
                ],
            ],
        ];

        $response = app(StripeWebhookService::class)->processEvent($event, $payload);
        $stored = WebhookLog::query()->where('event_id', $event->id)->firstOrFail();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'id' => $event->id,
            'type' => 'customer.created',
            'created' => 1794000000,
            'livemode' => false,
            'api_version' => '2026-09-30.clover',
            'data' => ['object' => ['id' => 'cus_private_1', 'object' => 'customer']],
        ], $stored->payload);
        $this->assertSame('customer.created', $stored->event_type);
        $this->assertNotNull($stored->processed_at);
        $this->assertStringNotContainsString('private.customer@example.test', json_encode($stored->payload));
        $this->assertStringNotContainsString('Private Customer Name', json_encode($stored->payload));
        $this->assertStringNotContainsString('+15555550123', json_encode($stored->payload));
        $this->assertStringNotContainsString('Private Street', json_encode($stored->payload));
    }

    public function test_checkout_and_refund_webhook_logs_keep_processing_fields_but_drop_customer_data(): void
    {
        $checkoutEvent = (object) [
            'id' => 'evt_privacy_checkout_1',
            'type' => 'checkout.session.completed',
            'created' => 1794000001,
            'livemode' => false,
            'data' => (object) ['object' => (object) [
                'id' => 'cs_test_privacy_1',
                'object' => 'checkout.session',
                'status' => 'complete',
                'payment_status' => 'paid',
                'mode' => 'payment',
                'livemode' => false,
                'amount_total' => 3250,
                'currency' => 'usd',
                'payment_intent' => 'pi_privacy_1',
                'expires_at' => 1794003600,
                'metadata' => (object) [
                    'booking_id' => '900001',
                    'payment_id' => '900002',
                    'checkout_attempt_id' => 'attempt-privacy',
                    'customer_email' => 'sensitive.checkout@example.test',
                ],
                'customer_details' => (object) [
                    'email' => 'sensitive.checkout@example.test',
                    'name' => 'Checkout Customer Secret',
                    'phone' => '+15555550777',
                ],
            ]],
        ];
        $checkoutResponse = app(StripeWebhookService::class)->processEvent(
            $checkoutEvent,
            json_decode(json_encode($checkoutEvent), true),
        );
        $checkoutLog = WebhookLog::query()->where('event_id', $checkoutEvent->id)->firstOrFail();

        $this->assertSame(422, $checkoutResponse->getStatusCode(), 'No local payment fixture should match this synthetic event.');
        $this->assertSame('cs_test_privacy_1', $checkoutLog->payload['data']['object']['id']);
        $this->assertSame('pi_privacy_1', $checkoutLog->payload['data']['object']['payment_intent']);
        $this->assertSame('900001', $checkoutLog->payload['data']['object']['metadata']['booking_id']);
        $this->assertPersonalDataAbsent($checkoutLog->payload, [
            'sensitive.checkout@example.test',
            'Checkout Customer Secret',
            '+15555550777',
        ]);

        $refundEvent = (object) [
            'id' => 'evt_privacy_refund_1',
            'type' => 'refund.updated',
            'created' => 1794000002,
            'livemode' => false,
            'data' => (object) ['object' => (object) [
                'id' => 're_privacy_1',
                'object' => 'refund',
                'status' => 'succeeded',
                'payment_intent' => 'pi_privacy_1',
                'amount' => 3250,
                'currency' => 'usd',
                'reason' => 'requested_by_customer',
                'failure_reason' => null,
                'metadata' => (object) [
                    'refund_operation_id' => '900003',
                    'customer_email' => 'sensitive.refund@example.test',
                ],
                'customer_email' => 'sensitive.refund@example.test',
                'billing_details' => (object) ['name' => 'Refund Customer Secret'],
            ]],
        ];
        $refundResponse = app(StripeWebhookService::class)->processEvent(
            $refundEvent,
            json_decode(json_encode($refundEvent), true),
        );
        $refundLog = WebhookLog::query()->where('event_id', $refundEvent->id)->firstOrFail();

        $this->assertSame(200, $refundResponse->getStatusCode(), 'Unknown local refund operations are acknowledged for later reconciliation.');
        $this->assertSame('re_privacy_1', $refundLog->payload['data']['object']['id']);
        $this->assertSame('pi_privacy_1', $refundLog->payload['data']['object']['payment_intent']);
        $this->assertSame('900003', $refundLog->payload['data']['object']['metadata']['refund_operation_id']);
        $this->assertPersonalDataAbsent($refundLog->payload, [
            'sensitive.refund@example.test',
            'Refund Customer Secret',
        ]);
    }

    private function assertPersonalDataAbsent(array $payload, array $values): void
    {
        $encoded = json_encode($payload);
        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $encoded);
        }
    }

    public function test_webhook_pruning_is_a_dry_run_by_default_and_keeps_unprocessed_records(): void
    {
        $oldProcessed = WebhookLog::query()->create([
            'provider' => 'stripe',
            'event_id' => 'evt_old_processed',
            'event_type' => 'payment_intent.succeeded',
            'payload' => ['id' => 'evt_old_processed'],
            'processed_at' => now()->subDays(120),
            'attempts' => 0,
        ]);
        $oldUnprocessed = WebhookLog::query()->create([
            'provider' => 'stripe',
            'event_id' => 'evt_old_unprocessed',
            'event_type' => 'checkout.session.completed',
            'payload' => ['id' => 'evt_old_unprocessed'],
            'processed_at' => null,
            'attempts' => 3,
        ]);
        $recentProcessed = WebhookLog::query()->create([
            'provider' => 'stripe',
            'event_id' => 'evt_recent_processed',
            'event_type' => 'refund.updated',
            'payload' => ['id' => 'evt_recent_processed'],
            'processed_at' => now()->subDays(10),
            'attempts' => 0,
        ]);

        $this->artisan('webhooks:prune', ['--days' => 90])
            ->expectsOutputToContain('Dry run: 1 processed webhook log(s)')
            ->assertExitCode(0);

        $this->assertDatabaseHas('webhook_logs', ['id' => $oldProcessed->id]);
        $this->assertDatabaseHas('webhook_logs', ['id' => $oldUnprocessed->id]);
        $this->assertDatabaseHas('webhook_logs', ['id' => $recentProcessed->id]);

        $this->artisan('webhooks:prune', ['--days' => 90, '--batch' => 1, '--execute' => true])
            ->expectsOutputToContain('Deleted 1 processed webhook log(s)')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('webhook_logs', ['id' => $oldProcessed->id]);
        $this->assertDatabaseHas('webhook_logs', ['id' => $oldUnprocessed->id]);
        $this->assertDatabaseHas('webhook_logs', ['id' => $recentProcessed->id]);
    }
}
