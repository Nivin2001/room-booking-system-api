<?php

namespace App\Console\Commands;

use App\Enums\RefundOperationStatus;
use App\Models\RefundOperation;
use App\Services\RefundReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileRefundOperations extends Command
{
    protected $signature = 'refunds:reconcile {--limit=100 : Maximum number of unresolved operations to inspect}';

    protected $description = 'Reconcile unresolved Stripe refund operations without submitting new refunds';

    public function handle(RefundReconciliationService $reconciliation): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('The --limit value must be between 1 and 1000.');

            return self::INVALID;
        }

        $candidateStatuses = [
            RefundOperationStatus::Processing,
            RefundOperationStatus::Unknown,
            RefundOperationStatus::Pending,
            RefundOperationStatus::RequiresAction,
        ];

        $operations = RefundOperation::query()
            ->whereIn('status', array_map(static fn (RefundOperationStatus $status) => $status->value, $candidateStatuses))
            ->orderByRaw('COALESCE(last_checked_at, requested_at) ASC')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $resolved = 0;
        $unresolved = 0;
        $errors = 0;

        foreach ($operations as $operation) {
            try {
                $result = $reconciliation->reconcile($operation);
                if ($result->status->isTerminal()) {
                    $resolved++;
                } else {
                    $unresolved++;
                }
            } catch (Throwable $exception) {
                $errors++;
                Log::warning('Refund reconciliation pass failed for an operation.', [
                    'refund_operation_id' => $operation->getKey(),
                    'exception' => class_basename($exception),
                ]);
            }
        }

        $this->info(sprintf(
            'Refund reconciliation inspected %d operation(s): %d final, %d unresolved, %d error(s).',
            $operations->count(),
            $resolved,
            $unresolved,
            $errors,
        ));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
