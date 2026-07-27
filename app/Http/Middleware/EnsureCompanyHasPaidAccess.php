<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Billing\CompanySubscriptionAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyHasPaidAccess
{
    public function __construct(
        private readonly CompanySubscriptionAccessService $accessService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if (! $actor) {
            return response()->json([
                'success' => false,
                'code' => 'unauthenticated',
                'message' => $this->isArabic()
                    ? 'يجب تسجيل الدخول للوصول إلى هذه العملية.'
                    : 'You must be signed in to access this operation.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $company = $this->accessService->resolveCompany($actor);

        if (! $company) {
            return response()->json([
                'success' => false,
                'code' => 'company_not_found',
                'message' => $this->isArabic()
                    ? 'تعذر تحديد الشركة المرتبطة بهذا الحساب.'
                    : 'The company linked to this account could not be resolved.',
            ], Response::HTTP_FORBIDDEN);
        }

        $isOwner = $actor instanceof Company;
        $access = $this->accessService->snapshot($company, $isOwner);

        if (! $access['company_approved']) {
            return response()->json([
                'success' => false,
                'code' => 'company_not_approved',
                'message' => $this->isArabic()
                    ? 'يجب اعتماد الشركة من الإدارة قبل استخدام عمليات المنصة.'
                    : 'The company must be approved before platform operations can be used.',
                'data' => $access,
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $access['has_full_access']) {
            return response()->json([
                'success' => false,
                'code' => 'subscription_required',
                'message' => $this->messageFor($access['reason'], $isOwner),
                'data' => $access,
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        // Make the normalized state available to downstream controllers.
        $request->attributes->set('company_subscription_access', $access);

        return $next($request);
    }

    private function messageFor(?string $reason, bool $isOwner): string
    {
        $arabic = $this->isArabic();

        if (! $isOwner) {
            return $arabic
                ? 'اشتراك الشركة غير فعّال حاليًا. راجع الخطط المتاحة وتواصل مع مالك الشركة لتفعيل الاشتراك.'
                : 'Your company subscription is not active. Review the available plans and contact the company owner to activate it.';
        }

        return match ($reason) {
            'no_plan' => $arabic
                ? 'لم يتم اختيار خطة اشتراك بعد. اختر خطة مناسبة لتفعيل جميع عمليات الشركة.'
                : 'No subscription plan has been selected yet. Choose a plan to unlock all company operations.',
            'subscription_expired' => $arabic
                ? 'انتهى اشتراك الشركة. جدّد الاشتراك أو اختر خطة للمتابعة.'
                : 'The company subscription has expired. Renew it or choose a plan to continue.',
            'checkout_pending' => $arabic
                ? 'تم اختيار الخطة، لكن عملية الدفع لم تكتمل بعد.'
                : 'A plan was selected, but checkout has not been completed yet.',
            'payment_required' => $arabic
                ? 'توجد مشكلة في دفع الاشتراك. حدّث بيانات الدفع لتجنب استمرار تقييد الحساب.'
                : 'The subscription payment requires attention. Update billing to restore access.',
            default => $arabic
                ? 'اشتراك الشركة غير فعّال. اختر خطة أو حدّث الاشتراك للمتابعة.'
                : 'The company subscription is inactive. Choose a plan or update the subscription to continue.',
        };
    }

    private function isArabic(): bool
    {
        return app()->getLocale() === 'ar';
    }
}
