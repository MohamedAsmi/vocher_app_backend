<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewAccountCreated extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $role,
        private readonly string $organizationName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = match ($this->role) {
            'outletManager' => 'Outlet Manager',
            'bookkeeper' => 'Bookkeeper',
            default => 'Administrator',
        };

        return (new MailMessage)
            ->subject("Your {$this->organizationName} account is ready")
            ->greeting("Hello {$notifiable->name},")
            ->line("An {$role} account has been created for you in {$this->organizationName}.")
            ->line("Sign in using this email address: {$notifiable->email}")
            ->action('Open the application', config('app.url'))
            ->line('Please obtain your temporary password securely from your administrator.');
    }
}
