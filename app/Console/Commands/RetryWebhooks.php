<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

class RetryWebhooks extends Command
{
    protected $signature = 'webhooks:retry';

    protected $description = 'Retry failed webhook events from the logs';

    public function handle()
    {
        $this->info('Searching for failed webhook logs...');

        $failed = WebhookLog::whereNull('processed_at')
            ->where('attempts', '>', 0)
            ->orderBy('attempts', 'asc')
            ->limit(50)
            ->get();

        foreach ($failed as $log) {
            try {
                // In a real system we'd re-dispatch to the same handler
                // For now we mark as attempted and leave for human review
                Log::warning('Retry placeholder for webhook', ['event_id' => $log->event_id]);

                // We could queue a job to reprocess the raw payload
                $log->increment('attempts');
            } catch (\Throwable $e) {
                Log::error('Retry failed', ['event_id' => $log->event_id, 'error' => $e->getMessage()]);
                $log->increment('attempts');
                $log->update(['last_error' => substr($e->getMessage(), 0, 1000)]);
            }
        }

        $this->info('Retry pass complete.');

        return 0;
    }
}
