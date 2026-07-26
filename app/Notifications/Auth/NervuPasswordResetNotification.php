<?php

namespace App\Notifications\Auth;

use App\Models\Admin;
use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NervuPasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $accountType,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable instanceof User
            ? substr((string) ($notifiable->locale ?? 'en'), 0, 2)
            : 'en';

        $broker = match ($this->accountType) {
            'company' => 'companies',
            'admin' => 'admins',
            default => 'users',
        };

        $expiresIn = (int) config("auth.passwords.{$broker}.expire", 60);
        $frontend = rtrim((string) config('interview_ai.frontend_url', 'http://localhost:5173'), '/');
        $url = $frontend . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
            'type' => $this->accountType,
        ]);

        if ($locale === 'ar') {
            return (new MailMessage)
                ->subject('إعادة تعيين كلمة المرور | Nervu.AI')
                ->greeting('مرحبًا،')
                ->line('تلقينا طلبًا لإعادة تعيين كلمة المرور الخاصة بحسابك في Nervu.AI.')
                ->action('إعادة تعيين كلمة المرور', $url)
                ->line("ستنتهي صلاحية هذا الرابط خلال {$expiresIn} دقيقة.")
                ->line('إذا لم تطلب إعادة تعيين كلمة المرور، فلا يلزم اتخاذ أي إجراء.');
        }

        return (new MailMessage)
            ->subject('Reset your Nervu.AI password')
            ->greeting('Hello,')
            ->line('We received a request to reset the password for your Nervu.AI account.')
            ->action('Reset password', $url)
            ->line("This password reset link expires in {$expiresIn} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }

    public static function accountTypeFor(object $notifiable): string
    {
        return match (true) {
            $notifiable instanceof Company => 'company',
            $notifiable instanceof Admin => 'admin',
            $notifiable instanceof User && $notifiable->isCompanyEmployee() => 'company_employee',
            $notifiable instanceof User && $notifiable->isCandidate() => 'candidate',
            default => 'user',
        };
    }
}
