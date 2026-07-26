<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;
use App\Listeners\Billing\HandleStripeWebhookReceived;
use App\Models\Company;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Cashier::useCustomerModel(Company::class);

        Event::listen(
            WebhookReceived::class,
            HandleStripeWebhookReceived::class
        );

        // ============================================================
        // 🔹 Rate Limiting
        // ============================================================

        // للمصادقة (تسجيل الدخول)
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Password-reset request: strict limits by email and IP.
        RateLimiter::for('password-reset-request', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email', 'unknown')));

            return [
                Limit::perMinute(3)->by($email . '|' . $request->ip()),
                Limit::perHour(10)->by($request->ip()),
            ];
        });

        // Password-reset submissions. Invalid tokens are deliberately limited.
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // للـ API العامة
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // للمستخدمين العاديين
        RateLimiter::for('user', function (Request $request) {
            return Limit::perMinute(100)->by($request->user()?->id ?: $request->ip());
        });

        // للشركات
        RateLimiter::for('company', function (Request $request) {
            return Limit::perMinute(200)->by($request->user()?->id ?: $request->ip());
        });

        // للأدمن
        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(200)->by($request->user()?->id ?: $request->ip());
        });

        // لرفع الملفات
        RateLimiter::for('upload', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // ============================================================
        // 🔹 NEW: Interview-specific Rate Limiters
        // ============================================================

        // بدء مقابلة جديدة: 5 طلبات في الساعة
        RateLimiter::for('start-interview', function (Request $request) {
            return Limit::perHour(5)->by($request->user()?->id ?: $request->ip());
        });

        // إرسال إجابة: 10 طلبات في الساعة
        RateLimiter::for('submit-answer', function (Request $request) {
            return Limit::perHour(10)->by($request->user()?->id ?: $request->ip());
        });

        // فحص التقرير: 30 طلب في الدقيقة
        RateLimiter::for('check-report', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // تسجيل مخالفات الغش: 20 طلب في الدقيقة
        RateLimiter::for('anti-cheat', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // استئناف المقابلة: 10 طلبات في الساعة
        RateLimiter::for('resume-interview', function (Request $request) {
            return Limit::perHour(10)->by($request->user()?->id ?: $request->ip());
        });

        // التحقق من الجلسة: 60 طلب في الدقيقة
        RateLimiter::for('session-status', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // إنهاء المقابلة: 5 طلبات في الساعة
        RateLimiter::for('complete-interview', function (Request $request) {
            return Limit::perHour(5)->by($request->user()?->id ?: $request->ip());
        });

        // الحصول على التقرير النهائي: 10 طلبات في الدقيقة
        RateLimiter::for('get-report', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // قفل/فتح المقابلة: 20 طلب في الدقيقة
        RateLimiter::for('interview-lock', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // تحديث القفل (Keep Alive): 60 طلب في الدقيقة (مسموح أكثر)
        RateLimiter::for('refresh-lock', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // المقابلات العامة (للمرشحين): 30 طلب في الدقيقة
        RateLimiter::for('public-interview', function (Request $request) {
            return Limit::perMinute(50)->by($request->ip());
        });
    }
}
