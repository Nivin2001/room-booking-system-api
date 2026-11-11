<?php

namespace App\Console\Commands;

use App\Models\WebhookLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneWebhookLogs extends Command
{
    protected $signature = 'webhooks:prune
        {--days=90 : Retention period for processed webhook logs}
        {--batch=500 : Maximum records to remove per batch}
        {--execute : Perform deletion; without this option the command only reports the candidate count}';

    protected $description = 'Safely prune only processed webhook logs older than the retention period';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);

        if ($days === false || $days < 30 || $days > 3650) {
            $this->error('The --days value must be between 30 and 3650.');

            return self::INVALID;
        }

        if ($batch === false || $batch < 1 || $batch > 5000) {
            $this->error('The --batch value must be between 1 and 5000.');

            return self::INVALID;
        }

        $cutoff = now()->subDays($days);
        $query = fn () => WebhookLog::query()
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', $cutoff);

        if (! $this->option('execute')) {
            $this->info(sprintf(
                'Dry run: %d processed webhook log(s) are older than %s. No records were deleted.',
                $query()->count(),
                $cutoff->toDateTimeString(),
            ));

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $ids = $query()->orderBy('id')->limit($batch)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            $deleted += WebhookLog::query()
                ->whereIn('id', $ids)
                ->whereNotNull('processed_at')
                ->where('processed_at', '<', $cutoff)
                ->delete();
        } while ($ids->count() === $batch);

        Log::notice('Processed webhook logs pruned under the configured retention policy.', [
            'deleted_count' => $deleted,
            'retention_days' => $days,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        $this->info(sprintf('Deleted %d processed webhook log(s) older than %s.', $deleted, $cutoff->toDateTimeString()));

        return self::SUCCESS;
    }
}
