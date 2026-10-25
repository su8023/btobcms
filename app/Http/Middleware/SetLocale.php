<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next, string $locale): mixed
    {
        if (! in_array($locale, config('site.locales'), true)) {
            abort(400, 'Unsupported locale.');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
