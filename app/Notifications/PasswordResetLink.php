<?php

namespace App\Notifications;

use App\Models\User;
use App\Settings\CompanySettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The « forgot password » e-mail, in the person's language, with a link valid for the broker's expiry. It is queued,
 * so the answer to the request takes the same time whether the e-mail exists or not. The sender is the one set on
 * the platform settings page, otherwise the installation's default (MAIL_FROM_*).
 */
class PasswordResetLink extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $company = app(CompanySettings::class);
        $platform = $company->displayName();
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        $message = (new MailMessage)
            ->subject(__('password_reset.mail.subject', ['platform' => $platform]))
            ->view(['mail.password-reset', 'mail.password-reset-text'], [
                'greeting' => __('password_reset.mail.greeting', ['name' => $notifiable->name]),
                'body' => __('password_reset.mail.body', ['platform' => $platform, 'username' => $notifiable->username]),
                'action' => __('password_reset.mail.action'),
                'url' => $this->resetUrl($notifiable),
                'expiry' => __('password_reset.mail.expiry', ['count' => $minutes]),
                'ignore' => __('password_reset.mail.ignore'),
            ]);

        if (filled($company->mail_from_address)) {
            $message->from((string) $company->mail_from_address, $company->mail_from_name ?: $platform);
        }

        return $message;
    }
}
