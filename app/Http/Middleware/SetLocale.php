<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function resolveLocale(Request $request): string
    {
        $locale = $request->input('locale')
            ?: $request->header('Accept-Language')
            ?: optional($request->user())->locale
            ?: config('app.locale', 'en');

        $locale = strtolower(substr((string) $locale, 0, 2));

        return in_array($locale, ['en', 'ar'], true) ? $locale : 'en';
    }
}
