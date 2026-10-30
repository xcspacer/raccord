<?php

namespace App\Jobs;

use App\Ai\ReceiptReader;
use App\Enums\ExpenseLineStatus;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a freshly photographed ticket, after the person has been answered.
 */
class ReadReceipt
{
    use Queueable;

    public function __construct(public int $userId, public int $lineId) {}

    public function handle(ReceiptReader $reader): void
    {
        $user = User::query()->find($this->userId);
        $line = ExpenseLine::query()->with('file')->find($this->lineId);

        if ($user !== null && $line !== null && $line->user_id === $user->id && $line->status === ExpenseLineStatus::Reading) {
            $reader->read($user, $line);
        }
    }
}
