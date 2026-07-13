<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\WebhookLog;

class WebhookRetryTest extends TestCase
{
    public function test_retry_command_runs()
    {
        // Basic smoke test: create a failed log and run the artisan command
        $log = WebhookLog::create([
            'provider' => 'stripe',
            'event_id' => 'test-event-1',
            'event_type' => 'charge.refunded',
            'payload' => [],
            'attempts' => 1,
        ]);

        $this->artisan('webhooks:retry')->assertExitCode(0);

        $this->assertDatabaseHas('webhook_logs', [
            'event_id' => 'test-event-1',
        ]);
    }
}
