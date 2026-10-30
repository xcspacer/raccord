<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ExpireLegacyPasswords extends Command
{
    protected $signature = 'users:expire-legacy-passwords';

    protected $description = 'Start the expiry clock of imported legacy passwords and erase the ones never used in time';

    public function handle(): int
    {
        $started = User::withTrashed()
            ->whereNotNull('legacy_password_hash')
            ->whereNull('legacy_password_expires_at')
            ->update(['legacy_password_expires_at' => now()->addDays(User::LEGACY_PASSWORD_DAYS)]);

        $erased = User::withTrashed()
            ->whereNotNull('legacy_password_hash')
            ->where('legacy_password_expires_at', '<=', now())
            ->update(['legacy_password_hash' => null, 'legacy_password_expires_at' => null]);

        $this->info("{$started} legacy password(s) given an expiry date, {$erased} expired legacy password(s) erased.");

        return self::SUCCESS;
    }
}
