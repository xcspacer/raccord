<?php

namespace App\Notifications;

use App\Models\User;
use App\Settings\CompanySettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A security event told to an administrator by e-mail. The sender is the one set on the platform settings page,
 * otherwise the installation's default (MAIL_FROM_*).
 */
class AdministratorAlert extends Notification
{
    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(public string $event, public array $replace = []) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $company = app(CompanySettings::class);
        $platform = $company->displayName();
        $message = (new MailMessage)
            ->subject(__('alerts.subject', ['platform' => $platform, 'subject' => __('alerts.events.'.$this->event.'.subject')]))
            ->view(['mail.administrator-alert', 'mail.administrator-alert-text'], [
                'greeting' => __('alerts.greeting', ['name' => $notifiable->name]),
                'body' => __('alerts.events.'.$this->event.'.body', $this->replace),
                'hint' => __('alerts.security_log'),
                'reason' => __('alerts.reason', ['platform' => $platform]),
            ]);

        if (filled($company->mail_from_address)) {
            $message->from((string) $company->mail_from_address, $company->mail_from_name ?: $platform);
        }

        return $message;
    }
}
