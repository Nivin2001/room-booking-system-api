<?php

namespace App\Enums;

enum RefundOperationStatus: string
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Unknown = 'unknown';
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public static function fromStripe(mixed $status): self
    {
        return match ($status) {
            'succeeded' => self::Succeeded,
            'pending' => self::Pending,
            'requires_action' => self::RequiresAction,
            'failed' => self::Failed,
            'canceled' => self::Canceled,
            default => self::Unknown,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Canceled], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Requested => [self::Processing, self::Failed, self::Canceled],
            self::Processing => [self::Unknown, self::Pending, self::RequiresAction, self::Succeeded, self::Failed, self::Canceled],
            self::Unknown => [self::Pending, self::RequiresAction, self::Succeeded, self::Failed, self::Canceled],
            self::Pending => [self::Unknown, self::RequiresAction, self::Succeeded, self::Failed, self::Canceled],
            self::RequiresAction => [self::Processing, self::Pending, self::Unknown, self::Succeeded, self::Failed, self::Canceled],
            self::Succeeded, self::Failed, self::Canceled => [],
        }, true);
    }
}
