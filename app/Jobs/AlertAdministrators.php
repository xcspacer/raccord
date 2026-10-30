<?php

namespace App\Jobs;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\AdministratorAlert;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a security alert to every administrator still in the company who has an e-mail address, each in their
 * own language; one address refused by the mail server does not keep the others from being told.
 */
class AlertAdministrators
{
    use Queueable;

    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(public string $event, public array $replace = []) {}

    public function handle(): void
    {
        $administrators = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', Role::Administrator->value))
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where(fn ($query) => $query->whereNull('departure_date')->orWhere('departure_date', '>', now()->toDateString()))
            ->get();

        foreach ($administrators as $administrator) {
            try {
                $administrator->notify((new AdministratorAlert($this->event, $this->replace))->locale($administrator->locale ?: config('app.locale')));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
